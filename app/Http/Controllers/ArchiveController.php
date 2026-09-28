<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use App\Models\LoanDocument;
use App\Models\PhysicalLocationShelf;
use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Models\User;
use App\Services\UserActivityLogger;
use App\Support\PdfUpload;
use App\Support\RequestNumberGenerator;
use App\Support\SignedPdfUrl;
use App\Support\TextSearch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ArchiveController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede gestionar el archivo general.',
            ], 403);
        }

        try {
            $nextBoxNumber = ((int) TransferBox::query()->max('box_number')) + 1;

            return response()->json([
                'next_box_number' => $nextBoxNumber,
                'next_box_code' => RequestNumberGenerator::box(now()),
                'units' => Unit::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'code', 'name'])
                    ->map(fn (Unit $unit) => [
                        'label' => $unit->name,
                        'value' => $unit->id,
                        'code' => $unit->code,
                    ])
                    ->values(),
                'series' => $this->archiveClassificationOptions(),
                'locations' => $this->locationOptions(),
                'support_types' => ['Fisico', 'Digital', 'Mixto'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el catalogo de archivo.',
            ], 500);
        }
    }

    public function boxes(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede consultar las cajas del archivo general.',
            ], 403);
        }

        try {
            $term = trim((string) $request->query('q', ''));
            $classificationId = trim((string) $request->query('classification_id', ''));

            $boxesQuery = TransferBox::query()
                ->with(['documents', 'transfer.unit', 'transfer.units', 'assignedBy.person'])
                ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'));

            if ($classificationId !== '') {
                $classification = $this->archiveClassificationOptions()
                    ->firstWhere('value', $classificationId);

                if ($classification === null) {
                    return response()->json([
                        'message' => 'La subserie documental seleccionada no esta habilitada.',
                    ], 422);
                }

                $classificationNames = collect([$classification['label'], $classification['name']])
                    ->map(fn ($name) => trim((string) $name))
                    ->filter()
                    ->unique()
                    ->values();

                $boxesQuery->where(function (Builder $query) use ($classificationNames) {
                    foreach ($classificationNames as $index => $classificationName) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $query->{$method}(function (Builder $classificationQuery) use ($classificationName) {
                            $this->whereArchiveClassificationLabelMatches(
                                $classificationQuery,
                                $classificationName,
                            );
                        });
                    }
                });
            }

            if ($term !== '') {
                $boxesQuery->where(function (Builder $query) use ($term) {
                    if (preg_match('/^C-[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+$/', $term) === 1) {
                        $query->whereRaw('LOWER(transfer_boxes.box_code) = ?', [mb_strtolower($term)])
                            ->orWhere(function (Builder $legacyQuery) use ($term) {
                                if (preg_match('/^C-(.+)-(\d+)$/i', $term, $matches) !== 1) {
                                    return;
                                }

                                $transferCode = mb_strtolower($matches[1]);
                                $boxNumber = (int) $matches[2];

                                $legacyQuery->where('transfer_boxes.box_number', $boxNumber)
                                    ->whereHas('transfer', function (Builder $transferQuery) use ($transferCode) {
                                        if ($transferCode === 'ag') {
                                            $transferQuery->where('transfers.view_mode', 'archive');

                                            return;
                                        }

                                        $transferQuery->whereRaw('LOWER(transfers.code) = ?', [$transferCode]);
                                    });
                            });

                        return;
                    }

                    if (preg_match('/^C-(.+)-(\d+)$/i', $term, $matches) === 1) {
                        $transferCode = mb_strtolower($matches[1]);
                        $boxNumber = (int) $matches[2];

                        $query->where('transfer_boxes.box_number', $boxNumber)
                            ->whereHas('transfer', function (Builder $transferQuery) use ($transferCode) {
                                if ($transferCode === 'ag') {
                                    $transferQuery->where('transfers.view_mode', 'archive');

                                    return;
                                }

                                $transferQuery->whereRaw('LOWER(transfers.code) = ?', [$transferCode]);
                            });

                        return;
                    }

                    $this->addSearchClauses($query, [
                        'transfer_boxes.box_code',
                        'transfer_boxes.series_name',
                        'transfer_boxes.title',
                        'transfer_boxes.period_label',
                        'transfer_boxes.location_code',
                        'transfer_boxes.content_description',
                    ], $term);

                    $query->orWhereHas('transfer.unit', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, ['units.code', 'units.name'], $term);
                    });

                    $query->orWhereHas('transfer.units', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, ['units.code', 'units.name'], $term);
                    });

                    $query->orWhereHas('transfer', function (Builder $transferQuery) use ($term) {
                        $this->addSearchClauses($transferQuery, ['transfers.code'], $term);
                    });

                    $query->orWhereHas('documents', function (Builder $documentQuery) use ($term) {
                        $this->addSearchClauses($documentQuery, [
                            'transfer_box_documents.code',
                            'transfer_box_documents.name',
                            'transfer_box_documents.series_label',
                        ], $term);
                    });
                });
            }

            $page = max(1, (int) $request->query('page', 1));
            $perPage = (int) $request->query('per_page', 10);
            $perPage = min(max($perPage, 5), 50);
            $boxesPaginator = $boxesQuery
                ->orderByDesc('transfer_boxes.created_at')
                ->paginate($perPage, ['*'], 'page', $page);
            $boxes = $boxesPaginator->getCollection()
                ->map(fn (TransferBox $box) => $this->mapBox($box))
                ->values();
            $meta = [
                'current_page' => $boxesPaginator->currentPage(),
                'last_page' => $boxesPaginator->lastPage(),
                'per_page' => $boxesPaginator->perPage(),
                'total' => $boxesPaginator->total(),
                'from' => $boxesPaginator->firstItem(),
                'to' => $boxesPaginator->lastItem(),
            ];

            return response()->json([
                'query' => $term,
                'count' => $boxesPaginator->total(),
                'boxes' => $boxes,
                'data' => $boxes,
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron cargar las cajas registradas.',
            ], 500);
        }
    }

    public function storeBox(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede crear cajas en el archivo general.',
            ], 403);
        }

        $request->merge([
            'unit_ids' => $this->normalizeArchiveUnitIds($request),
            'classification_ids' => collect($request->input('classification_ids', $request->input('series_ids', [])))
                ->when(
                    $request->filled('series_id'),
                    fn ($classifications) => $classifications->push('subseries:' . (int) $request->input('series_id'))
                )
                ->map(function ($classification) {
                    if (is_numeric($classification)) {
                        return 'subseries:' . (int) $classification;
                    }

                    return trim((string) $classification);
                })
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);

        $validator = Validator::make($request->all(), [
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'classification_ids' => ['required', 'array', 'min:1'],
            'classification_ids.*' => ['required', 'string', 'distinct', 'regex:/^subseries:\d+$/'],
            'start_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'end_year' => ['required', 'integer', 'min:1900', 'max:2100', 'gte:start_year'],
            'office_id' => ['required', 'integer', 'exists:physical_location_offices,id'],
            'aisle_id' => ['required', 'integer', 'exists:physical_location_aisles,id'],
            'shelf_id' => ['required', 'integer', 'exists:physical_location_shelves,id'],
            'documents' => ['nullable', 'array'],
            'documents.*.series' => ['nullable', 'string', 'max:2000'],
            'documents.*.code' => ['nullable', 'string', 'max:120'],
            'documents.*.name' => ['required_with:documents', 'string', 'max:255'],
            'documents.*.year' => ['nullable', 'string', 'max:50'],
            'documents.*.support' => ['nullable', 'string', 'max:30'],
            'documents.*.pages' => ['nullable', 'string', 'max:50'],
            'documents.*.digital_file_name' => ['nullable', 'string', 'max:255'],
            'documents.*.digital_file_base64' => ['nullable', 'string'],
            'existing_document_ids' => ['nullable', 'array'],
            'existing_document_ids.*' => ['integer', 'exists:transfer_box_documents,id'],
        ], [
            'unit_ids.required' => 'Debe seleccionar al menos una unidad productora.',
            'unit_ids.min' => 'Debe seleccionar al menos una unidad productora.',
            'unit_ids.*.integer' => 'Seleccione unidades productoras validas.',
            'unit_ids.*.distinct' => 'No repita una unidad productora.',
            'unit_ids.*.exists' => 'Seleccione una unidad productora valida.',
            'classification_ids.required' => 'Debe seleccionar al menos una serie o subserie documental.',
            'classification_ids.min' => 'Debe seleccionar al menos una serie o subserie documental.',
            'office_id.required' => 'Debe seleccionar la oficina.',
            'aisle_id.required' => 'Debe seleccionar el pasillo.',
            'shelf_id.required' => 'Debe seleccionar el estante.',
            'end_year.gte' => 'El ano final no puede ser menor que el ano inicial.',
            'documents.*.name.required_with' => 'El nombre del documento es obligatorio.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la caja no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $unitIds = collect($request->input('unit_ids', []))->map(fn ($id) => (int) $id)->values();
        $selectedUnits = Unit::query()
            ->whereIn('id', $unitIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        if ($selectedUnits->count() !== $unitIds->count()) {
            return response()->json([
                'message' => 'Los datos de la caja no son validos.',
                'errors' => [
                    'unit_ids' => ['Una o mas unidades productoras no existen o no estan habilitadas.'],
                ],
            ], 422);
        }

        $classificationIds = collect($request->input('classification_ids', []))->values();
        $classificationOptions = $this->archiveClassificationOptions();
        $selectedClassifications = $classificationIds
            ->map(fn (string $classificationId) => $classificationOptions->firstWhere('value', $classificationId))
            ->filter()
            ->values();

        if ($selectedClassifications->count() !== $classificationIds->count()) {
            return response()->json([
                'message' => 'Los datos de la caja no son validos.',
                'errors' => [
                    'classification_ids' => ['Una o mas series o subseries documentales no existen o no estan habilitadas.'],
                ],
            ], 422);
        }

        $selectedSeriesName = $selectedClassifications
            ->pluck('label')
            ->implode('; ');

        $allowedSeriesNames = $selectedClassifications
            ->flatMap(fn (array $classification) => [$classification['label'], $classification['name']])
            ->map(fn ($name) => $this->normalizeArchiveClassificationLabel($name))
            ->filter()
            ->values()
            ->all();

        $documentCodes = [];

        foreach ((array) $request->input('documents', []) as $documentPayload) {
            $documentName = trim((string) ($documentPayload['name'] ?? 'documento'));
            $documentSeries = trim((string) ($documentPayload['series'] ?? ''));

            if ($documentSeries === '') {
                if ($selectedClassifications->count() !== 1) {
                    return response()->json([
                        'message' => 'Los datos de la caja no son validos.',
                        'errors' => [
                            'documents' => ['El documento "' . $documentName . '" debe indicar la serie o subserie documental.'],
                        ],
                    ], 422);
                }

                $documentSeries = $selectedClassifications->first()['label'];
            }

            if (!in_array($this->normalizeArchiveClassificationLabel($documentSeries), $allowedSeriesNames, true)) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['La serie documental "' . $documentSeries . '" no existe o no esta habilitada.'],
                    ],
                ], 422);
            }

            $documentCode = mb_strtolower(trim((string) ($documentPayload['code'] ?? '')));

            if ($documentCode !== '') {
                if (isset($documentCodes[$documentCode])) {
                    return response()->json([
                        'message' => 'Los datos de la caja no son validos.',
                        'errors' => [
                            'documents' => ['El codigo "' . trim((string) ($documentPayload['code'] ?? '')) . '" esta repetido dentro de la caja.'],
                        ],
                    ], 422);
                }

                $documentCodes[$documentCode] = true;
            }

            $documentYear = trim((string) ($documentPayload['year'] ?? ''));
            $startYear = (int) $request->input('start_year');
            $endYear = (int) $request->input('end_year');

            if ($documentYear !== '' && (!preg_match('/^\d{4}$/', $documentYear)
                || (int) $documentYear < $startYear
                || (int) $documentYear > $endYear)) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['El año del documento "' . $documentName . '" debe estar dentro del rango de la caja (' . $startYear . ' - ' . $endYear . ').'],
                    ],
                ], 422);
            }

            $pages = trim((string) ($documentPayload['pages'] ?? ''));

            if ($pages !== '' && (!ctype_digit($pages) || (int) $pages < 1)) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['La cantidad de paginas del documento "' . $documentName . '" debe ser un entero positivo.'],
                    ],
                ], 422);
            }

            $support = $this->normalizeArchiveSupport((string) ($documentPayload['support'] ?? 'Fisico'));

            if (!in_array($support, ['Fisico', 'Digital', 'Mixto'], true)) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['El soporte del documento "' . $documentName . '" no es válido.'],
                    ],
                ], 422);
            }

            $digitalFileName = trim((string) ($documentPayload['digital_file_name'] ?? ''));
            $digitalFileBase64 = trim((string) ($documentPayload['digital_file_base64'] ?? ''));

            if (in_array($support, ['Digital', 'Mixto'], true)
                && ($digitalFileBase64 === '' || $digitalFileName === '')) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['El documento "' . $documentName . '" requiere un archivo PDF para el soporte ' . $support . '.'],
                    ],
                ], 422);
            }

            if ($digitalFileBase64 !== '') {
                $pdfError = PdfUpload::validateBase64($digitalFileName, $digitalFileBase64);

                if ($pdfError !== null) {
                    return response()->json([
                        'message' => 'Los datos de la caja no son validos.',
                        'errors' => [
                            'documents' => [$pdfError],
                        ],
                    ], 422);
                }
            }
        }

        $selectedShelf = $this->findValidShelf(
            (int) $request->input('office_id'),
            (int) $request->input('aisle_id'),
            (int) $request->input('shelf_id')
        );

        if ($selectedShelf === null) {
            return response()->json([
                'message' => 'La ubicación fisica seleccionada no respeta la relacion oficina, pasillo y estante.',
            ], 422);
        }

        try {
            $box = DB::transaction(function () use ($request, $selectedShelf, $selectedSeriesName, $unitIds) {
                $now = now();
                $transferredStatus = RequestStatusCatalog::query()
                    ->where('code', 'transfer_status_transferred')
                    ->value('id');
                $authorizedStatus = RequestStatusCatalog::query()
                    ->where('code', 'transfer_auth_authorized')
                    ->value('id');

                $transfer = Transfer::query()->create([
                    'code' => RequestNumberGenerator::transfer($now),
                    'user_id' => $request->user()->id,
                    'unit_id' => (int) $unitIds->first(),
                    'request_date' => $now->toDateString(),
                    'requested_at' => $now,
                    'status' => 'Aprobada',
                    'authorization_status_id' => $authorizedStatus,
                    'workflow_status_id' => $transferredStatus,
                    'authorized_by_user_id' => $request->user()->id,
                    'authorized_at' => $now,
                    'completed_by_user_id' => $request->user()->id,
                    'completed_at' => $now,
                    'view_mode' => 'archive',
                    'box_display_state' => 'expanded',
                    'show_print_card' => false,
                    'description' => 'Caja creada directamente desde Archivo General.',
                ]);

                $transfer->units()->sync($unitIds->all());

                $boxNumber = ((int) TransferBox::query()->max('box_number')) + 1;
                $boxCode = RequestNumberGenerator::box($now);
                $startYear = (int) $request->input('start_year');
                $endYear = (int) $request->input('end_year');
                $locationCode = $this->buildLocationCode($selectedShelf);

                $box = $transfer->boxes()->create([
                    'series_name' => $selectedSeriesName,
                    'start_year' => $startYear,
                    'end_year' => $endYear,
                    'box_number' => $boxNumber,
                    'box_code' => $boxCode,
                    'title' => 'Caja ' . $boxCode,
                    'period_label' => '01/01/' . $startYear . ' - 31/12/' . $endYear,
                    'location_code' => $locationCode,
                    'assigned_by_user_id' => $request->user()->id,
                    'assigned_at' => $now,
                    'content_description' => $selectedSeriesName,
                ]);

                $sortOrder = 1;
                $existingIds = collect($request->input('existing_document_ids', []))
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values();

                if ($existingIds->isNotEmpty()) {
                    TransferDocument::query()
                        ->whereIn('id', $existingIds)
                        ->get()
                        ->each(function (TransferDocument $document) use ($box, &$sortOrder) {
                            $document->update([
                                'transfer_box_id' => $box->id,
                                'sort_order' => $sortOrder++,
                            ]);
                        });
                }

                foreach ($request->input('documents', []) as $documentPayload) {
                    $documentSeries = trim((string) ($documentPayload['series'] ?? ''));
                    $resolvedSeries = $documentSeries !== '' ? $documentSeries : $selectedSeriesName;
                    $digitalDocument = $this->storeDigitalDocumentPayload($documentPayload, $transfer->code . '-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT));

                    $box->documents()->create([
                        'code' => $documentPayload['code'] ?? 'DOC-' . $transfer->code . '-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT),
                        'name' => $documentPayload['name'],
                        'series_label' => $resolvedSeries,
                        'support_type' => $this->normalizeArchiveSupport((string) ($documentPayload['support'] ?? 'Fisico')),
                        'year_label' => $documentPayload['year'] ?? null,
                        'pages_label' => $documentPayload['pages'] ?? null,
                        'digital_file_name' => $digitalDocument['name'] ?? null,
                        'digital_file_path' => $digitalDocument['path'] ?? null,
                        'sort_order' => $sortOrder++,
                    ]);
                }

                $transfer->events()->create([
                    'status_catalog_id' => $transferredStatus,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Caja creada',
                    'description' => 'Caja registrada directamente en Archivo General.',
                    'context' => [
                        'box_number' => $boxNumber,
                        'box_code' => $boxCode,
                        'location_code' => $locationCode,
                    ],
                    'occurred_at' => $now,
                ]);

                return $box->fresh(['documents', 'transfer.unit', 'transfer.units', 'assignedBy.person']);
            });

            return response()->json([
                'message' => 'Caja creada correctamente.',
                'box' => $this->mapBox($box),
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo crear la caja. Intente nuevamente.',
            ], 500);
        }
    }

    public function destroyBox(Request $request, int $id, UserActivityLogger $activityLogger): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede eliminar cajas del archivo general.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
        ], [
            'current_password.required' => 'Ingrese su contraseña para confirmar la eliminacion.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'La contraseña es obligatoria para eliminar la caja.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!Hash::check((string) $request->input('current_password'), (string) $request->user()->password)) {
            return response()->json([
                'message' => 'La contraseña ingresada no es correcta.',
            ], 422);
        }

        try {
            $box = TransferBox::query()->with('transfer')->findOrFail($id);
            $transfer = $box->transfer;

            if ($transfer?->view_mode !== 'archive') {
                return response()->json([
                    'message' => 'Solo se pueden eliminar cajas creadas directamente desde Archivo General.',
                ], 422);
            }

            $boxCode = $box->boxCode($transfer->code);
            $activityLog = DB::transaction(function () use (
                $activityLogger,
                $request,
                $box,
                $boxCode,
                $transfer,
            ) {
                $log = $activityLogger->log(
                    $request->user(),
                    $request->user(),
                    'archive_box_deleted',
                    'Eliminacion de caja',
                    'Caja: ' . $boxCode,
                    $request,
                );

                $transfer->delete();

                return $log;
            });

            return response()->json([
                'message' => 'Caja eliminada correctamente.',
                'event_id' => $activityLog->id,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo eliminar la caja.',
            ], 500);
        }
    }

    public function updateBoxLocation(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede reasignar cajas del archivo general.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'office_id' => ['required', 'integer', 'exists:physical_location_offices,id'],
            'aisle_id' => ['required', 'integer', 'exists:physical_location_aisles,id'],
            'shelf_id' => ['required', 'integer', 'exists:physical_location_shelves,id'],
        ], [
            'office_id.required' => 'Debe seleccionar la oficina.',
            'aisle_id.required' => 'Debe seleccionar el pasillo.',
            'shelf_id.required' => 'Debe seleccionar el estante.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la nueva ubicación no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $selectedShelf = $this->findValidShelf(
            (int) $request->input('office_id'),
            (int) $request->input('aisle_id'),
            (int) $request->input('shelf_id')
        );

        if ($selectedShelf === null) {
            return response()->json([
                'message' => 'La ubicación física seleccionada no respeta la relación oficina, pasillo y estante.',
            ], 422);
        }

        try {
            $box = DB::transaction(function () use ($id, $request, $selectedShelf) {
                $box = TransferBox::query()
                    ->with(['transfer', 'documents', 'assignedBy.person'])
                    ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->findOrFail($id);

                $previousLocation = $box->location_code;
                $locationCode = $this->buildLocationCode($selectedShelf);

                $box->update([
                    'location_code' => $locationCode,
                ]);

                $box->transfer?->events()->create([
                    'status_catalog_id' => $box->transfer?->workflow_status_id,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Caja reasignada',
                    'description' => 'La caja #' . $box->formattedBoxNumber() . ' fue reasignada a una nueva ubicación física.',
                    'context' => [
                        'box_number' => $box->box_number,
                        'previous_location_code' => $previousLocation,
                        'new_location_code' => $locationCode,
                    ],
                    'occurred_at' => now(),
                ]);

                return $box->fresh(['documents', 'transfer.unit', 'transfer.units', 'assignedBy.person']);
            });

            return response()->json([
                'message' => 'Ubicación de caja reasignada correctamente.',
                'box' => $this->mapBox($box),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo reasignar la ubicación de la caja.',
            ], 500);
        }
    }

    public function storeDocument(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede crear documentos en el archivo general.',
            ], 403);
        }

        $request->merge([
            'series_id' => is_numeric($request->input('series_id'))
                ? 'subseries:' . (int) $request->input('series_id')
                : trim((string) $request->input('series_id')),
        ]);

        $currentYear = (int) now()->format('Y');
        $validator = Validator::make($request->all(), [
            'box_id' => ['required', 'integer', 'exists:transfer_boxes,id'],
            'series_id' => ['required', 'string', 'regex:/^subseries:\d+$/'],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:1900', 'max:' . $currentYear],
            'support' => ['required', 'string', 'in:Fisico,Digital,Mixto'],
            'pages' => ['required', 'integer', 'min:1', 'max:99999'],
            'digital_file_name' => ['nullable', 'string', 'max:255'],
            'digital_file_base64' => ['nullable', 'string'],
        ], [
            'box_id.required' => 'Debe seleccionar la caja donde se asignara el documento.',
            'series_id.required' => 'Debe seleccionar la serie o subserie documental.',
            'code.required' => 'El codigo del documento es obligatorio.',
            'name.required' => 'El nombre del documento es obligatorio.',
            'year.required' => 'El ano del documento es obligatorio.',
            'year.min' => 'El ano del documento debe ser mayor o igual a 1900.',
            'year.max' => 'El ano del documento no puede ser mayor al ano actual.',
            'support.required' => 'Debe seleccionar el tipo de soporte.',
            'support.in' => 'El tipo de soporte seleccionado no es válido.',
            'pages.required' => 'Debe indicar el número de páginas.',
            'pages.min' => 'El número de páginas debe ser mayor que 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del documento no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $support = (string) $request->input('support');
        $hasDigitalPayload = trim((string) $request->input('digital_file_base64', '')) !== '';

        if (in_array($support, ['Digital', 'Mixto'], true)) {
            $fileName = trim((string) $request->input('digital_file_name', ''));

            if (!$hasDigitalPayload || $fileName === '') {
                return response()->json([
                    'message' => 'Los datos del documento no son validos.',
                    'errors' => [
                        'digital_file' => ['Debe adjuntar un archivo PDF para documentos digitales o mixtos.'],
                    ],
                ], 422);
            }

            $pdfError = PdfUpload::validateBase64($fileName, (string) $request->input('digital_file_base64', ''));

            if ($pdfError !== null) {
                return response()->json([
                    'message' => 'Los datos del documento no son validos.',
                    'errors' => [
                        'digital_file' => [$pdfError],
                    ],
                ], 422);
            }
        }

        try {
            $document = DB::transaction(function () use ($request, $support, $hasDigitalPayload) {
                $box = TransferBox::query()
                    ->with('transfer')
                    ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->find((int) $request->input('box_id'));

                if ($box === null) {
                    throw new \RuntimeException('La caja seleccionada no esta disponible para asignar documentos.', 422);
                }

                $classification = $this->archiveClassificationOptions()
                    ->firstWhere('value', (string) $request->input('series_id'));

                if ($classification === null) {
                    throw new \RuntimeException('La serie o subserie documental seleccionada no esta habilitada.', 422);
                }

                $boxSeriesNames = collect(explode(';', (string) $box->series_name))
                    ->map(fn ($name) => $this->normalizeArchiveClassificationLabel($name))
                    ->filter();
                $classificationNames = collect([$classification['label'], $classification['name']])
                    ->map(fn ($name) => $this->normalizeArchiveClassificationLabel($name));

                if ($classificationNames->intersect($boxSeriesNames)->isEmpty()) {
                    throw new \RuntimeException('La serie o subserie documental debe coincidir con la clasificacion asignada a la caja.', 422);
                }

                $documentYear = (int) $request->input('year');

                if ($box->start_year && $box->end_year && ($documentYear < (int) $box->start_year || $documentYear > (int) $box->end_year)) {
                    throw new \RuntimeException('El ano del documento debe estar dentro del periodo de la caja (' . $box->start_year . ' - ' . $box->end_year . ').', 422);
                }

                $code = trim((string) $request->input('code'));
                $codeExists = $box->documents()
                    ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
                    ->exists();

                if ($codeExists) {
                    throw new \RuntimeException('Ya existe un documento con ese codigo en la caja seleccionada.', 422);
                }

                $sortOrder = ((int) $box->documents()->max('sort_order')) + 1;
                $digitalDocument = null;

                if ($support !== 'Fisico' && $hasDigitalPayload) {
                    $fallbackName = ($box->transfer?->code ?? 'archivo-general') . '-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT);
                    $digitalDocument = $this->storeDigitalDocumentPayload($request->only([
                        'digital_file_name',
                        'digital_file_base64',
                    ]), $fallbackName);
                }

                return $box->documents()->create([
                    'code' => $code,
                    'name' => trim((string) $request->input('name')),
                    'series_label' => $classification['label'],
                    'support_type' => $support,
                    'year_label' => (string) $documentYear,
                    'pages_label' => (string) ((int) $request->input('pages')),
                    'digital_file_name' => $digitalDocument['name'] ?? null,
                    'digital_file_path' => $digitalDocument['path'] ?? null,
                    'sort_order' => $sortOrder,
                ]);
            });

            return response()->json([
                'message' => 'Documento creado correctamente.',
                'document' => $this->mapDocument($document->fresh(['box.transfer.unit', 'box.assignedBy.person'])),
            ], 201);
        } catch (\RuntimeException $exception) {
            if ((int) $exception->getCode() === 422) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            report($exception);

            return response()->json([
                'message' => 'No se pudo crear el documento. Intente nuevamente.',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo crear el documento. Intente nuevamente.',
            ], 500);
        }
    }

    public function updateDocument(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede modificar documentos del archivo general.',
            ], 403);
        }

        $request->merge([
            'series_id' => is_numeric($request->input('series_id'))
                ? 'subseries:' . (int) $request->input('series_id')
                : trim((string) $request->input('series_id')),
        ]);

        $currentYear = (int) now()->format('Y');
        $validator = Validator::make($request->all(), [
            'series_id' => ['required', 'string', 'regex:/^(subseries|existing):\d+$/'],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:1900', 'max:' . $currentYear],
            'support' => ['required', 'string', 'in:Fisico,Digital,Mixto'],
            'pages' => ['required', 'integer', 'min:1', 'max:99999'],
            'digital_file_name' => ['nullable', 'string', 'max:255'],
            'digital_file_base64' => ['nullable', 'string'],
        ], [
            'series_id.required' => 'Debe seleccionar la serie o subserie documental.',
            'code.required' => 'El codigo del documento es obligatorio.',
            'name.required' => 'El nombre del documento es obligatorio.',
            'year.required' => 'El ano del documento es obligatorio.',
            'year.min' => 'El ano del documento debe ser mayor o igual a 1900.',
            'year.max' => 'El ano del documento no puede ser mayor al ano actual.',
            'support.required' => 'Debe seleccionar el tipo de soporte.',
            'support.in' => 'El tipo de soporte seleccionado no es válido.',
            'pages.required' => 'Debe indicar el número de páginas.',
            'pages.min' => 'El número de páginas debe ser mayor que 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del documento no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $support = (string) $request->input('support');
        $hasDigitalPayload = trim((string) $request->input('digital_file_base64', '')) !== '';
        $fileName = trim((string) $request->input('digital_file_name', ''));

        if ($hasDigitalPayload) {
            $pdfError = PdfUpload::validateBase64($fileName, (string) $request->input('digital_file_base64', ''));

            if ($pdfError !== null) {
                return response()->json([
                    'message' => 'Los datos del documento no son validos.',
                    'errors' => [
                        'digital_file' => [$pdfError],
                    ],
                ], 422);
            }
        }

        try {
            [$document, $oldDigitalPath] = DB::transaction(function () use ($id, $request, $support, $hasDigitalPayload) {
                $document = TransferDocument::query()
                    ->with('box.transfer')
                    ->whereHas('box.transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->find($id);

                if ($document === null || $document->box === null) {
                    throw new \RuntimeException('El documento no fue encontrado en una caja disponible.', 404);
                }

                $box = $document->box;
                $seriesId = (string) $request->input('series_id');
                $classification = null;

                if (preg_match('/^existing:(\d+)$/', $seriesId, $existingSeriesMatch) === 1) {
                    if ((int) $existingSeriesMatch[1] !== (int) $document->id) {
                        throw new \RuntimeException('La serie documental histórica no corresponde al documento seleccionado.', 422);
                    }

                    $classification = [
                        'label' => $document->series_label,
                        'name' => $document->series_label,
                    ];
                } else {
                    $classification = $this->archiveClassificationOptions()
                        ->firstWhere('value', $seriesId);
                }

                if ($classification === null) {
                    throw new \RuntimeException('La serie o subserie documental seleccionada no esta habilitada.', 422);
                }

                $boxSeriesNames = collect(explode(';', (string) $box->series_name))
                    ->map(fn ($name) => $this->normalizeArchiveClassificationLabel($name))
                    ->filter();
                $classificationNames = collect([$classification['label'], $classification['name']])
                    ->map(fn ($name) => $this->normalizeArchiveClassificationLabel($name));
                $currentDocumentSeries = $this->normalizeArchiveClassificationLabel($document->series_label);
                $isCurrentDocumentClassification = $classificationNames->contains($currentDocumentSeries);

                if ($classificationNames->intersect($boxSeriesNames)->isEmpty() && !$isCurrentDocumentClassification) {
                    throw new \RuntimeException('La serie o subserie documental debe coincidir con la clasificacion asignada a la caja.', 422);
                }

                $documentYear = (int) $request->input('year');

                if ($box->start_year && $box->end_year
                    && ($documentYear < (int) $box->start_year || $documentYear > (int) $box->end_year)) {
                    throw new \RuntimeException('El ano del documento debe estar dentro del periodo de la caja (' . $box->start_year . ' - ' . $box->end_year . ').', 422);
                }

                $hasExistingDigitalFile = !empty($document->digital_file_name)
                    && !empty($document->digital_file_path);

                if (in_array($support, ['Digital', 'Mixto'], true)
                    && !$hasDigitalPayload
                    && !$hasExistingDigitalFile) {
                    throw new \RuntimeException('Debe conservar el archivo PDF actual o adjuntar uno nuevo para el soporte ' . $support . '.', 422);
                }

                $code = trim((string) $request->input('code'));
                $codeExists = $box->documents()
                    ->where('id', '<>', $document->id)
                    ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
                    ->exists();

                if ($codeExists) {
                    throw new \RuntimeException('Ya existe otro documento con ese codigo en la caja seleccionada.', 422);
                }

                $oldDigitalPath = $document->digital_file_path;
                $digitalDocument = null;

                if ($support !== 'Fisico' && $hasDigitalPayload) {
                    $digitalDocument = $this->storeDigitalDocumentPayload($request->only([
                        'digital_file_name',
                        'digital_file_base64',
                    ]), ($box->transfer?->code ?: 'archivo-general') . '-' . $document->id);

                    if ($digitalDocument === null) {
                        throw new \RuntimeException('No se pudo guardar el archivo PDF del documento.', 422);
                    }
                }

                $document->update([
                    'code' => $code,
                    'name' => trim((string) $request->input('name')),
                    'series_label' => $classification['label'],
                    'support_type' => $support,
                    'year_label' => (string) $documentYear,
                    'pages_label' => (string) ((int) $request->input('pages')),
                    'digital_file_name' => $support === 'Fisico'
                        ? null
                        : ($digitalDocument['name'] ?? $document->digital_file_name),
                    'digital_file_path' => $support === 'Fisico'
                        ? null
                        : ($digitalDocument['path'] ?? $document->digital_file_path),
                ]);

                $box->transfer?->events()->create([
                    'status_catalog_id' => $box->transfer?->workflow_status_id,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Documento actualizado',
                    'description' => 'El documento "' . $document->name . '" fue actualizado desde la gestión del archivo.',
                    'context' => [
                        'document_id' => $document->id,
                        'box_id' => $box->id,
                        'support' => $support,
                    ],
                    'occurred_at' => now(),
                ]);

                return [$document->fresh(['box.transfer.unit', 'box.assignedBy.person']), $oldDigitalPath];
            });

            if ($oldDigitalPath && $oldDigitalPath !== $document->digital_file_path) {
                Storage::disk('local')->delete($oldDigitalPath);
            }

            return response()->json([
                'message' => 'Documento actualizado correctamente.',
                'document' => $this->mapDocument($document),
            ]);
        } catch (\RuntimeException $exception) {
            if (in_array((int) $exception->getCode(), [404, 422], true)) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], (int) $exception->getCode());
            }

            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar el documento. Intente nuevamente.',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar el documento. Intente nuevamente.',
            ], 500);
        }
    }

    public function updateDocumentBox(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede reasignar documentos del archivo general.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'box_id' => ['required', 'integer', 'exists:transfer_boxes,id'],
        ], [
            'box_id.required' => 'Debe seleccionar la caja de destino.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la caja de destino no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $document = DB::transaction(function () use ($id, $request) {
                $document = TransferDocument::query()
                    ->with('box.transfer')
                    ->whereHas('box.transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->findOrFail($id);

                $destinationBox = TransferBox::query()
                    ->with('transfer')
                    ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->findOrFail((int) $request->input('box_id'));

                if ((int) $document->transfer_box_id === (int) $destinationBox->id) {
                    throw new \RuntimeException('El documento ya pertenece a la caja seleccionada.', 422);
                }

                $previousBox = $document->box;
                $sortOrder = ((int) $destinationBox->documents()->max('sort_order')) + 1;

                $document->update([
                    'transfer_box_id' => $destinationBox->id,
                    'sort_order' => $sortOrder,
                ]);

                $destinationBox->transfer?->events()->create([
                    'status_catalog_id' => $destinationBox->transfer?->workflow_status_id,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Documento reasignado',
                    'description' => 'El documento "' . $document->name . '" fue movido a la caja #' . $destinationBox->formattedBoxNumber() . '.',
                    'context' => [
                        'document_id' => $document->id,
                        'document_code' => $document->code,
                        'previous_box_id' => $previousBox?->id,
                        'previous_box_number' => $previousBox?->box_number,
                        'destination_box_id' => $destinationBox->id,
                        'destination_box_number' => $destinationBox->box_number,
                    ],
                    'occurred_at' => now(),
                ]);

                return $document->fresh(['box.transfer.unit', 'box.assignedBy.person']);
            });

            return response()->json([
                'message' => 'Documento reasignado correctamente.',
                'document' => $this->mapDocument($document),
            ]);
        } catch (\RuntimeException $exception) {
            if ((int) $exception->getCode() === 422) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            report($exception);

            return response()->json([
                'message' => 'No se pudo reasignar el documento.',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo reasignar el documento.',
            ], 500);
        }
    }

    public function documents(Request $request): JsonResponse
    {
        if (!$this->canSearchArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede consultar el archivo general.',
            ], 403);
        }

        try {
            $term = trim((string) $request->query('q', ''));
            $searchDate = $this->parseSearchDate($term);
            $classificationId = trim((string) $request->query('classification_id', ''));
            $classification = $classificationId !== ''
                ? $this->resolveArchiveReservationClassification($classificationId)
                : null;

            if ($classificationId !== '' && $classification === null) {
                return response()->json([
                    'message' => 'La subserie documental seleccionada no es válida.',
                ], 422);
            }

            $documentsBaseQuery = TransferDocument::query()
                ->whereHas('box.transfer', fn (Builder $query) => $query->whereNotNull('completed_at'));

            $totals = [
                'documents' => (clone $documentsBaseQuery)->count(),
                'boxes' => TransferBox::query()
                    ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->count(),
                'completed_transfers' => Transfer::query()
                    ->whereNotNull('completed_at')
                    ->count(),
            ];

            if ($classification !== null) {
                $this->applyArchiveReservationClassificationFilter($documentsBaseQuery, $classification);
            }

            if ($request->boolean('available_for_reservation')) {
                $documentsBaseQuery->where('is_reserved', false);
            }

            if ($term === '' && !$request->boolean('all')) {
                return response()->json([
                    'totals' => $totals,
                    'query' => $term,
                    'results_count' => 0,
                    'results' => [],
                ]);
            }

            $resultsQuery = (clone $documentsBaseQuery)
                ->with([
                    'box.assignedBy.person',
                    'box.transfer.unit',
                    'box.transfer.requester.person',
                    'box.transfer.completedBy.person',
                    'reservedBy.person',
                ]);

            if ($term !== '') {
                $resultsQuery->where(function (Builder $query) use ($term, $searchDate) {
                    $this->addSearchClauses($query, [
                        'transfer_box_documents.code',
                        'transfer_box_documents.name',
                        'transfer_box_documents.series_label',
                        'transfer_box_documents.support_type',
                        'transfer_box_documents.year_label',
                        'transfer_box_documents.pages_label',
                        'transfer_box_documents.digital_file_name',
                    ], $term);

                    $query->orWhereHas('box', function (Builder $boxQuery) use ($term, $searchDate) {
                        $this->addSearchClauses($boxQuery, [
                            'transfer_boxes.box_code',
                            'transfer_boxes.box_number',
                            'transfer_boxes.location_code',
                        ], $term);

                        if ($searchDate !== null) {
                            $boxQuery->orWhereDate('transfer_boxes.assigned_at', $searchDate);
                        }
                    });

                    $query->orWhereHas('box.transfer', function (Builder $transferQuery) use ($term, $searchDate) {
                        $this->addSearchClauses($transferQuery, ['transfers.code'], $term);

                        if ($searchDate !== null) {
                            $transferQuery
                                ->orWhereDate('transfers.request_date', $searchDate)
                                ->orWhereDate('transfers.requested_at', $searchDate)
                                ->orWhereDate('transfers.authorized_at', $searchDate)
                                ->orWhereDate('transfers.completed_at', $searchDate)
                                ->orWhereDate('transfers.scheduled_for', $searchDate);
                        }
                    });

                    $query->orWhereHas('box.transfer.unit', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, [
                            'units.code',
                            'units.name',
                        ], $term);
                    });
                });
            }

            $resultsCount = (clone $resultsQuery)->count();
            $page = max(1, (int) $request->query('page', 1));
            $perPage = (int) $request->query('per_page', 100);
            $perPage = min(max($perPage, 5), 100);
            $loanedDocuments = $this->loanedDocumentMap();
            $resultsPaginator = $resultsQuery
                ->orderBy('transfer_box_documents.name')
                ->orderBy('transfer_box_documents.id')
                ->paginate($perPage, ['*'], 'page', $page);
            $results = $resultsPaginator->getCollection()
                ->map(fn (TransferDocument $document) => $this->mapDocument($document, $loanedDocuments))
                ->values();
            $meta = [
                'current_page' => $resultsPaginator->currentPage(),
                'last_page' => $resultsPaginator->lastPage(),
                'per_page' => $resultsPaginator->perPage(),
                'total' => $resultsPaginator->total(),
                'from' => $resultsPaginator->firstItem(),
                'to' => $resultsPaginator->lastItem(),
            ];

            return response()->json([
                'totals' => $totals,
                'query' => $term,
                'results_count' => $resultsCount,
                'results_limit' => $perPage,
                'meta' => $meta,
                'results' => $results,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar el archivo general.',
            ], 500);
        }
    }

    public function reservedDocuments(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede consultar documentos reservados.',
            ], 403);
        }

        try {
            $term = trim((string) $request->query('q', ''));
            $classificationId = trim((string) $request->query('classification_id', ''));
            $classification = $classificationId !== ''
                ? $this->resolveArchiveReservationClassification($classificationId)
                : null;

            if ($classificationId !== '' && $classification === null) {
                return response()->json([
                    'message' => 'La subserie documental seleccionada no es válida.',
                ], 422);
            }

            $query = TransferDocument::query()
                ->with([
                    'reservedBy.person',
                    'box.assignedBy.person',
                    'box.transfer.unit',
                    'box.transfer.requester.person',
                    'box.transfer.completedBy.person',
                ])
                ->where('is_reserved', true)
                ->whereHas('box.transfer', fn (Builder $transferQuery) => $transferQuery->whereNotNull('completed_at'));

            if ($classification !== null) {
                $this->applyArchiveReservationClassificationFilter($query, $classification);
            }

            if ($term !== '') {
                $query->where(function (Builder $searchQuery) use ($term) {
                    $this->addSearchClauses($searchQuery, [
                        'transfer_box_documents.code',
                        'transfer_box_documents.name',
                        'transfer_box_documents.series_label',
                        'transfer_box_documents.support_type',
                        'transfer_box_documents.year_label',
                    ], $term);

                    $searchQuery->orWhereHas('box', function (Builder $boxQuery) use ($term) {
                        $this->addSearchClauses($boxQuery, [
                            'transfer_boxes.box_code',
                            'transfer_boxes.box_number',
                            'transfer_boxes.series_name',
                            'transfer_boxes.location_code',
                            'transfer_boxes.title',
                        ], $term);
                    });

                    $searchQuery->orWhereHas('box.transfer.unit', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, [
                            'units.code',
                            'units.name',
                        ], $term);
                    });
                });
            }

            $page = max(1, (int) $request->query('page', 1));
            $perPage = (int) $request->query('per_page', 10);
            $perPage = min(max($perPage, 5), 50);
            $documentsPaginator = $query
                ->orderByDesc('reserved_at')
                ->orderBy('transfer_box_documents.name')
                ->paginate($perPage, ['*'], 'page', $page);
            $documents = $documentsPaginator->getCollection()
                ->map(fn (TransferDocument $document) => $this->mapDocument($document))
                ->values();
            $meta = [
                'current_page' => $documentsPaginator->currentPage(),
                'last_page' => $documentsPaginator->lastPage(),
                'per_page' => $documentsPaginator->perPage(),
                'total' => $documentsPaginator->total(),
                'from' => $documentsPaginator->firstItem(),
                'to' => $documentsPaginator->lastItem(),
            ];

            return response()->json([
                'documents' => $documents,
                'data' => $documents,
                'count' => $documentsPaginator->total(),
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron consultar los documentos reservados.',
            ], 500);
        }
    }

    public function storeReservedDocument(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede marcar documentos reservados.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'document_id' => [
                'nullable',
                'integer',
                'exists:transfer_box_documents,id',
                'required_without_all:document_ids,classification_id',
            ],
            'document_ids' => [
                'nullable',
                'array',
                'min:1',
                'required_without_all:document_id,classification_id',
            ],
            'document_ids.*' => ['integer', 'distinct', 'exists:transfer_box_documents,id'],
            'classification_id' => [
                'nullable',
                'string',
                'regex:/^subseries:\d+$/',
                'required_without_all:document_id,document_ids',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Seleccione uno o mas documentos, o una subserie documental para reservar.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $documentIds = collect($request->input('document_ids', []))
            ->push($request->input('document_id'))
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $classificationId = trim((string) $request->input('classification_id', ''));
        $classification = $classificationId !== ''
            ? $this->resolveArchiveReservationClassification($classificationId)
            : null;

        if ($classificationId !== '' && $classification === null) {
            return response()->json([
                'message' => 'La subserie documental seleccionada no es valida.',
            ], 422);
        }

        try {
            [$documents, $matchedCount] = DB::transaction(function () use ($documentIds, $classification, $request) {
                $query = TransferDocument::query()
                    ->whereHas('box.transfer', fn (Builder $transferQuery) => $transferQuery->whereNotNull('completed_at'));

                if ($documentIds->isNotEmpty()) {
                    $query->whereIn('transfer_box_documents.id', $documentIds->all());
                }

                if ($classification !== null) {
                    $this->applyArchiveReservationClassificationFilter($query, $classification);
                }

                $matchedCount = (clone $query)->count();
                $documents = $query
                    ->where('is_reserved', false)
                    ->lockForUpdate()
                    ->get();

                if ($documents->isEmpty()) {
                    throw new \RuntimeException(
                        $classification !== null
                            ? 'Todos los documentos de la subserie seleccionada ya están reservados o no están disponibles.'
                            : 'Los documentos seleccionados ya estan reservados o no estan disponibles en el archivo general.',
                        422,
                    );
                }

                $reservedAt = now();

                $documents->each(fn (TransferDocument $document) => $document->forceFill([
                    'is_reserved' => true,
                    'reserved_by_user_id' => $request->user()->id,
                    'reserved_at' => $reservedAt,
                ])->save());

                return [$documents, $matchedCount];
            });

            $reservedCount = $documents->count();
            $skippedCount = max(0, $matchedCount - $reservedCount);
            $firstDocument = $reservedCount === 1
                ? $documents->first()->fresh([
                    'reservedBy.person',
                    'box.assignedBy.person',
                    'box.transfer.unit',
                    'box.transfer.requester.person',
                    'box.transfer.completedBy.person',
                ])
                : null;
            $selectionLabel = $classification['label'] ?? null;
            $message = $reservedCount === 1 && $selectionLabel === null
                ? 'El documento "' . $documents->first()->name . '" fue agregado a reservados.'
                : ($selectionLabel !== null
                    ? 'Se agregaron ' . $reservedCount . ' documentos de "' . $selectionLabel . '" a reservados.'
                    : 'Se agregaron ' . $reservedCount . ' documentos a reservados.');

            return response()->json([
                'message' => $message,
                'reserved_count' => $reservedCount,
                'skipped_count' => $skippedCount,
                'document' => $firstDocument ? $this->mapDocument($firstDocument) : null,
            ], 201);
        } catch (\RuntimeException $exception) {
            if ((int) $exception->getCode() === 422) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            report($exception);

            return response()->json([
                'message' => 'No se pudo agregar el documento a reservados.',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo agregar el documento a reservados.',
            ], 500);
        }
    }

    public function destroyReservedDocument(Request $request, int $id, UserActivityLogger $activityLogger): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede quitar documentos reservados.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
        ], [
            'current_password.required' => 'Ingrese su contraseña para confirmar la eliminación.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'La contraseña es obligatoria para quitar el documento de reservados.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!Hash::check((string) $request->input('current_password'), (string) $request->user()->password)) {
            return response()->json([
                'message' => 'La contraseña ingresada no es correcta.',
            ], 422);
        }

        try {
            $document = TransferDocument::query()
                ->whereKey($id)
                ->where('is_reserved', true)
                ->first();

            if ($document === null) {
                return response()->json([
                    'message' => 'El documento reservado no fue encontrado.',
                ], 404);
            }

            $documentName = $document->name;
            $classificationLabel = trim((string) $document->series_label) ?: 'No disponible';
            $activityLog = DB::transaction(function () use (
                $activityLogger,
                $request,
                $document,
                $documentName,
                $classificationLabel,
            ) {
                $document->forceFill([
                    'is_reserved' => false,
                    'reserved_by_user_id' => null,
                    'reserved_at' => null,
                ])->save();

                return $activityLogger->log(
                    $request->user(),
                    $request->user(),
                    'reserved_document_deleted',
                    'Eliminacion de documento reservado',
                    'Documento: ' . $document->code
                        . ' - ' . $documentName
                        . '. Serie: ' . $classificationLabel . '.',
                    $request,
                );
            });

            return response()->json([
                'message' => 'El documento "' . $documentName . '" fue removido de reservados.',
                'event_id' => $activityLog->id,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo remover el documento de reservados.',
            ], 500);
        }
    }

    public function destroyReservedDocumentsByClassification(
        Request $request,
        UserActivityLogger $activityLogger,
    ): JsonResponse {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede quitar documentos reservados.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'classification_id' => ['required', 'string', 'regex:/^subseries:\d+$/'],
            'current_password' => ['required', 'string'],
        ], [
            'classification_id.required' => 'Seleccione la subserie que desea quitar de reservados.',
            'classification_id.regex' => 'La subserie documental seleccionada no es válida.',
            'current_password.required' => 'Ingrese su contraseña para confirmar la eliminación.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Seleccione una subserie e ingrese su contraseña para continuar.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!Hash::check((string) $request->input('current_password'), (string) $request->user()->password)) {
            return response()->json([
                'message' => 'La contraseña ingresada no es correcta.',
            ], 422);
        }

        $classificationId = trim((string) $request->input('classification_id'));
        $classification = $this->resolveArchiveReservationClassification($classificationId);

        if ($classification === null) {
            return response()->json([
                'message' => 'La subserie documental seleccionada no es válida.',
            ], 422);
        }

        try {
            [$removedCount, $activityLog] = DB::transaction(function () use (
                $activityLogger,
                $classification,
                $request,
            ) {
                $query = TransferDocument::query()
                    ->where('is_reserved', true)
                    ->whereHas(
                        'box.transfer',
                        fn (Builder $transferQuery) => $transferQuery->whereNotNull('completed_at'),
                    );
                $this->applyArchiveReservationClassificationFilter($query, $classification);
                $documents = $query->lockForUpdate()->get();

                if ($documents->isEmpty()) {
                    throw new \RuntimeException(
                        'La subserie seleccionada no tiene documentos reservados.',
                        422,
                    );
                }

                $documents->each(fn (TransferDocument $document) => $document->forceFill([
                    'is_reserved' => false,
                    'reserved_by_user_id' => null,
                    'reserved_at' => null,
                ])->save());

                $removedCount = $documents->count();
                $activityLog = $activityLogger->log(
                    $request->user(),
                    $request->user(),
                    'reserved_subseries_documents_deleted',
                    'Eliminacion de documentos reservados por subserie',
                    'Serie/Subserie: ' . $classification['label']
                        . '. Documentos retirados: ' . $removedCount . '.',
                    $request,
                );

                return [$removedCount, $activityLog];
            });

            return response()->json([
                'message' => 'Se quitaron ' . $removedCount . ' documentos de "'
                    . $classification['label'] . '" de reservados.',
                'removed_count' => $removedCount,
                'event_id' => $activityLog->id,
            ]);
        } catch (\RuntimeException $exception) {
            if ((int) $exception->getCode() === 422) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            report($exception);

            return response()->json([
                'message' => 'No se pudieron quitar los documentos de la subserie de reservados.',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron quitar los documentos de la subserie de reservados.',
            ], 500);
        }
    }

    public function destroySelectedReservedDocuments(
        Request $request,
        UserActivityLogger $activityLogger,
    ): JsonResponse {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede quitar documentos reservados.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['required', 'integer', 'distinct'],
            'current_password' => ['required', 'string'],
        ], [
            'document_ids.required' => 'Seleccione al menos un documento reservado.',
            'document_ids.array' => 'La selección de documentos no es válida.',
            'document_ids.min' => 'Seleccione al menos un documento reservado.',
            'document_ids.*.integer' => 'La selección contiene un documento no válido.',
            'document_ids.*.distinct' => 'La selección contiene documentos duplicados.',
            'current_password.required' => 'Ingrese su contraseña para confirmar la eliminación.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Seleccione los documentos e ingrese su contraseña para continuar.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!Hash::check((string) $request->input('current_password'), (string) $request->user()->password)) {
            return response()->json([
                'message' => 'La contraseña ingresada no es correcta.',
            ], 422);
        }

        $documentIds = collect($request->input('document_ids'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        try {
            [$removedCount, $activityLog] = DB::transaction(function () use (
                $activityLogger,
                $documentIds,
                $request,
            ) {
                $documents = TransferDocument::query()
                    ->whereIn('transfer_box_documents.id', $documentIds->all())
                    ->where('is_reserved', true)
                    ->whereHas(
                        'box.transfer',
                        fn (Builder $transferQuery) => $transferQuery->whereNotNull('completed_at'),
                    )
                    ->lockForUpdate()
                    ->get();

                if ($documents->count() !== $documentIds->count()) {
                    throw new \RuntimeException(
                        'Uno o más documentos seleccionados ya no están disponibles en la lista de reservados.',
                        422,
                    );
                }

                $documents->each(fn (TransferDocument $document) => $document->forceFill([
                    'is_reserved' => false,
                    'reserved_by_user_id' => null,
                    'reserved_at' => null,
                ])->save());

                $removedCount = $documents->count();
                $documentCodes = $documents
                    ->pluck('code')
                    ->map(fn ($code) => trim((string) $code))
                    ->filter()
                    ->implode(', ');
                $activityLog = $activityLogger->log(
                    $request->user(),
                    $request->user(),
                    'selected_reserved_documents_deleted',
                    'Eliminacion de documentos reservados seleccionados',
                    'Documentos retirados: ' . $removedCount
                        . ($documentCodes !== '' ? '. Codigos: ' . $documentCodes : '')
                        . '.',
                    $request,
                );

                return [$removedCount, $activityLog];
            });

            return response()->json([
                'message' => $removedCount === 1
                    ? 'El documento seleccionado fue removido de reservados.'
                    : 'Se quitaron ' . $removedCount . ' documentos seleccionados de reservados.',
                'removed_count' => $removedCount,
                'event_id' => $activityLog->id,
            ]);
        } catch (\RuntimeException $exception) {
            if ((int) $exception->getCode() === 422) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            report($exception);

            return response()->json([
                'message' => 'No se pudieron quitar los documentos seleccionados de reservados.',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron quitar los documentos seleccionados de reservados.',
            ], 500);
        }
    }

    private function storeDigitalDocumentPayload(array $documentPayload, string $fallbackName): ?array
    {
        $base64Content = trim((string) ($documentPayload['digital_file_base64'] ?? ''));

        if ($base64Content === '') {
            return null;
        }

        $originalName = trim((string) ($documentPayload['digital_file_name'] ?? ''));
        $fileName = $originalName !== '' ? $this->sanitizeFileName($originalName) : $this->sanitizeFileName($fallbackName . '.pdf');
        $decodedContent = PdfUpload::decodeBase64($base64Content);

        if ($decodedContent === null) {
            return null;
        }

        $storagePath = 'uploads/' . $fileName;
        Storage::disk('local')->put($storagePath, $decodedContent);

        return [
            'name' => $fileName,
            'path' => $storagePath,
        ];
    }

    private function sanitizeFileName(string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME) ?: 'documento';
        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'pdf';
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'documento';
        $safeExtension = preg_replace('/[^A-Za-z0-9]+/', '', $extension) ?: 'pdf';

        return $safeName . '.' . $safeExtension;
    }

    private function parseSearchDate(string $term): ?string
    {
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!' . $format, $term);
            } catch (Throwable) {
                continue;
            }

            if ($date !== false && $date->format($format) === $term) {
                return $date->toDateString();
            }
        }

        return null;
    }

    private function addSearchClauses(Builder $query, array $columns, string $term): void
    {
        $like = TextSearch::containsPattern($term);

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}(TextSearch::postgresTextExpression($column) . ' LIKE ?', [$like]);
        }
    }

    private function resolveArchiveReservationClassification(string $classificationId): ?array
    {
        if (preg_match('/^subseries:(\d+)$/', $classificationId, $matches) === 1) {
            $option = $this->archiveClassificationOptions()
                ->firstWhere('value', 'subseries:' . (int) $matches[1]);

            return $option === null ? null : [
                'label' => $option['label'],
                'names' => collect([$option['label'], $option['name']])
                    ->map(fn ($name) => trim((string) $name))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        return null;
    }

    private function applyArchiveReservationClassificationFilter(Builder $query, array $classification): void
    {
        $names = collect($classification['names'] ?? [])
            ->map(fn ($name) => TextSearch::normalize((string) $name))
            ->filter()
            ->unique()
            ->values();
        $normalizedColumn = 'TRIM(' . TextSearch::postgresTextExpression('transfer_box_documents.series_label') . ')';

        $query->where(function (Builder $classificationQuery) use ($names, $normalizedColumn) {
            foreach ($names as $index => $name) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $classificationQuery->{$method}(
                    "{$normalizedColumn} = ?",
                    [$name],
                );
            }
        });
    }

    private function mapDocument(TransferDocument $document, array $loanedDocuments = []): array
    {
        $box = $document->box;
        $transfer = $box?->transfer;
        $unit = $transfer?->unit;
        $boxCode = $box?->boxCode($transfer?->code);
        $loan = $loanedDocuments[(int) $document->id] ?? null;
        $hasDigitalFile = in_array($document->support_type, ['Digital', 'Mixto'], true)
            && !empty($document->digital_file_name);
        $boxNumber = $box?->box_number !== null
            ? str_pad((string) $box->box_number, 3, '0', STR_PAD_LEFT)
            : null;

        return [
            'id' => $document->id,
            'title' => $document->name,
            'code' => $document->code,
            'series' => $document->series_label,
            'support' => $document->support_type,
            'year' => $document->year_label,
            'pages' => $document->pages_label,
            'created_at' => optional($document->created_at)->toDateTimeString(),
            'created_at_label' => optional($document->created_at)->format('d/m/Y H:i'),
            'is_reserved' => (bool) $document->is_reserved,
            'reserved_at' => optional($document->reserved_at)->toDateTimeString(),
            'reserved_at_label' => optional($document->reserved_at)->format('d/m/Y H:i'),
            'reserved_by' => $this->formatUserName($document->reservedBy),
            'is_loaned' => $loan !== null,
            'loan' => $loan,
            'digital_available' => $hasDigitalFile,
            'digital_file' => $hasDigitalFile ? $document->digital_file_name : null,
            'digital_url' => $hasDigitalFile && $transfer?->code && $document->code
                ? SignedPdfUrl::transferDocument($transfer->code, SignedPdfUrl::transferDocumentReference($document->id))
                : null,
            'box' => [
                'id' => $box?->id,
                'number' => $boxNumber,
                'code' => $boxCode,
                'title' => $box?->title,
                'period' => $box?->period_label,
                'start_year' => $box?->start_year,
                'end_year' => $box?->end_year,
                'location' => $box?->location_code,
                'series' => $box?->series_name,
                'content_description' => $box?->content_description,
                'assigned_at_label' => $box?->assigned_at?->format('d/m/Y H:i'),
                'assigned_by' => $this->formatUserName($box?->assignedBy),
            ],
            'unit' => [
                'id' => $unit?->id,
                'code' => $unit?->code,
                'name' => $unit?->name,
            ],
            'transfer' => [
                'id' => $transfer?->id,
                'code' => $transfer?->code,
                'request_date_label' => optional($transfer?->requested_at)->format('d/m/Y H:i')
                    ?: optional($transfer?->request_date)->format('d/m/Y'),
                'completed_at' => optional($transfer?->completed_at)->toDateTimeString(),
                'completed_at_label' => optional($transfer?->completed_at)->format('d/m/Y H:i'),
                'responsible' => $this->formatUserName($transfer?->completedBy) ?: $this->formatUserName($transfer?->requester),
            ],
        ];
    }

    private function loanedDocumentMap(): array
    {
        return LoanDocument::query()
            ->with(['loan.events'])
            ->where('selected_for_loan', true)
            ->where('returned', false)
            ->get()
            ->reduce(function (array $loanedDocuments, LoanDocument $loanDocument): array {
                $documentMapEvent = $loanDocument->loan?->events
                    ->first(fn ($event) => is_array($event->context) && isset($event->context['document_map']));

                foreach ($documentMapEvent?->context['document_map'] ?? [] as $mapping) {
                    if ((int) ($mapping['loan_document_id'] ?? 0) !== (int) $loanDocument->id) {
                        continue;
                    }

                    $transferDocumentId = (int) ($mapping['transfer_document_id'] ?? 0);

                    if ($transferDocumentId > 0) {
                        $loanedDocuments[$transferDocumentId] = [
                            'number' => $loanDocument->loan?->number,
                            'status' => 'Prestado',
                        ];
                    }

                    break;
                }

                return $loanedDocuments;
            }, []);
    }

    private function formatUserName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $person = $user->person;

        if ($person === null) {
            return $user->email;
        }

        $name = trim(collect([
            $person->first_name,
            $person->second_name,
            $person->first_last_name,
            $person->second_last_name,
        ])->filter()->implode(' '));

        return $name !== '' ? $name : $user->email;
    }

    private function mapBox(TransferBox $box): array
    {
        $transfer = $box->transfer;
        $unit = $transfer?->unit;
        $units = $transfer?->units;

        if ($units === null || $units->isEmpty()) {
            $units = $unit ? collect([$unit]) : collect();
        }

        $boxCode = $box->boxCode($transfer?->code);

        return [
            'id' => $box->id,
            'number' => str_pad((string) $box->box_number, 3, '0', STR_PAD_LEFT),
            'box_number' => $box->box_number,
            'box_code' => $boxCode,
            'boxCode' => $boxCode,
            'code' => $boxCode,
            'title' => $box->title,
            'series' => $box->series_name,
            'unit' => [
                'id' => $unit?->id,
                'code' => $unit?->code,
                'name' => $unit?->name,
            ],
            'units' => $units->map(fn (Unit $associatedUnit) => [
                'id' => $associatedUnit->id,
                'code' => $associatedUnit->code,
                'name' => $associatedUnit->name,
            ])->values()->all(),
            'units_label' => $units->pluck('name')->implode('; '),
            'period' => $box->period_label,
            'start_year' => $box->start_year,
            'end_year' => $box->end_year,
            'location' => $box->location_code,
            'documents_count' => $box->documents->count(),
            'can_delete' => $transfer?->view_mode === 'archive',
            'created_at_label' => $box->created_at?->format('d/m/Y H:i'),
            'assigned_by' => $box->assignedBy
                ? trim(collect([
                    $box->assignedBy?->person?->first_name,
                    $box->assignedBy?->person?->first_last_name,
                ])->filter()->implode(' '))
                : null,
            'documents' => $box->documents
                ->map(function (TransferDocument $document) use ($transfer) {
                    $hasDigitalFile = in_array($document->support_type, ['Digital', 'Mixto'], true)
                        && !empty($document->digital_file_name);

                    return [
                        'id' => $document->id,
                        'series' => $document->series_label,
                        'code' => $document->code,
                        'name' => $document->name,
                        'year' => $document->year_label,
                        'support' => $document->support_type,
                        'pages' => $document->pages_label,
                        'created_at_label' => optional($document->created_at)->format('d/m/Y H:i'),
                        'digital_available' => $hasDigitalFile,
                        'digital_file' => $hasDigitalFile ? $document->digital_file_name : null,
                        'digital_url' => $hasDigitalFile && $transfer?->code
                            ? SignedPdfUrl::transferDocument(
                                $transfer->code,
                                SignedPdfUrl::transferDocumentReference($document->id),
                            )
                            : null,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function normalizeArchiveSupport(string $value): string
    {
        $support = trim($value);

        return $support === 'Físico' ? 'Fisico' : $support;
    }

    private function canManageArchive(Request $request): bool
    {
        return in_array($request->user()?->activeProfile()?->name, ['Administrador', 'Usuario UGDA'], true);
    }

    /**
     * Las cajas y documentos del Archivo General se clasifican únicamente
     * con subseries activas, sin filtrar por las unidades asociadas.
     */
    private function archiveClassificationOptions()
    {
        return DocumentarySeries::query()
            ->where('is_active', true)
            ->with(['subseries' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('code')])
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->flatMap(function (DocumentarySeries $series) {
                $seriesLabel = $series->code . ' - ' . $series->name;

                return $series->subseries->map(fn (DocumentarySubseries $subseries) => [
                    'label' => $seriesLabel . ' / ' . $subseries->code . ' - ' . $subseries->name,
                    'value' => 'subseries:' . $subseries->id,
                    'code' => $subseries->code,
                    'name' => $subseries->name,
                    'type' => 'subseries',
                    'series_id' => $series->id,
                    'subseries_id' => $subseries->id,
                ]);
            })
            ->values();
    }

    private function normalizeArchiveClassificationLabel(?string $value): string
    {
        $normalized = Str::ascii(str_replace(['–', '—', '−'], '-', trim((string) $value)));

        return mb_strtolower(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function whereArchiveClassificationLabelMatches(Builder $query, string $label): void
    {
        $normalizedLabel = TextSearch::normalize($label);
        $normalizedLabel = preg_replace('/\s+/', '', $normalizedLabel) ?? '';
        $normalizedColumn = TextSearch::postgresDelimitedExpression('transfer_boxes.series_name');

        $query->whereRaw(
            "POSITION(? IN ('|' || {$normalizedColumn} || '|')) > 0",
            ["|{$normalizedLabel}|"],
        );
    }

    private function normalizeArchiveUnitIds(Request $request): array
    {
        $unitIds = $request->input('unit_ids');

        if (!is_array($unitIds)) {
            $unitIds = $request->filled('unit_id') ? [$request->input('unit_id')] : [];
        }

        return collect($unitIds)
            ->map(fn ($unitId) => (int) $unitId)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function canSearchArchive(Request $request): bool
    {
        return in_array($request->user()?->activeProfile()?->name, ['Administrador', 'Usuario UGDA'], true);
    }

    private function locationOptions(): array
    {
        $offices = \App\Models\PhysicalLocationOffice::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($office) => [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
            ])
            ->values();

        $aisles = \App\Models\PhysicalLocationAisle::query()
            ->with('office:id,name,is_active')
            ->where('is_active', true)
            ->whereHas('office', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'physical_location_office_id', 'name', 'code'])
            ->map(fn ($aisle) => [
                'id' => $aisle->id,
                'office_id' => $aisle->physical_location_office_id,
                'office_name' => $aisle->office?->name,
                'name' => $aisle->name,
                'code' => $aisle->code,
            ])
            ->values();

        $shelves = PhysicalLocationShelf::query()
            ->with('aisle.office:id,name,is_active')
            ->where('is_active', true)
            ->whereHas('aisle', fn ($query) => $query->where('is_active', true)->whereHas('office', fn ($office) => $office->where('is_active', true)))
            ->orderBy('name')
            ->get(['id', 'physical_location_aisle_id', 'name', 'code'])
            ->map(fn (PhysicalLocationShelf $shelf) => [
                'id' => $shelf->id,
                'aisle_id' => $shelf->physical_location_aisle_id,
                'aisle_name' => $shelf->aisle?->name,
                'office_id' => $shelf->aisle?->physical_location_office_id,
                'office_name' => $shelf->aisle?->office?->name,
                'name' => $shelf->name,
                'code' => $shelf->code,
            ])
            ->values();

        return [
            'offices' => $offices,
            'aisles' => $aisles,
            'shelves' => $shelves,
        ];
    }

    private function findValidShelf(int $officeId, int $aisleId, int $shelfId): ?PhysicalLocationShelf
    {
        return PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->where('id', $shelfId)
            ->where('is_active', true)
            ->where('physical_location_aisle_id', $aisleId)
            ->whereHas('aisle', fn ($query) => $query
                ->where('id', $aisleId)
                ->where('is_active', true)
                ->where('physical_location_office_id', $officeId)
                ->whereHas('office', fn ($office) => $office->where('is_active', true)))
            ->first();
    }

    private function buildLocationCode(PhysicalLocationShelf $shelf): string
    {
        $shelf->loadMissing('aisle.office');

        return collect([
            $shelf->aisle?->office?->code,
            $shelf->aisle?->code,
            $shelf->code,
        ])
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->implode('-');
    }
}
