<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\RequestStatusCatalog;
use App\Models\TransferDocument;
use App\Services\SystemNotificationService;
use App\Support\RequestNumberGenerator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LoanController extends Controller
{
    public function createCatalog(Request $request): JsonResponse
    {
        if (!$this->canRequesterCreate($request)) {
            return response()->json([
                'message' => 'Solo la unidad solicitante puede crear solicitudes de prestamo.',
            ], 403);
        }

        $user = $request->user()->load(['person', 'units']);
        $activeUnit = $user->units
            ->first(fn ($unit) => (bool) ($unit->pivot->is_active ?? false))
            ?? $user->units->first();

        $documents = TransferDocument::query()
            ->with(['box.transfer.unit', 'box.transfer.workflowStatus'])
            ->whereHas('box.transfer', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->whereHas('workflowStatus', fn ($statusQuery) => $statusQuery->where('code', 'transfer_status_transferred'));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (TransferDocument $document) => [
                'id' => $document->id,
                'title' => $document->name,
                'series' => $document->series_label ?? $document->box?->series_name,
                'box' => 'CAJA-' . str_pad((string) ($document->box?->box_number ?? 0), 3, '0', STR_PAD_LEFT),
                'year' => $document->year_label,
                'unit' => $document->box?->transfer?->unit?->name,
                'transferredAt' => optional($document->box?->transfer?->completed_at ?? $document->box?->transfer?->requested_at)->format('d/m/Y'),
                'support' => $document->support_type,
                'transferNumber' => $document->box?->transfer?->code,
            ])
            ->values();

        return response()->json([
            'requester' => [
                'name' => $this->userName($user),
                'unit_id' => $activeUnit?->id,
                'unit_name' => $activeUnit?->name,
            ],
            'documents' => $documents,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canRequesterCreate($request)) {
            return response()->json([
                'message' => 'Solo la unidad solicitante puede crear solicitudes de prestamo.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'requester_name' => ['required', 'string', 'max:255'],
            'unit_name' => ['required', 'string', 'max:255'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
            'system_documents' => ['nullable', 'array'],
            'system_documents.*.id' => ['required_with:system_documents', 'integer'],
            'system_documents.*.document_type' => ['required_with:system_documents', 'in:Copia,Original'],
            'additional_documents' => ['nullable', 'array'],
            'additional_documents.*.name' => ['required_with:additional_documents', 'string', 'max:255'],
            'additional_documents.*.year' => ['nullable', 'string', 'max:60'],
            'additional_documents.*.quantity' => ['nullable', 'string', 'max:120'],
            'additional_documents.*.unit' => ['nullable', 'string', 'max:255'],
            'additional_documents.*.observation' => ['nullable', 'string', 'max:1000'],
            'additional_documents.*.document_type' => ['required_with:additional_documents', 'in:Copia,Original'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la solicitud de prestamo no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $systemDocuments = collect($request->input('system_documents', []))
            ->filter(fn ($document) => filled($document['id'] ?? null))
            ->values();
        $selectedIds = ($systemDocuments->isNotEmpty()
            ? $systemDocuments->pluck('id')
            : collect($request->input('document_ids', [])))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $systemDocumentTypes = $systemDocuments
            ->mapWithKeys(fn ($document) => [(int) $document['id'] => $document['document_type'] ?? 'Copia']);
        $additionalDocuments = collect($request->input('additional_documents', []))
            ->filter(fn ($document) => filled($document['name'] ?? null))
            ->values();

        if ($selectedIds->isEmpty() && $additionalDocuments->isEmpty()) {
            return response()->json([
                'message' => 'Debe seleccionar o agregar al menos un documento.',
            ], 422);
        }

        try {
            $user = $request->user()->load('units');
            $activeUnit = $user->units()
                ->wherePivot('is_active', true)
                ->first()
                ?? $user->units()->first();

            if ($activeUnit === null) {
                return response()->json([
                    'message' => 'El usuario no tiene una unidad solicitante asignada.',
                ], 422);
            }

            $transferDocuments = TransferDocument::query()
                ->with(['box.transfer.unit'])
                ->whereIn('id', $selectedIds)
                ->whereHas('box.transfer', function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->whereHas('workflowStatus', fn ($statusQuery) => $statusQuery->where('code', 'transfer_status_transferred'));
                })
                ->get();

            if ($transferDocuments->count() !== $selectedIds->count()) {
                return response()->json([
                    'message' => 'Uno o mas documentos seleccionados no estan disponibles para prestamo.',
                ], 422);
            }

            $loan = DB::transaction(function () use ($request, $user, $activeUnit, $transferDocuments, $systemDocumentTypes, $additionalDocuments) {
                $authPending = $this->statusId('loan_auth_pending');
                $statusPending = $this->statusId('loan_status_pending');
                $requestedAt = now();

                $loan = Loan::query()->create([
                    'number' => RequestNumberGenerator::loan($requestedAt),
                    'user_id' => $user->id,
                    'unit_id' => $activeUnit->id,
                    'requested_at' => $requestedAt,
                    'authorization_status_id' => $authPending,
                    'workflow_status_id' => $statusPending,
                    'view_mode' => 'detail',
                    'description' => 'Solicitud de prestamo documental creada por unidad solicitante.',
                ]);

                foreach ($transferDocuments->values() as $index => $document) {
                    $documentType = $systemDocumentTypes->get($document->id, 'Copia');

                    $loan->documents()->create([
                        'document_kind' => 'system',
                        'group_title' => 'Documentos del Sistema (' . $transferDocuments->count() . ')',
                        'title' => $document->name,
                        'series_label' => $document->series_label,
                        'box_code' => 'CAJA-' . str_pad((string) ($document->box?->box_number ?? 0), 3, '0', STR_PAD_LEFT),
                        'year_label' => $document->year_label,
                        'unit_name_snapshot' => $document->box?->transfer?->unit?->name,
                        'document_type_label' => $documentType,
                        'document_type_tone' => $documentType === 'Original' ? 'warning' : 'info',
                        'sort_order' => $index + 1,
                    ]);
                }

                foreach ($additionalDocuments as $index => $document) {
                    $documentType = $document['document_type'] ?? 'Copia';

                    $loan->documents()->create([
                        'document_kind' => 'additional',
                        'group_title' => 'Documentos Adicionales (' . $additionalDocuments->count() . ')',
                        'title' => $document['name'],
                        'series_label' => null,
                        'box_code' => null,
                        'year_label' => $document['year'] ?? null,
                        'unit_name_snapshot' => $document['unit'] ?? null,
                        'document_type_label' => $documentType,
                        'document_type_tone' => $documentType === 'Original' ? 'warning' : 'info',
                        'quantity_label' => $document['quantity'] ?? null,
                        'note' => $document['observation'] ?? null,
                        'sort_order' => $transferDocuments->count() + $index + 1,
                    ]);
                }

                $loan->events()->create([
                    'status_catalog_id' => $statusPending,
                    'actor_user_id' => $user->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Creado',
                    'description' => 'Solicitud de prestamo creada por ' . $request->string('requester_name')->toString(),
                    'context' => [
                        'system_documents' => $transferDocuments->count(),
                        'additional_documents' => $additionalDocuments->count(),
                    ],
                    'occurred_at' => now(),
                ]);

                return $loan;
            });

            app(SystemNotificationService::class)->loanCreated($loan->fresh(['unit']), $request->user());

            return response()->json([
                'message' => 'Solicitud de prestamo registrada correctamente.',
                'number' => $loan->number,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar la solicitud de prestamo.',
            ], 500);
        }
    }

    public function authorizeLoan(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de autorizacion no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comments = trim($request->string('comments')->toString());

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_authorized',
            eventTitle: 'Autorizado UGDA',
            description: $comments !== ''
                ? 'Solicitud autorizada por UGDA para iniciar busqueda de documentos. Comentario: ' . $comments
                : 'Solicitud autorizada por UGDA para iniciar busqueda de documentos.',
            updater: fn (Loan $loan) => [
                'ugda_authorized_by_user_id' => $request->user()->id,
                'ugda_authorized_at' => now(),
            ],
            context: $comments !== '' ? ['comments' => $comments] : null,
        );
    }

    public function pendingAuthorization(Request $request): JsonResponse
    {
        if (!$this->canUnitDirectorAuthorize($request)) {
            return response()->json([
                'message' => 'Solo el Director/Jefe de Unidad puede revisar estas solicitudes.',
            ], 403);
        }

        $unitIds = $this->userUnitIds($request);

        $loans = Loan::query()
            ->with(['requester.person', 'unit', 'authorizationStatus', 'workflowStatus', 'documents'])
            ->whereIn('unit_id', $unitIds)
            ->whereHas('authorizationStatus', fn ($query) => $query->where('code', 'loan_auth_pending'))
            ->whereHas('workflowStatus', fn ($query) => $query->where('code', 'loan_status_pending'))
            ->orderByDesc('requested_at')
            ->get();

        $today = now()->toDateString();

        return response()->json([
            'summary' => [
                'pending' => $loans->count(),
                'today' => $loans->filter(fn (Loan $loan) => $loan->requested_at?->toDateString() === $today)->count(),
                'units' => $loans->pluck('unit_id')->filter()->unique()->count(),
            ],
            'items' => $loans->map(fn (Loan $loan) => [
                'number' => $loan->number,
                'unit' => $loan->unit?->name,
                'responsible' => $this->userName($loan->requester) ?: 'Sin responsable',
                'requested_at' => $loan->requested_at?->format('d/m/Y H:i'),
                'auth' => $loan->authorizationStatus?->label ?? 'Pendiente',
                'status' => $loan->workflowStatus?->label ?? 'Pendiente',
                'documents_count' => $loan->documents->count(),
            ])->values(),
        ]);
    }

    public function authorizeByUnit(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de autorizacion no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->unitStateChange(
            request: $request,
            number: $number,
            authCode: 'loan_auth_authorized',
            workflowCode: 'loan_status_pending',
            eventTitle: 'Autorizado por jefatura',
            description: trim($request->string('comments')->toString()) !== ''
                ? 'Solicitud autorizada por jefatura de unidad. Comentario: ' . trim($request->string('comments')->toString())
                : 'Solicitud autorizada por jefatura de unidad.',
            updater: fn () => [
                'authorized_by_user_id' => $request->user()->id,
                'authorized_at' => now(),
                'view_mode' => 'manage',
            ],
            context: trim($request->string('comments')->toString()) !== ''
                ? ['comments' => trim($request->string('comments')->toString()), 'decision_scope' => 'unit']
                : ['decision_scope' => 'unit'],
        );
    }

    public function denyByUnit(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe ingresar el motivo de rechazo.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->unitStateChange(
            request: $request,
            number: $number,
            authCode: 'loan_auth_denied',
            workflowCode: 'loan_status_denied',
            eventTitle: 'Denegado por jefatura',
            description: 'Solicitud denegada por jefatura de unidad. Motivo: ' . $request->string('reason')->toString(),
            eventType: 'decision',
            context: [
                'reason_label' => 'Motivo de denegación:',
                'reason' => $request->string('reason')->toString(),
                'decision_scope' => 'unit',
            ],
        );
    }

    public function observe(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe ingresar la observación de la solicitud.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_observed',
            eventTitle: 'Observación UGDA',
            description: $request->string('message')->toString(),
            eventType: 'observation'
        );
    }

    public function deny(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe ingresar el motivo de rechazo.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_denied',
            eventTitle: 'Denegado por UGDA',
            description: 'Solicitud denegada por UGDA. Motivo: ' . $request->string('reason')->toString(),
            eventType: 'decision',
            context: [
                'reason_label' => 'Motivo de rechazo:',
                'reason' => $request->string('reason')->toString(),
                'decision_scope' => 'ugda',
            ]
        );
    }

    public function startSearch(Request $request, string $number): JsonResponse
    {
        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_authorized',
            eventTitle: 'Busqueda en proceso',
            description: 'UGDA ha iniciado el proceso de busqueda y localizacion fisica de los documentos solicitados.',
            updater: fn (Loan $loan) => [
                'search_status_id' => $this->statusId('loan_search_in_progress'),
                'search_started_by_user_id' => $request->user()->id,
                'search_started_at' => now(),
            ]
        );
    }

    public function finishSearch(Request $request, string $number): JsonResponse
    {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la busqueda no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('document_ids', []))->map(fn ($id) => (int) $id)->all();
                $foundDocuments = $selectedIds !== [];
                $searchStatusCode = $foundDocuments ? 'loan_search_completed' : 'loan_search_not_found';
                $eventTitle = $foundDocuments ? 'Busqueda finalizada' : 'Busqueda no encontrados';
                $eventDescription = $foundDocuments
                    ? 'La busqueda de los documentos ha sido completada. Documentos listos para retiro.'
                    : 'Los documentos solicitados no fueron encontrados en el archivo.';

                $loan->documents()->update(['found_in_search' => false]);

                if ($foundDocuments) {
                    $loan->documents()->whereIn('id', $selectedIds)->update(['found_in_search' => true]);
                }

                $loan->update([
                    'search_status_id' => $this->statusId($searchStatusCode),
                    'search_completed_by_user_id' => $request->user()->id,
                    'search_completed_at' => now(),
                    'search_comments' => $request->input('comments'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($searchStatusCode),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => $eventTitle,
                    'description' => $eventDescription,
                    'context' => [
                        'comments' => $request->input('comments'),
                        'found_documents' => count($selectedIds),
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->loanSearchFinished($loan->fresh(), $request->user());

            return response()->json(['message' => 'Busqueda finalizada correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo finalizar la busqueda.',
            ], 500);
        }
    }

    public function registerLoan(Request $request, string $number): JsonResponse
    {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer'],
            'loan_date' => ['required', 'date_format:d/m/Y'],
            'due_date' => ['required', 'date_format:d/m/Y'],
            'received_by' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del prestamo no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('selected_ids'))->map(fn ($id) => (int) $id)->all();
                $dispatch = $loan->dispatches()->create([
                    'loan_date' => Carbon::createFromFormat('d/m/Y', $request->string('loan_date')->toString())->toDateString(),
                    'due_date' => Carbon::createFromFormat('d/m/Y', $request->string('due_date')->toString())->toDateString(),
                    'received_by_name' => $request->string('received_by')->toString(),
                    'delivered_by_user_id' => $request->user()->id,
                    'observations' => $request->input('observations'),
                ]);

                foreach ($selectedIds as $documentId) {
                    $dispatch->items()->create(['loan_document_id' => $documentId]);
                }

                $loan->documents()->whereIn('id', $selectedIds)->update([
                    'selected_for_loan' => true,
                ]);

                $loan->update([
                    'workflow_status_id' => $this->statusId('loan_status_loaned'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId('loan_status_loaned'),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Prestado',
                    'description' => 'Documentos entregados a ' . $request->string('received_by')->toString() . '. Fecha de devolucion: ' . $request->string('due_date')->toString(),
                    'context' => null,
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->loanRegistered($loan->fresh(), $request->user());

            return response()->json(['message' => 'Prestamo registrado correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar el prestamo.',
            ], 500);
        }
    }

    public function registerReturn(Request $request, string $number): JsonResponse
    {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer'],
            'return_date' => ['required', 'date_format:d/m/Y'],
            'condition' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la devolucion no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('selected_ids'))->map(fn ($id) => (int) $id)->all();
                $return = $loan->returns()->create([
                    'return_date' => Carbon::createFromFormat('d/m/Y', $request->string('return_date')->toString())->toDateString(),
                    'received_by_user_id' => $request->user()->id,
                    'condition_label' => $request->string('condition')->toString(),
                    'observations' => $request->input('observations'),
                ]);

                foreach ($selectedIds as $documentId) {
                    $return->items()->create(['loan_document_id' => $documentId]);
                }

                $loan->documents()->whereIn('id', $selectedIds)->update([
                    'returned' => true,
                ]);

                $hasPending = $loan->documents()->where('selected_for_loan', true)->where('returned', false)->exists();
                $loan->update([
                    'workflow_status_id' => $this->statusId($hasPending ? 'loan_status_loaned' : 'loan_status_returned'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($hasPending ? 'loan_status_loaned' : 'loan_status_returned'),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Devuelto',
                    'description' => 'Documentos devueltos por ' . $this->userName($request->user()) . '. ' . $request->string('condition')->toString(),
                    'context' => null,
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->loanReturned($loan->fresh(), $request->user());

            return response()->json(['message' => 'Devolucion registrada correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar la devolucion.',
            ], 500);
        }
    }

    private function simpleStateChange(
        Request $request,
        string $number,
        string $workflowCode,
        string $eventTitle,
        string $description,
        ?callable $updater = null,
        string $eventType = 'status',
        ?array $context = null
    ): JsonResponse {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        try {
            $loan = Loan::query()->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan, $workflowCode, $eventTitle, $description, $updater, $eventType, $context) {
                $attributes = ['workflow_status_id' => $this->statusId($workflowCode)];

                if ($updater !== null) {
                    $attributes = array_merge($attributes, $updater($loan));
                }

                $loan->update($attributes);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($workflowCode),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => $eventType,
                    'title' => $eventTitle,
                    'description' => $description,
                    'context' => $context,
                    'occurred_at' => now(),
                ]);
            });

            $notificationService = app(SystemNotificationService::class);
            $freshLoan = $loan->fresh();

            if ($eventTitle === 'Busqueda en proceso') {
                $notificationService->loanSearchStarted($freshLoan, $request->user());
            } else {
                $notificationService->loanStatusChanged($freshLoan, $request->user(), $workflowCode, $description);
            }

            return response()->json(['message' => 'Estado del prestamo actualizado correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar el prestamo.',
            ], 500);
        }
    }

    private function unitStateChange(
        Request $request,
        string $number,
        string $authCode,
        string $workflowCode,
        string $eventTitle,
        string $description,
        ?callable $updater = null,
        string $eventType = 'status',
        ?array $context = null
    ): JsonResponse {
        if (!$this->canUnitDirectorAuthorize($request)) {
            return response()->json([
                'message' => 'Solo el Director/Jefe de Unidad puede realizar esta accion.',
            ], 403);
        }

        try {
            $loan = Loan::query()
                ->with(['authorizationStatus', 'workflowStatus'])
                ->where('number', $number)
                ->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            if (!in_array($loan->unit_id, $this->userUnitIds($request), true)) {
                return response()->json([
                    'message' => 'La solicitud no pertenece a su unidad.',
                ], 403);
            }

            if ($loan->authorizationStatus?->code !== 'loan_auth_pending' || $loan->workflowStatus?->code !== 'loan_status_pending') {
                return response()->json([
                    'message' => 'La solicitud ya no se encuentra pendiente de autorizacion.',
                ], 422);
            }

            DB::transaction(function () use ($request, $loan, $authCode, $workflowCode, $eventTitle, $description, $updater, $eventType, $context) {
                $attributes = [
                    'authorization_status_id' => $this->statusId($authCode),
                    'workflow_status_id' => $this->statusId($workflowCode),
                ];

                if ($updater !== null) {
                    $attributes = array_merge($attributes, $updater($loan));
                }

                $loan->update($attributes);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($workflowCode),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => $eventType,
                    'title' => $eventTitle,
                    'description' => $description,
                    'context' => $context,
                    'occurred_at' => now(),
                ]);
            });

            $notificationService = app(SystemNotificationService::class);
            $freshLoan = $loan->fresh(['unit']);

            if ($authCode === 'loan_auth_authorized') {
                $notificationService->loanAuthorizedByUnit($freshLoan, $request->user());
            } else {
                $notificationService->loanStatusChanged($freshLoan, $request->user(), $workflowCode, $description);
            }

            return response()->json(['message' => 'Solicitud de prestamo actualizada correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar la solicitud de prestamo.',
            ], 500);
        }
    }

    private function statusId(string $code): ?int
    {
        return RequestStatusCatalog::query()->where('code', $code)->value('id');
    }

    private function canRequesterCreate(Request $request): bool
    {
        return $request->user()?->activeProfile()?->name === 'Unidad Solicitante';
    }

    private function canUnitDirectorAuthorize(Request $request): bool
    {
        return $request->user()?->activeProfile()?->name === 'Director/Jefe de Unidad';
    }

    private function canUgdaManage(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $profileName = $user->activeProfile()?->name;

        if (in_array($profileName, ['Administrador', 'Usuario UGDA'], true)) {
            return true;
        }

        return $user->units()
            ->where('units.code', 'UGDA')
            ->exists();
    }

    private function userUnitIds(Request $request): array
    {
        return $request->user()
            ? $request->user()->units()->pluck('units.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    private function userName($user): string
    {
        $parts = array_filter([
            $user->person?->first_name,
            $user->person?->second_name,
            $user->person?->first_last_name,
            $user->person?->second_last_name,
        ]);

        return implode(' ', $parts);
    }
}
