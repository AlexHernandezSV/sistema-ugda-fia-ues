<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\PhysicalLocationShelf;
use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ArchiveController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el administrador puede gestionar el archivo general.',
            ], 403);
        }

        try {
            $nextBoxNumber = ((int) TransferBox::query()->max('box_number')) + 1;

            return response()->json([
                'next_box_number' => $nextBoxNumber,
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
                'series' => DocumentarySeries::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'code', 'name'])
                    ->map(fn (DocumentarySeries $series) => [
                        'label' => $series->name,
                        'value' => $series->id,
                        'code' => $series->code,
                    ])
                    ->values(),
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
                'message' => 'Solo el administrador puede consultar las cajas del archivo general.',
            ], 403);
        }

        try {
            $term = trim((string) $request->query('q', ''));

            $boxesQuery = TransferBox::query()
                ->with(['documents', 'transfer.unit', 'assignedBy.person'])
                ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'));

            if ($term !== '') {
                $boxesQuery->where(function (Builder $query) use ($term) {
                    $this->addSearchClauses($query, [
                        'transfer_boxes.series_name',
                        'transfer_boxes.box_number',
                        'transfer_boxes.title',
                        'transfer_boxes.period_label',
                        'transfer_boxes.location_code',
                        'transfer_boxes.content_description',
                    ], $term);

                    $query->orWhereHas('transfer.unit', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, ['units.code', 'units.name'], $term);
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

            $boxes = $boxesQuery
                ->orderByDesc('transfer_boxes.created_at')
                ->limit(150)
                ->get()
                ->map(fn (TransferBox $box) => $this->mapBox($box))
                ->values();

            return response()->json([
                'query' => $term,
                'count' => $boxes->count(),
                'boxes' => $boxes,
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
                'message' => 'Solo el administrador puede crear cajas en el archivo general.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'box_number' => ['required', 'integer', 'min:1'],
            'unit_id' => ['required', 'exists:units,id'],
            'series_id' => ['required', 'exists:documentary_series,id'],
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
            'existing_document_ids' => ['nullable', 'array'],
            'existing_document_ids.*' => ['integer', 'exists:transfer_box_documents,id'],
        ], [
            'box_number.required' => 'El numero de caja es obligatorio.',
            'unit_id.required' => 'Debe seleccionar la unidad productora.',
            'series_id.required' => 'Debe seleccionar la serie documental.',
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

        $selectedShelf = $this->findValidShelf(
            (int) $request->input('office_id'),
            (int) $request->input('aisle_id'),
            (int) $request->input('shelf_id')
        );

        if ($selectedShelf === null) {
            return response()->json([
                'message' => 'La ubicacion fisica seleccionada no respeta la relacion oficina, pasillo y estante.',
            ], 422);
        }

        try {
            $box = DB::transaction(function () use ($request, $selectedShelf) {
                $series = DocumentarySeries::query()->findOrFail((int) $request->input('series_id'));
                $now = now();
                $transferredStatus = RequestStatusCatalog::query()
                    ->where('code', 'transfer_status_transferred')
                    ->value('id');
                $authorizedStatus = RequestStatusCatalog::query()
                    ->where('code', 'transfer_auth_authorized')
                    ->value('id');

                $transfer = Transfer::query()->create([
                    'code' => $this->nextArchiveTransferCode(),
                    'user_id' => $request->user()->id,
                    'unit_id' => (int) $request->input('unit_id'),
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

                $boxNumber = (int) $request->input('box_number');
                $startYear = (int) $request->input('start_year');
                $endYear = (int) $request->input('end_year');
                $locationCode = $this->buildLocationCode($selectedShelf);

                $box = $transfer->boxes()->create([
                    'series_name' => $series->name,
                    'start_year' => $startYear,
                    'end_year' => $endYear,
                    'box_number' => $boxNumber,
                    'title' => 'Caja #' . str_pad((string) $boxNumber, 3, '0', STR_PAD_LEFT),
                    'period_label' => '01/01/' . $startYear . ' - 31/12/' . $endYear,
                    'location_code' => $locationCode,
                    'assigned_by_user_id' => $request->user()->id,
                    'assigned_at' => $now,
                    'content_description' => $series->name,
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
                    $box->documents()->create([
                        'code' => $documentPayload['code'] ?? 'DOC-' . $transfer->code . '-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT),
                        'name' => $documentPayload['name'],
                        'series_label' => $documentPayload['series'] ?? $series->name,
                        'support_type' => $documentPayload['support'] ?? 'Fisico',
                        'year_label' => $documentPayload['year'] ?? null,
                        'pages_label' => $documentPayload['pages'] ?? null,
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
                        'location_code' => $locationCode,
                    ],
                    'occurred_at' => $now,
                ]);

                return $box->fresh(['documents', 'transfer.unit', 'assignedBy.person']);
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

    public function destroyBox(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el administrador puede eliminar cajas del archivo general.',
            ], 403);
        }

        try {
            $box = TransferBox::query()->with('transfer')->findOrFail($id);

            if ($box->transfer?->view_mode !== 'archive') {
                return response()->json([
                    'message' => 'Solo se pueden eliminar cajas creadas directamente desde Archivo General.',
                ], 422);
            }

            $box->transfer?->delete();

            return response()->json([
                'message' => 'Caja eliminada correctamente.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo eliminar la caja.',
            ], 500);
        }
    }

    public function documents(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el administrador puede consultar el archivo general.',
            ], 403);
        }

        try {
            $term = trim((string) $request->query('q', ''));
            $searchDate = $this->parseSearchDate($term);

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

            if ($term === '') {
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
                ])
                ->where(function (Builder $query) use ($term, $searchDate) {
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
                            'transfer_boxes.series_name',
                            'transfer_boxes.box_number',
                            'transfer_boxes.title',
                            'transfer_boxes.period_label',
                            'transfer_boxes.location_code',
                            'transfer_boxes.content_description',
                        ], $term);

                        if ($searchDate !== null) {
                            $boxQuery->orWhereDate('transfer_boxes.assigned_at', $searchDate);
                        }
                    });

                    $query->orWhereHas('box.transfer', function (Builder $transferQuery) use ($term, $searchDate) {
                        $this->addSearchClauses($transferQuery, [
                            'transfers.code',
                            'transfers.status',
                            'transfers.request_date',
                            'transfers.requested_at',
                            'transfers.completed_at',
                            'transfers.description',
                            'transfers.observation',
                        ], $term);

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

            $resultsCount = (clone $resultsQuery)->count();
            $results = $resultsQuery
                ->orderBy('transfer_box_documents.name')
                ->limit(100)
                ->get()
                ->map(fn (TransferDocument $document) => $this->mapDocument($document))
                ->values();

            return response()->json([
                'totals' => $totals,
                'query' => $term,
                'results_count' => $resultsCount,
                'results_limit' => 100,
                'results' => $results,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar el archivo general.',
            ], 500);
        }
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
        $like = '%' . mb_strtolower($term) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}("LOWER(CAST({$column} AS TEXT)) LIKE ?", [$like]);
        }
    }

    private function mapDocument(TransferDocument $document): array
    {
        $box = $document->box;
        $transfer = $box?->transfer;
        $unit = $transfer?->unit;
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
            'digital_available' => !empty($document->digital_file_name),
            'digital_file' => $document->digital_file_name,
            'digital_url' => !empty($document->digital_file_name) && $transfer?->code && $document->code
                ? '/api/transfers/' . $transfer->code . '/documents/' . $document->code . '/download'
                : null,
            'box' => [
                'id' => $box?->id,
                'number' => $boxNumber,
                'title' => $box?->title,
                'period' => $box?->period_label,
                'start_year' => $box?->start_year,
                'end_year' => $box?->end_year,
                'location' => $box?->location_code,
                'series' => $box?->series_name,
                'content_description' => $box?->content_description,
                'assigned_at_label' => $box?->assigned_at?->format('d/m/Y'),
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
                'request_date_label' => $transfer?->request_date?->format('d/m/Y'),
                'completed_at' => $transfer?->completed_at?->toDateTimeString(),
                'completed_at_label' => $transfer?->completed_at?->format('d/m/Y'),
                'responsible' => $this->formatUserName($transfer?->completedBy) ?: $this->formatUserName($transfer?->requester),
            ],
        ];
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

        return [
            'id' => $box->id,
            'number' => str_pad((string) $box->box_number, 3, '0', STR_PAD_LEFT),
            'box_number' => $box->box_number,
            'title' => $box->title,
            'series' => $box->series_name,
            'unit' => [
                'id' => $unit?->id,
                'code' => $unit?->code,
                'name' => $unit?->name,
            ],
            'period' => $box->period_label,
            'start_year' => $box->start_year,
            'end_year' => $box->end_year,
            'location' => $box->location_code,
            'documents_count' => $box->documents->count(),
            'can_delete' => $transfer?->view_mode === 'archive',
            'created_at_label' => $box->created_at?->format('d/m/Y'),
            'assigned_by' => $box->assignedBy
                ? trim(collect([
                    $box->assignedBy?->person?->first_name,
                    $box->assignedBy?->person?->first_last_name,
                ])->filter()->implode(' '))
                : null,
            'documents' => $box->documents
                ->map(fn (TransferDocument $document) => [
                    'id' => $document->id,
                    'series' => $document->series_label,
                    'code' => $document->code,
                    'name' => $document->name,
                    'year' => $document->year_label,
                    'support' => $document->support_type,
                    'pages' => $document->pages_label,
                ])
                ->values()
                ->all(),
        ];
    }

    private function canManageArchive(Request $request): bool
    {
        return $request->user()?->activeProfile()?->name === 'Administrador';
    }

    private function nextArchiveTransferCode(): string
    {
        $year = now()->format('Y');
        $prefix = 'AG-' . $year . '-';
        $last = Transfer::query()
            ->where('code', 'like', $prefix . '%')
            ->pluck('code')
            ->map(fn ($code) => (int) str_replace($prefix, '', (string) $code))
            ->max();

        return $prefix . str_pad((string) (($last ?? 0) + 1), 4, '0', STR_PAD_LEFT);
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
