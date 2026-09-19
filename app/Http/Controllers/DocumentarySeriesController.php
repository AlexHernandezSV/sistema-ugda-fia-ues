<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Services\UserActivityLogger;
use App\Support\XlsxRowsReader;
use App\Support\XlsxWorkbookWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DocumentarySeriesController extends Controller
{
    public function index()
    {
        $series = DocumentarySeries::query()
            ->with('units:id,code,name')
            ->with(['subseries' => function ($query) {
                $query->with('units:id,code,name')->orderBy('code');
            }])
            ->orderBy('code')
            ->get();

        return response()->json(
            $series->map(fn (DocumentarySeries $item) => $this->transformSeries($item))->values()
        );
    }

    public function downloadImportTemplate()
    {
        $series = DocumentarySeries::query()
            ->with([
                'units:id,name',
                'subseries' => fn ($query) => $query->orderBy('code'),
                'subseries.units:id,name',
            ])
            ->orderBy('code')
            ->get()
            ->sortBy(fn (DocumentarySeries $item) => $this->catalogCodeSortKey($item->code))
            ->values();

        $seriesRows = $series
            ->map(fn (DocumentarySeries $item) => [$item->code, $item->name])
            ->all();
        $subseriesRows = $series
            ->flatMap(fn (DocumentarySeries $item) => $item->subseries
                ->sortBy(fn (DocumentarySubseries $subseries) => $this->catalogCodeSortKey($subseries->code))
                ->map(fn (DocumentarySubseries $subseries) => [
                    $item->code,
                    $subseries->code,
                    $subseries->name,
                ]))
            ->values()
            ->all();
        $seriesUnitRows = $series
            ->flatMap(fn (DocumentarySeries $item) => $item->units
                ->map(fn (Unit $unit) => [$item->code, $unit->name]))
            ->values()
            ->all();
        $subseriesUnitRows = $series
            ->flatMap(fn (DocumentarySeries $item) => $item->subseries
                ->sortBy(fn (DocumentarySubseries $subseries) => $this->catalogCodeSortKey($subseries->code))
                ->flatMap(fn (DocumentarySubseries $subseries) => $subseries->units
                    ->map(fn (Unit $unit) => [
                        $item->code,
                        $subseries->code,
                        $unit->name,
                    ])))
            ->values()
            ->all();
        $path = tempnam(sys_get_temp_dir(), 'series-template-');

        if ($path === false) {
            abort(500, 'No se pudo preparar la plantilla de series documentales.');
        }

        XlsxWorkbookWriter::write($path, [
            [
                'name' => 'Series',
                'headers' => ['Código de Serie', 'Nombre de Serie'],
                'rows' => $seriesRows,
            ],
            [
                'name' => 'Sub series',
                'headers' => ['Código de Serie', 'Código de Subserie', 'Nombre de Subserie'],
                'rows' => $subseriesRows,
            ],
            [
                'name' => 'Unidades series',
                'headers' => ['Código de Serie', 'Unidad productora'],
                'rows' => $seriesUnitRows,
            ],
            [
                'name' => 'Unidades sub series',
                'headers' => ['Código de Serie', 'Código de Subserie', 'Unidad productora'],
                'rows' => $subseriesUnitRows,
            ],
        ]);

        return response()
            ->download(
                $path,
                'Series y subseries.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            )
            ->deleteFileAfterSend(true);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ], [
            'file.required' => 'Seleccione un archivo Excel.',
            'file.file' => 'El archivo seleccionado no es válido.',
            'file.mimes' => 'El archivo debe tener formato .xlsx.',
            'file.max' => 'El archivo no puede superar los 5 MB.',
        ]);

        try {
            $workbook = XlsxRowsReader::readWorkbook($request->file('file')->getRealPath());
            $records = $this->parseImportWorkbook($workbook);
            $this->validateImportRecords($records);
            $result = DB::transaction(fn (): array => $this->syncImportRecords($records));

            return response()->json([
                'message' => sprintf(
                    'Carga completada correctamente. Se procesaron %d series y %d subseries.',
                    $result['series_count'],
                    $result['subseries_count']
                ),
                ...$result,
            ]);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo procesar el archivo de series y subseries documentales.',
            ], 422);
        }
    }

    public function store(Request $request)
    {
        $this->normalizeCatalogPayload($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_series', 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at')),
                function ($attribute, $value, $fail) {
                    if ($this->deletedSeriesCodeExists($value)) {
                        $fail('El código de la serie pertenece a una serie eliminada y no puede reutilizarse.');
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                function ($attribute, $value, $fail) {
                    if ($this->seriesNameExists($value)) {
                        $fail('No se pudo guardar ya que existe otra serie documental con ese nombre.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', 'exists:units,id'],
        ], [
            'code.required' => 'El código de la serie es obligatorio.',
            'code.max' => 'El código de la serie no puede exceder los 20 dígitos.',
            'code.regex' => 'El código de la serie solo puede contener dígitos.',
            'code.unique' => 'Ya existe una serie documental registrada con este código.',
            'name.required' => 'El nombre de la serie es obligatorio.',
            'name.max' => 'El nombre de la serie no puede exceder los 150 caracteres.',
            'unit_ids.*.exists' => 'Una de las unidades productoras seleccionadas no existe.',
        ]);

        $series = DocumentarySeries::create([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $series->units()->sync($validated['unit_ids'] ?? []);

        return response()->json(['message' => 'Serie documental creada con exito']);
    }

    public function update(Request $request, $id)
    {
        $series = DocumentarySeries::findOrFail($id);
        $this->normalizeCatalogPayload($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_series', 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($series->id),
                function ($attribute, $value, $fail) use ($series) {
                    if ($this->deletedSeriesCodeExists($value, $series->id)) {
                        $fail('El código de la serie pertenece a una serie eliminada y no puede reutilizarse.');
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                function ($attribute, $value, $fail) use ($series) {
                    if ($this->seriesNameExists($value, $series->id)) {
                        $fail('No se pudo guardar ya que existe otra serie documental con ese nombre.');
                    }
                },
            ],
            'is_active' => ['required', 'boolean'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', 'exists:units,id'],
        ], [
            'code.required' => 'El código de la serie es obligatorio.',
            'code.max' => 'El código de la serie no puede exceder los 20 dígitos.',
            'code.regex' => 'El código de la serie solo puede contener dígitos.',
            'code.unique' => 'Ya existe una serie documental registrada con este código.',
            'name.required' => 'El nombre de la serie es obligatorio.',
            'name.max' => 'El nombre de la serie no puede exceder los 150 caracteres.',
            'unit_ids.*.exists' => 'Una de las unidades productoras seleccionadas no existe.',
        ]);

        $selectedUnitIds = $validated['unit_ids'] ?? [];
        $removedUnitIds = $series->units()
            ->pluck('units.id')
            ->map(fn ($unitId) => (int) $unitId)
            ->diff(collect($selectedUnitIds)->map(fn ($unitId) => (int) $unitId))
            ->values();

        if ($this->documentaryLabelsAreAssociatedWithAnyUnit(
            $this->seriesDocumentaryLabels($series),
            $removedUnitIds->all()
        )) {
            return response()->json([
                'message' => 'No se puede quitar una unidad productora porque existen documentos de la serie o sus subseries asociados a esa unidad.',
            ], 422);
        }

        $series->update([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'],
        ]);
        $series->units()->sync($selectedUnitIds);
        $series->subseries()
            ->with('units:id')
            ->get()
            ->each(function (DocumentarySubseries $subseries) use ($selectedUnitIds) {
                $subseries->units()->sync(
                    $subseries->units
                        ->pluck('id')
                        ->intersect($selectedUnitIds)
                        ->values()
                        ->all()
                );
            });

        return response()->json(['message' => 'Serie documental actualizada']);
    }

    public function checkSeriesUnitRemoval(Request $request, $id): JsonResponse
    {
        $series = DocumentarySeries::findOrFail($id);
        $validated = $request->validate([
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'exists:units,id'],
        ], [
            'unit_ids.required' => 'Seleccione las unidades que desea retirar.',
            'unit_ids.min' => 'Seleccione al menos una unidad para validar.',
            'unit_ids.*.exists' => 'Una de las unidades productoras seleccionadas no existe.',
        ]);

        if ($this->documentaryLabelsAreAssociatedWithAnyUnit(
            $this->seriesDocumentaryLabels($series),
            array_map('intval', $validated['unit_ids'])
        )) {
            return response()->json([
                'message' => 'No se puede quitar la unidad productora porque existen documentos de la serie o sus subseries asociados a esa unidad.',
            ], 422);
        }

        return response()->json([
            'message' => 'No existen documentos asociados a las unidades retiradas.',
        ]);
    }

    public function checkSeriesDeletion(Request $request, $id): JsonResponse
    {
        $series = DocumentarySeries::findOrFail($id);

        if ($this->seriesHasSubseries($series)) {
            return response()->json([
                'message' => 'No se puede eliminar la serie documental porque tiene subseries asociadas. Elimine primero sus subseries.',
            ], 422);
        }

        if ($series->units()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la serie documental porque tiene unidades productoras asociadas. Desvincule primero las unidades productoras.',
            ], 422);
        }

        if ($this->documentaryLabelsAreAssociated($this->seriesDocumentaryLabels($series))) {
            return response()->json([
                'message' => 'No se puede eliminar la serie documental porque está asociada a documentos. Desclasifique o reasigne primero los documentos asociados.',
            ], 422);
        }

        return response()->json([
            'message' => 'La serie documental puede eliminarse.',
        ]);
    }

    public function destroy(Request $request, $id, UserActivityLogger $activityLogger)
    {
        $series = DocumentarySeries::findOrFail($id);

        if ($this->seriesHasSubseries($series)) {
            return response()->json([
                'message' => 'No se puede eliminar la serie documental porque tiene subseries asociadas. Elimine primero sus subseries.',
            ], 422);
        }

        if ($series->units()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la serie documental porque tiene unidades productoras asociadas. Desvincule primero las unidades productoras.',
            ], 422);
        }

        if ($this->documentaryLabelsAreAssociated([
            $this->seriesDocumentaryLabel($series),
            ...$series->subseries()->withTrashed()->get()->map(
                fn (DocumentarySubseries $subseries) => $this->subseriesDocumentaryLabel($series, $subseries)
            )->all(),
        ])) {
            return response()->json([
                'message' => 'No se puede eliminar la serie documental porque está asociada a documentos. Desclasifique o reasigne primero los documentos asociados.',
            ], 422);
        }

        $deletionResult = DB::transaction(function () use ($activityLogger, $request, $id) {
            $series = DocumentarySeries::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($this->seriesHasSubseries($series)) {
                return [
                    'blocked' => 'No se puede eliminar la serie documental porque tiene subseries asociadas. Elimine primero sus subseries.',
                ];
            }

            if ($series->units()->exists()) {
                return [
                    'blocked' => 'No se puede eliminar la serie documental porque tiene unidades productoras asociadas. Desvincule primero las unidades productoras.',
                ];
            }

            $labels = [
                $this->seriesDocumentaryLabel($series),
                ...$series->subseries()->withTrashed()->get()->map(
                    fn (DocumentarySubseries $subseries) => $this->subseriesDocumentaryLabel($series, $subseries)
                )->all(),
            ];

            if ($this->documentaryLabelsAreAssociated($labels)) {
                return [
                    'blocked' => 'No se puede eliminar la serie documental porque está asociada a documentos. Desclasifique o reasigne primero los documentos asociados.',
                ];
            }

            $log = $activityLogger->log(
                $request->user(),
                $request->user(),
                'documentary_series_deleted',
                'Eliminacion de serie documental',
                'Serie: ' . $series->code . ' - ' . $series->name . '.',
                $request,
            );

            // Las dependencias ya fueron comprobadas antes y nuevamente dentro
            // de la transacción; la acción de eliminar es un borrado físico.
            $series->forceDelete();

            return ['log' => $log];
        });

        if (isset($deletionResult['blocked'])) {
            return response()->json([
                'message' => $deletionResult['blocked'],
            ], 422);
        }

        $activityLog = $deletionResult['log'];

        return response()->json([
            'message' => 'Serie documental eliminada',
            'event_id' => $activityLog->id,
        ]);
    }

    public function storeSubseries(Request $request, $seriesId)
    {
        $series = DocumentarySeries::with('units:id')->findOrFail($seriesId);
        $allowedUnitIds = $series->units->pluck('id')->all();
        $this->normalizeCatalogPayload($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_subseries', 'code')
                    ->where(fn ($query) => $query->where('documentary_series_id', $series->id)->whereNull('deleted_at')),
                function ($attribute, $value, $fail) use ($series) {
                    if ($this->deletedSubseriesCodeExists($series->id, $value)) {
                        $fail('El código de la subserie pertenece a una subserie eliminada y no puede reutilizarse.');
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                function ($attribute, $value, $fail) use ($series) {
                    if ($this->subseriesNameExists($series->id, $value)) {
                        $fail('No se pudo guardar ya que existe otra subserie documental con ese nombre dentro de la misma serie.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', Rule::in($allowedUnitIds)],
        ], [
            'code.required' => 'El código de la subserie es obligatorio.',
            'code.max' => 'El código de la subserie no puede exceder los 20 dígitos.',
            'code.regex' => 'El código de la subserie solo puede contener dígitos.',
            'code.unique' => 'Ya existe una subserie registrada con este código dentro de la serie seleccionada.',
            'name.required' => 'El nombre de la subserie es obligatorio.',
            'name.max' => 'El nombre de la subserie no puede exceder los 150 caracteres.',
            'unit_ids.*.in' => 'Las unidades de la subserie deben pertenecer a las unidades asignadas a la serie.',
        ]);

        $subseries = DocumentarySubseries::create([
            'documentary_series_id' => $series->id,
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $subseries->units()->sync($validated['unit_ids'] ?? []);

        return response()->json(['message' => 'Subserie documental creada con exito']);
    }

    public function updateSubseries(Request $request, $id)
    {
        $subseries = DocumentarySubseries::with('series.units:id')->findOrFail($id);
        $allowedUnitIds = $subseries->series?->units?->pluck('id')->all() ?? [];
        $this->normalizeCatalogPayload($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_subseries', 'code')
                    ->where(fn ($query) => $query->where('documentary_series_id', $subseries->documentary_series_id)->whereNull('deleted_at'))
                    ->ignore($subseries->id),
                function ($attribute, $value, $fail) use ($subseries) {
                    if ($this->deletedSubseriesCodeExists(
                        $subseries->documentary_series_id,
                        $value,
                        $subseries->id
                    )) {
                        $fail('El código de la subserie pertenece a una subserie eliminada y no puede reutilizarse.');
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                function ($attribute, $value, $fail) use ($subseries) {
                    if ($this->subseriesNameExists($subseries->documentary_series_id, $value, $subseries->id)) {
                        $fail('No se pudo guardar ya que existe otra subserie documental con ese nombre dentro de la misma serie.');
                    }
                },
            ],
            'is_active' => ['required', 'boolean'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', Rule::in($allowedUnitIds)],
        ], [
            'code.required' => 'El código de la subserie es obligatorio.',
            'code.max' => 'El código de la subserie no puede exceder los 20 dígitos.',
            'code.regex' => 'El código de la subserie solo puede contener dígitos.',
            'code.unique' => 'Ya existe una subserie registrada con este código dentro de la serie seleccionada.',
            'name.required' => 'El nombre de la subserie es obligatorio.',
            'name.max' => 'El nombre de la subserie no puede exceder los 150 caracteres.',
            'unit_ids.*.in' => 'Las unidades de la subserie deben pertenecer a las unidades asignadas a la serie.',
        ]);

        $selectedUnitIds = $validated['unit_ids'] ?? [];
        $removedUnitIds = $subseries->units()
            ->pluck('units.id')
            ->map(fn ($unitId) => (int) $unitId)
            ->diff(collect($selectedUnitIds)->map(fn ($unitId) => (int) $unitId))
            ->values();

        if ($this->documentaryLabelsAreAssociatedWithAnyUnit(
            [$this->subseriesDocumentaryLabel($subseries->series, $subseries)],
            $removedUnitIds->all()
        )) {
            return response()->json([
                'message' => 'No se puede quitar una unidad productora porque existen documentos de la subserie asociados a esa unidad.',
            ], 422);
        }

        $subseries->update([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'],
        ]);
        $subseries->units()->sync($selectedUnitIds);

        return response()->json(['message' => 'Subserie documental actualizada']);
    }

    public function checkSubseriesUnitRemoval(Request $request, $id): JsonResponse
    {
        $subseries = DocumentarySubseries::with('series')->findOrFail($id);
        $validated = $request->validate([
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'exists:units,id'],
        ], [
            'unit_ids.required' => 'Seleccione las unidades que desea retirar.',
            'unit_ids.min' => 'Seleccione al menos una unidad para validar.',
            'unit_ids.*.exists' => 'Una de las unidades productoras seleccionadas no existe.',
        ]);

        if ($this->documentaryLabelsAreAssociatedWithAnyUnit(
            [$this->subseriesDocumentaryLabel($subseries->series, $subseries)],
            array_map('intval', $validated['unit_ids'])
        )) {
            return response()->json([
                'message' => 'No se puede quitar la unidad productora porque existen documentos de la subserie asociados a esa unidad.',
            ], 422);
        }

        return response()->json([
            'message' => 'No existen documentos asociados a las unidades retiradas.',
        ]);
    }

    public function checkSubseriesDeletion(Request $request, $id): JsonResponse
    {
        $subseries = DocumentarySubseries::with('series')->findOrFail($id);

        if ($subseries->units()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la subserie documental porque tiene unidades productoras asociadas. Desvincule primero las unidades productoras.',
            ], 422);
        }

        if ($this->documentaryLabelsAreAssociated([
            $this->subseriesDocumentaryLabel($subseries->series, $subseries),
        ])) {
            return response()->json([
                'message' => 'No se puede eliminar la subserie documental porque está asociada a documentos. Desclasifique o reasigne primero los documentos asociados.',
            ], 422);
        }

        return response()->json([
            'message' => 'La subserie documental puede eliminarse.',
        ]);
    }

    public function destroySubseries(Request $request, $id, UserActivityLogger $activityLogger)
    {
        $subseries = DocumentarySubseries::with('series')->findOrFail($id);

        if ($subseries->units()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la subserie documental porque tiene unidades productoras asociadas. Desvincule primero las unidades productoras.',
            ], 422);
        }

        if ($this->documentaryLabelsAreAssociated([
            $this->subseriesDocumentaryLabel($subseries->series, $subseries),
        ])) {
            return response()->json([
                'message' => 'No se puede eliminar la subserie documental porque está asociada a documentos. Desclasifique o reasigne primero los documentos asociados.',
            ], 422);
        }

        $deletionResult = DB::transaction(function () use ($activityLogger, $request, $id) {
            $subseries = DocumentarySubseries::with('series')->lockForUpdate()->findOrFail($id);

            if ($subseries->units()->exists()) {
                return [
                    'blocked' => 'No se puede eliminar la subserie documental porque tiene unidades productoras asociadas. Desvincule primero las unidades productoras.',
                ];
            }

            if ($this->documentaryLabelsAreAssociated([
                $this->subseriesDocumentaryLabel($subseries->series, $subseries),
            ])) {
                return [
                    'blocked' => 'No se puede eliminar la subserie documental porque está asociada a documentos. Desclasifique o reasigne primero los documentos asociados.',
                ];
            }

            $log = $activityLogger->log(
                $request->user(),
                $request->user(),
                'documentary_subseries_deleted',
                'Eliminacion de subserie documental',
                'Subserie: ' . $subseries->code . ' - ' . $subseries->name . '.',
                $request,
            );

            // Las dependencias ya fueron comprobadas antes y nuevamente dentro
            // de la transacción; la acción de eliminar es un borrado físico.
            $subseries->forceDelete();

            return ['log' => $log];
        });

        if (isset($deletionResult['blocked'])) {
            return response()->json([
                'message' => $deletionResult['blocked'],
            ], 422);
        }

        $activityLog = $deletionResult['log'];

        return response()->json([
            'message' => 'Subserie documental eliminada',
            'event_id' => $activityLog->id,
        ]);
    }

    private function normalizeCatalogPayload(Request $request): void
    {
        $request->merge([
            'code' => is_string($request->input('code')) ? trim($request->input('code')) : $request->input('code'),
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
        ]);
    }

    private function seriesDocumentaryLabel(DocumentarySeries $series): string
    {
        return trim($series->code . ' - ' . $series->name);
    }

    private function seriesHasSubseries(DocumentarySeries $series): bool
    {
        return DocumentarySubseries::withTrashed()
            ->where('documentary_series_id', $series->id)
            ->exists();
    }

    private function subseriesDocumentaryLabel(DocumentarySeries $series, DocumentarySubseries $subseries): string
    {
        return $this->seriesDocumentaryLabel($series) . ' / ' . trim($subseries->code . ' - ' . $subseries->name);
    }

    /**
     * @return array<int, string>
     */
    private function seriesDocumentaryLabels(DocumentarySeries $series): array
    {
        return [
            $this->seriesDocumentaryLabel($series),
            ...$series->subseries()->withTrashed()->get()->map(
                fn (DocumentarySubseries $subseries) => $this->subseriesDocumentaryLabel($series, $subseries)
            )->all(),
        ];
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, int> $unitIds
     */
    private function documentaryLabelsAreAssociatedWithAnyUnit(array $labels, array $unitIds): bool
    {
        foreach ($unitIds as $unitId) {
            if ($this->documentaryLabelsAreAssociatedWithUnit($labels, (int) $unitId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $labels
     */
    private function documentaryLabelsAreAssociatedWithUnit(array $labels, int $unitId): bool
    {
        return $this->documentaryLabelsQuery($labels)
            ->whereHas('box.transfer', function ($query) use ($unitId) {
                $query->where(function ($query) use ($unitId) {
                    $query
                        ->where('transfers.unit_id', $unitId)
                        ->orWhereHas('units', fn ($unitQuery) => $unitQuery->where('units.id', $unitId));
                });
            })
            ->exists();
    }

    /**
     * La relacion del catalogo se almacena como etiquetas separadas por punto
     * y coma en transfer_box_documents; la comparacion respeta sus limites.
     *
     * @param array<int, string> $labels
     */
    private function documentaryLabelsAreAssociated(array $labels): bool
    {
        return $this->documentaryLabelsQuery($labels)->exists();
    }

    /**
     * @param array<int, string> $labels
     */
    private function documentaryLabelsQuery(array $labels)
    {
        $normalizedLabels = collect($labels)
            ->map(fn (string $label) => mb_strtolower(preg_replace('/\s+/', '', trim($label)) ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalizedLabels === []) {
            return TransferDocument::query()->whereRaw('1 = 0');
        }

        $normalizedColumn = "REPLACE(REPLACE(LOWER(COALESCE(transfer_box_documents.series_label, '')), ' ', ''), ';', '|')";

        return TransferDocument::query()
            ->where(function ($query) use ($normalizedLabels, $normalizedColumn) {
                foreach ($normalizedLabels as $index => $label) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $query->{$method}(
                        "POSITION(? IN ('|' || {$normalizedColumn} || '|')) > 0",
                        ["|{$label}|"]
                    );
                }
            });
    }

    /**
     * @param array<string, array<int, array<int, string>>> $workbook
     * @return array<string, mixed>
     */
    private function parseImportWorkbook(array $workbook): array
    {
        $requiredSheets = ['Series', 'Sub series', 'Unidades series', 'Unidades sub series'];

        foreach ($requiredSheets as $sheetName) {
            if (!array_key_exists($sheetName, $workbook)) {
                throw new \InvalidArgumentException(
                    "El archivo debe contener la hoja '{$sheetName}'."
                );
            }
        }

        $records = [
            'series' => $this->parseImportSheet(
                $workbook['Series'],
                [
                    'code' => ['codigodeserie', 'cdigodeserie', 'codigoserie'],
                    'name' => ['nombredeserie', 'nombreserie'],
                ],
                'Series'
            ),
            'subseries' => $this->parseImportSheet(
                $workbook['Sub series'],
                [
                    'series_code' => ['codigodeserie', 'cdigodeserie', 'codigoserie'],
                    'code' => ['codigodesubserie', 'cdigodesubserie', 'codigosubserie'],
                    'name' => ['nombredesubserie', 'nombresubserie'],
                ],
                'Sub series'
            ),
            'series_units' => $this->parseImportSheet(
                $workbook['Unidades series'],
                [
                    'series_code' => ['codigodeserie', 'cdigodeserie', 'codigoserie'],
                    'unit_name' => ['unidadproductora'],
                ],
                'Unidades series'
            ),
            'subseries_units' => $this->parseImportSheet(
                $workbook['Unidades sub series'],
                [
                    'series_code' => ['codigodeserie', 'cdigodeserie', 'codigoserie'],
                    'subseries_code' => ['codigodesubserie', 'cdigodesubserie', 'codigosubserie'],
                    'unit_name' => ['unidadproductora'],
                ],
                'Unidades sub series'
            ),
        ];

        if ($records['series'] === []) {
            throw new \InvalidArgumentException('La hoja Series no contiene registros.');
        }

        return $records;
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<string, array<int, string>> $columns
     * @return array<int, array<string, string|int>>
     */
    private function parseImportSheet(array $rows, array $columns, string $sheetName): array
    {
        if ($rows === []) {
            throw new \InvalidArgumentException("La hoja {$sheetName} está vacía.");
        }

        $headers = array_map(
            fn ($header) => $this->normalizeImportHeader((string) $header),
            $rows[0]
        );
        $headers = array_values($headers);
        $headerIndexes = [];

        foreach ($headers as $index => $header) {
            if ($header !== '' && !array_key_exists($header, $headerIndexes)) {
                $headerIndexes[$header] = $index;
            }
        }

        $columnIndexes = [];

        foreach ($columns as $field => $aliases) {
            $normalizedAliases = array_map(
                fn ($alias) => $this->normalizeImportHeader((string) $alias),
                $aliases
            );
            $matchedIndex = null;

            foreach ($normalizedAliases as $alias) {
                if (array_key_exists($alias, $headerIndexes)) {
                    $matchedIndex = $headerIndexes[$alias];
                    break;
                }
            }

            if ($matchedIndex === null) {
                $foundHeaders = implode(', ', array_filter($headers, fn (string $header) => $header !== ''));
                $expectedHeaders = implode(' / ', $normalizedAliases);

                throw new \InvalidArgumentException(
                    "La hoja {$sheetName} no contiene la columna requerida '{$expectedHeaders}'. Encabezados encontrados: {$foundHeaders}."
                );
            }

            $columnIndexes[$field] = $matchedIndex;
        }

        $records = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $excelRow = $index + 2;
            $values = array_map(fn ($value) => trim((string) $value), $row);

            if (collect($values)->every(fn (string $value) => $value === '')) {
                continue;
            }

            $record = ['row' => $excelRow];

            foreach (array_keys($columns) as $field) {
                $value = trim((string) ($values[$columnIndexes[$field]] ?? ''));

                if ($value === '') {
                    throw new \InvalidArgumentException(
                        "La fila {$excelRow} de la hoja {$sheetName} tiene campos obligatorios vacíos."
                    );
                }

                $record[$field] = $value;
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $records
     */
    private function validateImportRecords(array &$records): void
    {
        $seriesRecords =& $records['series'];
        $subseriesRecords =& $records['subseries'];
        $seriesUnitRecords =& $records['series_units'];
        $subseriesUnitRecords =& $records['subseries_units'];
        $existingSeries = DocumentarySeries::withTrashed()->get(['id', 'code', 'name', 'deleted_at']);
        $existingSeriesByCode = $existingSeries->keyBy('code');
        $existingSeriesByName = $existingSeries
            ->filter(fn (DocumentarySeries $series) => !$series->trashed())
            ->groupBy(fn (DocumentarySeries $series) => $this->normalizeImportText($series->name));
        $incomingSeriesByCode = [];
        $incomingSeriesNames = [];

        foreach ($seriesRecords as $index => &$record) {
            $row = $record['row'];
            $record['code'] = trim((string) $record['code']);
            $record['name'] = trim((string) $record['name']);
            $this->validateImportCode($record['code'], "El código de la serie en la fila {$row}");
            $this->validateImportName($record['name'], "El nombre de la serie en la fila {$row}");
            $nameKey = $this->normalizeImportText($record['name']);

            if (isset($incomingSeriesByCode[$record['code']])) {
                throw new \InvalidArgumentException(
                    "El código de serie '{$record['code']}' aparece más de una vez en el archivo."
                );
            }

            if (isset($incomingSeriesNames[$nameKey])) {
                throw new \InvalidArgumentException(
                    "El nombre de serie '{$record['name']}' aparece más de una vez en el archivo."
                );
            }

            $existing = $existingSeriesByCode[$record['code']] ?? null;

            if ($existing?->trashed()) {
                throw new \InvalidArgumentException(
                    "El código de serie '{$record['code']}' pertenece a un registro eliminado y no puede reutilizarse."
                );
            }

            foreach ($existingSeriesByName[$nameKey] ?? [] as $conflict) {
                if ($conflict->id !== ($existing?->id ?? null)) {
                    throw new \InvalidArgumentException(
                        "Ya existe otra serie documental con el nombre '{$record['name']}'."
                    );
                }
            }

            $record['series_id'] = $existing?->id;
            $incomingSeriesByCode[$record['code']] = $index;
            $incomingSeriesNames[$nameKey] = $row;
        }
        unset($record);

        $seriesIdsByCode = [];

        foreach ($seriesRecords as $record) {
            if ($record['series_id'] !== null) {
                $seriesIdsByCode[$record['code']] = $record['series_id'];
            }
        }

        foreach (array_merge(
            array_column($subseriesRecords, 'series_code'),
            array_column($seriesUnitRecords, 'series_code'),
            array_column($subseriesUnitRecords, 'series_code')
        ) as $seriesCode) {
            $seriesCode = trim((string) $seriesCode);
            $existing = $existingSeriesByCode[$seriesCode] ?? null;

            if (!isset($incomingSeriesByCode[$seriesCode]) && ($existing === null || $existing->trashed())) {
                throw new \InvalidArgumentException(
                    "La serie '{$seriesCode}' debe existir en la hoja Series o previamente en el sistema."
                );
            }

            if ($existing !== null && !$existing->trashed()) {
                $seriesIdsByCode[$seriesCode] = $existing->id;
            }
        }

        $existingSubseries = DocumentarySubseries::withTrashed()
            ->get(['id', 'documentary_series_id', 'code', 'name', 'deleted_at']);
        $existingSubseriesByKey = $existingSubseries->keyBy(
            fn (DocumentarySubseries $subseries) => $this->importPairKey(
                $this->seriesCodeForId($subseries->documentary_series_id, $existingSeries),
                $subseries->code
            )
        );
        $existingSubseriesByName = $existingSubseries
            ->filter(fn (DocumentarySubseries $subseries) => !$subseries->trashed())
            ->groupBy(fn (DocumentarySubseries $subseries) => $subseries->documentary_series_id . '|' . $this->normalizeImportText($subseries->name));
        $incomingSubseriesByKey = [];
        $incomingSubseriesNames = [];

        foreach ($subseriesRecords as $index => &$record) {
            $row = $record['row'];
            $record['series_code'] = trim((string) $record['series_code']);
            $record['code'] = trim((string) $record['code']);
            $record['name'] = trim((string) $record['name']);
            $this->validateImportCode($record['series_code'], "El código de serie de la fila {$row}");
            $this->validateImportCode($record['code'], "El código de subserie en la fila {$row}");
            $this->validateImportName($record['name'], "El nombre de la subserie en la fila {$row}");
            $seriesId = $seriesIdsByCode[$record['series_code']] ?? null;

            if ($seriesId === null && !isset($incomingSeriesByCode[$record['series_code']])) {
                throw new \InvalidArgumentException(
                    "La subserie de la fila {$row} referencia una serie que no existe."
                );
            }

            $key = $this->importPairKey($record['series_code'], $record['code']);
            $nameKey = $record['series_code'] . '|' . $this->normalizeImportText($record['name']);

            if (isset($incomingSubseriesByKey[$key])) {
                throw new \InvalidArgumentException(
                    "La combinación de serie '{$record['series_code']}' y subserie '{$record['code']}' aparece más de una vez."
                );
            }

            if (isset($incomingSubseriesNames[$nameKey])) {
                throw new \InvalidArgumentException(
                    "El nombre de subserie '{$record['name']}' aparece más de una vez dentro de la serie '{$record['series_code']}'."
                );
            }

            $existing = $existingSubseriesByKey[$key] ?? null;

            if ($existing?->trashed()) {
                throw new \InvalidArgumentException(
                    "La subserie '{$record['series_code']}-{$record['code']}' pertenece a un registro eliminado y no puede reutilizarse."
                );
            }

            if ($seriesId !== null) {
                foreach ($existingSubseriesByName[$seriesId . '|' . $this->normalizeImportText($record['name'])] ?? [] as $conflict) {
                    if ($conflict->id !== ($existing?->id ?? null)) {
                        throw new \InvalidArgumentException(
                            "Ya existe otra subserie documental con el nombre '{$record['name']}' dentro de la serie '{$record['series_code']}'."
                        );
                    }
                }
            }

            $record['subseries_id'] = $existing?->id;
            $record['key'] = $key;
            $incomingSubseriesByKey[$key] = $index;
            $incomingSubseriesNames[$nameKey] = $row;
        }
        unset($record);

        $unitsByName = Unit::query()
            ->get(['id', 'name'])
            ->groupBy(fn (Unit $unit) => $this->normalizeImportText($unit->name));

        foreach ($unitsByName as $nameKey => $matchingUnits) {
            if ($matchingUnits->count() > 1) {
                throw new \InvalidArgumentException(
                    "Hay más de una unidad productora con el nombre '{$matchingUnits->first()->name}'."
                );
            }
        }

        $seriesUnitIdsByCode = [];
        $seriesUnitKeys = [];

        foreach ($seriesUnitRecords as &$record) {
            $record['series_code'] = trim((string) $record['series_code']);
            $record['unit_name'] = trim((string) $record['unit_name']);
            $seriesCode = $record['series_code'];
            $this->validateImportCode(
                $seriesCode,
                "El código de serie de la hoja Unidades series en la fila {$record['row']}"
            );
            $unitKey = $this->normalizeImportText($record['unit_name']);
            $unit = $unitsByName[$unitKey][0] ?? null;
            $pairKey = $seriesCode . '|' . $unitKey;

            if ($unit === null) {
                throw new \InvalidArgumentException(
                    "La unidad productora '{$record['unit_name']}' no existe en el sistema."
                );
            }

            if (isset($seriesUnitKeys[$pairKey])) {
                throw new \InvalidArgumentException(
                    "La unidad '{$record['unit_name']}' está repetida en la serie '{$seriesCode}'."
                );
            }

            $record['unit_id'] = $unit->id;
            $seriesUnitKeys[$pairKey] = true;
            $seriesUnitIdsByCode[$seriesCode][] = $unit->id;
        }
        unset($record);

        $subseriesUnitIdsByKey = [];
        $subseriesUnitKeys = [];

        foreach ($subseriesUnitRecords as &$record) {
            $record['series_code'] = trim((string) $record['series_code']);
            $record['subseries_code'] = trim((string) $record['subseries_code']);
            $record['unit_name'] = trim((string) $record['unit_name']);
            $this->validateImportCode(
                $record['series_code'],
                "El código de serie de la hoja Unidades sub series en la fila {$record['row']}"
            );
            $this->validateImportCode(
                $record['subseries_code'],
                "El código de subserie de la hoja Unidades sub series en la fila {$record['row']}"
            );
            $subseriesKey = $this->importPairKey($record['series_code'], $record['subseries_code']);
            $unitKey = $this->normalizeImportText($record['unit_name']);
            $unit = $unitsByName[$unitKey][0] ?? null;
            $pairKey = $subseriesKey . '|' . $unitKey;

            if (!isset($incomingSubseriesByKey[$subseriesKey]) && !isset($existingSubseriesByKey[$subseriesKey])) {
                throw new \InvalidArgumentException(
                    "La subserie '{$record['series_code']}-{$record['subseries_code']}' no existe en el sistema ni en la plantilla."
                );
            }

            if ($unit === null) {
                throw new \InvalidArgumentException(
                    "La unidad productora '{$record['unit_name']}' no existe en el sistema."
                );
            }

            if (isset($subseriesUnitKeys[$pairKey])) {
                throw new \InvalidArgumentException(
                    "La unidad '{$record['unit_name']}' está repetida en la subserie '{$record['series_code']}-{$record['subseries_code']}'."
                );
            }

            $record['unit_id'] = $unit->id;
            $subseriesUnitKeys[$pairKey] = true;
            $subseriesUnitIdsByKey[$subseriesKey][] = $unit->id;
        }
        unset($record);

        $existingSeriesUnitIds = DB::table('documentary_series_unit as relation')
            ->join('documentary_series as series', 'series.id', '=', 'relation.documentary_series_id')
            ->whereNull('series.deleted_at')
            ->get(['series.code as series_code', 'relation.unit_id'])
            ->groupBy('series_code')
            ->map(fn ($items) => $items->pluck('unit_id')->map(fn ($id) => (int) $id)->values()->all())
            ->all();
        $existingSubseriesUnitIds = DB::table('documentary_subseries_unit as relation')
            ->join('documentary_subseries as subseries', 'subseries.id', '=', 'relation.documentary_subseries_id')
            ->join('documentary_series as series', 'series.id', '=', 'subseries.documentary_series_id')
            ->whereNull('subseries.deleted_at')
            ->whereNull('series.deleted_at')
            ->get(['series.code as series_code', 'subseries.code as subseries_code', 'relation.unit_id'])
            ->map(fn ($item) => [
                'key' => $this->importPairKey($item->series_code, $item->subseries_code),
                'unit_id' => (int) $item->unit_id,
            ])
            ->groupBy('key')
            ->map(fn ($items) => $items->pluck('unit_id')->values()->all())
            ->all();
        $explicitSeriesCodes = array_fill_keys(array_column($seriesRecords, 'code'), true);
        $seriesCodesToSync = array_fill_keys(array_keys($seriesUnitIdsByCode), true);
        foreach (array_keys($explicitSeriesCodes) as $seriesCode) {
            $seriesCodesToSync[$seriesCode] = true;
        }

        $finalSeriesUnitIds = $existingSeriesUnitIds;
        foreach (array_keys($seriesCodesToSync) as $seriesCode) {
            $finalSeriesUnitIds[$seriesCode] = array_values(array_unique($seriesUnitIdsByCode[$seriesCode] ?? []));
        }

        $explicitSubseriesKeys = array_fill_keys(array_column($subseriesRecords, 'key'), true);
        $subseriesKeysToSync = array_fill_keys(array_keys($subseriesUnitIdsByKey), true);
        foreach (array_keys($explicitSubseriesKeys) as $subseriesKey) {
            $subseriesKeysToSync[$subseriesKey] = true;
        }

        foreach ($subseriesUnitIdsByKey as $subseriesKey => $unitIds) {
            $subseriesSeriesCode = explode('|', $subseriesKey, 2)[0];
            $allowedUnitIds = $finalSeriesUnitIds[$subseriesSeriesCode] ?? [];

            foreach (array_unique($unitIds) as $unitId) {
                if (!in_array($unitId, $allowedUnitIds, true)) {
                    throw new \InvalidArgumentException(
                        "La unidad asignada a la subserie '{$subseriesKey}' debe estar habilitada primero en la serie '{$subseriesSeriesCode}'."
                    );
                }
            }
        }

        $records['series_unit_ids_by_code'] = $seriesUnitIdsByCode;
        $records['series_codes_to_sync'] = array_keys($seriesCodesToSync);
        $records['subseries_unit_ids_by_key'] = $subseriesUnitIdsByKey;
        $records['subseries_keys_to_sync'] = array_keys($subseriesKeysToSync);
        $records['existing_series_unit_ids'] = $existingSeriesUnitIds;
        $records['existing_subseries_unit_ids'] = $existingSubseriesUnitIds;
    }

    /**
     * @param array<string, mixed> $records
     * @return array{series_count:int, subseries_count:int, series_unit_links:int, subseries_unit_links:int}
     */
    private function syncImportRecords(array $records): array
    {
        $seriesByCode = [];
        $seriesCount = 0;

        foreach ($records['series'] as $record) {
            $series = DocumentarySeries::withTrashed()
                ->where('code', $record['code'])
                ->first();

            if ($series === null) {
                $series = DocumentarySeries::create([
                    'code' => $record['code'],
                    'name' => $record['name'],
                    'is_active' => true,
                ]);
            } else {
                $series->update(['name' => $record['name']]);
            }

            $seriesByCode[$record['code']] = $series;
            $seriesCount++;
        }

        $referencedSeriesCodes = array_unique(array_merge(
            $records['series_codes_to_sync'],
            array_column($records['subseries'], 'series_code'),
            array_column($records['subseries_units'], 'series_code')
        ));

        foreach ($referencedSeriesCodes as $seriesCode) {
            if (!isset($seriesByCode[$seriesCode])) {
                $seriesByCode[$seriesCode] = DocumentarySeries::query()
                    ->where('code', $seriesCode)
                    ->firstOrFail();
            }
        }

        $subseriesByKey = [];
        $subseriesCount = 0;

        foreach ($records['subseries'] as $record) {
            $series = $seriesByCode[$record['series_code']];
            $subseries = DocumentarySubseries::withTrashed()
                ->where('documentary_series_id', $series->id)
                ->where('code', $record['code'])
                ->first();

            if ($subseries === null) {
                $subseries = DocumentarySubseries::create([
                    'documentary_series_id' => $series->id,
                    'code' => $record['code'],
                    'name' => $record['name'],
                    'is_active' => true,
                ]);
            } else {
                $subseries->update(['name' => $record['name']]);
            }

            $subseriesByKey[$record['key']] = $subseries;
            $subseriesCount++;
        }

        foreach ($records['subseries_keys_to_sync'] as $subseriesKey) {
            if (!isset($subseriesByKey[$subseriesKey])) {
                [$seriesCode, $subseriesCode] = explode('|', $subseriesKey, 2);
                $series = $seriesByCode[$seriesCode];
                $subseriesByKey[$subseriesKey] = DocumentarySubseries::query()
                    ->where('documentary_series_id', $series->id)
                    ->where('code', $subseriesCode)
                    ->firstOrFail();
            }
        }

        $seriesUnitLinks = 0;

        foreach ($records['series_codes_to_sync'] as $seriesCode) {
            $unitIds = array_values(array_unique($records['series_unit_ids_by_code'][$seriesCode] ?? []));
            $seriesByCode[$seriesCode]->units()->sync($unitIds);
            $seriesUnitLinks += count($unitIds);
        }

        $subseriesUnitLinks = 0;

        foreach ($records['subseries_keys_to_sync'] as $subseriesKey) {
            $unitIds = array_values(array_unique($records['subseries_unit_ids_by_key'][$subseriesKey] ?? []));
            $subseriesByKey[$subseriesKey]->units()->sync($unitIds);
            $subseriesUnitLinks += count($unitIds);
        }

        return [
            'series_count' => $seriesCount,
            'subseries_count' => $subseriesCount,
            'series_unit_links' => $seriesUnitLinks,
            'subseries_unit_links' => $subseriesUnitLinks,
        ];
    }

    private function validateImportCode(string $code, string $label): void
    {
        if ($code === '' || mb_strlen($code) > 20 || preg_match('/^\d+$/', $code) !== 1) {
            throw new \InvalidArgumentException(
                "{$label} debe contener únicamente dígitos y no superar 20 caracteres."
            );
        }
    }

    private function validateImportName(string $name, string $label): void
    {
        if ($name === '' || mb_strlen($name) > 150) {
            throw new \InvalidArgumentException(
                "{$label} es obligatorio y no puede superar 150 caracteres."
            );
        }
    }

    private function normalizeImportHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim($value)))) ?? '';
    }

    private function normalizeImportText(string $value): string
    {
        $normalized = Str::ascii(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower(trim($normalized));
    }

    private function importPairKey(string $seriesCode, string $subseriesCode): string
    {
        return trim($seriesCode) . '|' . trim($subseriesCode);
    }

    private function seriesCodeForId(int $seriesId, $series): string
    {
        return (string) ($series->firstWhere('id', $seriesId)?->code ?? '');
    }

    private function catalogCodeSortKey(string $code): string
    {
        $code = ltrim(trim($code), '0');
        $code = $code === '' ? '0' : $code;

        return str_pad($code, 20, '0', STR_PAD_LEFT);
    }

    private function seriesNameExists(string $name, ?int $ignoreId = null): bool
    {
        return DocumentarySeries::query()
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    private function subseriesNameExists(int $seriesId, string $name, ?int $ignoreId = null): bool
    {
        return DocumentarySubseries::query()
            ->where('documentary_series_id', $seriesId)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    private function deletedSeriesCodeExists(string $code, ?int $ignoreId = null): bool
    {
        return DocumentarySeries::onlyTrashed()
            ->where('code', trim($code))
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function deletedSubseriesCodeExists(int $seriesId, string $code, ?int $ignoreId = null): bool
    {
        return DocumentarySubseries::onlyTrashed()
            ->where('documentary_series_id', $seriesId)
            ->where('code', trim($code))
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function transformSeries(DocumentarySeries $series): array
    {
        return [
            'id' => $series->id,
            'code' => $series->code,
            'name' => $series->name,
            'is_active' => $series->is_active,
            'subseries_count' => $series->subseries->count(),
            'unit_ids' => $series->units->pluck('id')->values()->all(),
            'units' => $series->units
                ->map(fn ($unit) => [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'name' => $unit->name,
                ])
                ->values()
                ->all(),
            'subseries' => $series->subseries
                ->map(fn (DocumentarySubseries $subseries) => [
                    'id' => $subseries->id,
                    'code' => $subseries->code,
                    'name' => $subseries->name,
                    'is_active' => $subseries->is_active,
                    'documentary_series_id' => $subseries->documentary_series_id,
                    'unit_ids' => $subseries->units->pluck('id')->values()->all(),
                    'units' => $subseries->units
                        ->map(fn ($unit) => [
                            'id' => $unit->id,
                            'code' => $unit->code,
                            'name' => $unit->name,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
