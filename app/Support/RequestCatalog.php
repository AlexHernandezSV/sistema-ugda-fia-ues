<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\Transfer;
use App\Models\TransferDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RequestCatalog
{
    public static function listings(?User $viewer = null): array
    {
        $transferQuery = Transfer::query()
            ->with(['requester.person', 'unit', 'authorizationStatus', 'workflowStatus'])
            ->orderByDesc('requested_at');

        self::scopeToVisibleUnits($transferQuery, $viewer);

        $transfers = $transferQuery
            ->get()
            ->map(fn (Transfer $transfer) => self::mapTransferListing($transfer))
            ->values()
            ->all();

        $loanQuery = Loan::query()
            ->with(['requester.person', 'unit', 'authorizationStatus', 'workflowStatus', 'searchStatus'])
            ->orderByDesc('requested_at');

        self::scopeToVisibleUnits($loanQuery, $viewer);

        $loans = $loanQuery
            ->get()
            ->map(fn (Loan $loan) => self::mapLoanListing($loan))
            ->values()
            ->all();

        $units = collect(array_merge(
            array_column($transfers, 'unit'),
            array_column($loans, 'unit')
        ))->filter()->unique()->values()->all();

        return [
            'transfers' => $transfers,
            'loans' => $loans,
            'units' => $units,
        ];
    }

    public static function transferDetail(string $number, ?User $viewer = null): ?array
    {
        $transfer = Transfer::query()
            ->with([
                'requester.person',
                'unit',
                'authorizationStatus',
                'workflowStatus',
                'authorizedBy.person',
                'completedBy.person',
                'boxes.documents',
                'boxes.assignedBy.person',
                'events.actor.person',
                'events.status',
            ])
            ->where('code', $number)
            ->first();

        if ($transfer === null) {
            return null;
        }

        if (!self::viewerCanAccessRequestUnit($viewer, $transfer->unit_id)) {
            return null;
        }

        $observations = $transfer->events
            ->where('event_type', 'observation')
            ->sortBy('occurred_at')
            ->values();

        $latestDecision = $transfer->events
            ->where('event_type', 'decision')
            ->sortByDesc('occurred_at')
            ->first();
        $latestSchedule = $transfer->events
            ->filter(fn ($event) => $event->status?->code === 'transfer_status_scheduled')
            ->sortByDesc('occurred_at')
            ->first();
        $latestObservation = $observations->sortByDesc('occurred_at')->first();
        $latestCorrection = $transfer->events
            ->filter(fn ($event) => $event->title === 'Corregido y reenviado')
            ->sortByDesc('occurred_at')
            ->first();

        $workflowCode = $transfer->workflowStatus?->code;
        $authCode = $transfer->authorizationStatus?->code;
        $viewerProfile = $viewer?->activeProfile()?->name;
        $viewerUnitIds = $viewer
            ? $viewer->units()->pluck('units.id')->all()
            : [];
        $isUnitDirectorReview = $authCode === 'transfer_auth_pending'
            && $workflowCode === 'transfer_status_pending'
            && $viewerProfile === 'Director/Jefe de Unidad'
            && in_array($transfer->unit_id, $viewerUnitIds, true);
        $isUgdaReview = $authCode === 'transfer_auth_authorized'
            && $workflowCode === 'transfer_status_pending'
            && self::viewerCanManageUgda($viewer);
        $canCorrectObserved = $workflowCode === 'transfer_status_observed'
            && self::viewerCanCorrectObservedTransfer($viewer, $transfer);
        $isReview = $isUnitDirectorReview || $isUgdaReview;
        $isScheduled = $workflowCode === 'transfer_status_scheduled';
        $mode = $isUnitDirectorReview ? 'unitAuthorization' : ($isUgdaReview ? 'review' : 'detail');

        return [
            'number' => $transfer->code,
            'unit' => $transfer->unit?->name,
            'responsible' => self::userDisplayName($transfer->requester),
            'receivedAt' => self::formatDateTime($transfer->requested_at),
            'auth' => $transfer->authorizationStatus?->label ?? 'Pendiente',
            'status' => $transfer->workflowStatus?->label ?? 'Pendiente',
            'pageTitle' => ($isReview || $isScheduled) ? 'Revisar Transferencia Documental' : 'Transferencia #' . $transfer->code,
            'pageSubtitle' => $isReview
                ? 'Solicitud #' . $transfer->code . ' - ' . ($transfer->unit?->name ?? 'Sin unidad')
                : ($isScheduled
                    ? 'Solicitud #' . $transfer->code . ' - ' . ($transfer->unit?->name ?? 'Sin unidad')
                    : 'Detalle de la solicitud'),
            'mode' => $mode,
            'showPrintCard' => (bool) $transfer->show_print_card,
            'boxDisplayState' => $transfer->box_display_state ?: 'collapsed',
            'authorizedBy' => self::userDisplayName($transfer->authorizedBy),
            'authorizedAt' => self::formatDateTime($transfer->authorized_at),
            'description' => $transfer->description,
            'completion' => $workflowCode === 'transfer_status_transferred'
                ? [
                    'date' => self::formatDate($transfer->completed_at),
                    'time' => self::formatTime($transfer->completed_at),
                    'completedBy' => self::userDisplayName($transfer->completedBy),
                    'message' => 'El proceso de transferencia fisica ha sido completado exitosamente',
                ]
                : null,
            'scheduledSummary' => $isScheduled && $latestSchedule
                ? [
                    'title' => 'Fecha de Entrega Programada',
                    'description' => 'La transferencia esta agendada para su entrega fisica',
                    'deliveryDate' => self::formatDate($transfer->scheduled_for),
                    'deliveryTime' => self::formatTime($transfer->scheduled_for),
                    'comments' => $latestSchedule->context['comments'] ?? 'Sin comentarios adicionales.',
                    'scheduledBy' => self::eventActorName($latestSchedule),
                    'scheduledAt' => self::formatDateTime($latestSchedule->occurred_at),
                ]
                : null,
            'processCard' => $isScheduled && self::viewerCanManageUgda($viewer)
                ? [
                    'title' => 'Proceso de Transferencia',
                    'description' => 'Asigne ubicaciones fisicas a las cajas de documentos para su resguardo en UGDA',
                    'buttonLabel' => 'Iniciar proceso de transferencia',
                ]
                : null,
            'workflowNotice' => $isUnitDirectorReview
                ? [
                    'title' => 'Solicitud pendiente de autorizacion',
                    'description' => 'Esta transferencia debe ser autorizada por la jefatura de la unidad productora antes de pasar a gestion UGDA.',
                ]
                : ($isUgdaReview && $workflowCode === 'transfer_status_pending'
                ? [
                    'title' => 'Solicitud pendiente de gestion',
                    'description' => 'Esta solicitud ha sido autorizada por la jefatura de unidad y requiere gestion de la UGDA para continuar el proceso.',
                ]
                : null),
            'observationsRegistered' => $workflowCode === 'transfer_status_observed' && $observations->isNotEmpty()
                ? [
                    'count' => $observations->count(),
                    'title' => 'Observaciones Registradas',
                    'description' => 'Esta solicitud tiene ' . $observations->count() . ' observacion(es) registrada(s)',
                    'canCorrect' => $canCorrectObserved,
                    'actionLabel' => 'Editar solicitud y corregir observaciones',
                    'items' => $observations->map(fn ($event) => [
                        'actor' => self::eventActorName($event),
                        'dateTime' => self::formatDateTime($event->occurred_at),
                        'message' => $event->description,
                    ])->values()->all(),
                ]
                : null,
            'resolvedObservation' => $workflowCode === 'transfer_status_pending'
                && $latestObservation
                && $latestCorrection
                && $latestCorrection->occurred_at?->greaterThan($latestObservation->occurred_at)
                ? [
                    'title' => 'Observacion resuelta',
                    'description' => 'La unidad solicitante corrigio la solicitud y la reenvio a jefatura para autorizacion.',
                    'observedBy' => self::eventActorName($latestObservation),
                    'observedAt' => self::formatDateTime($latestObservation->occurred_at),
                    'observation' => $latestObservation->description,
                    'resolvedBy' => self::eventActorName($latestCorrection),
                    'resolvedAt' => self::formatDateTime($latestCorrection->occurred_at),
                ]
                : null,
            'decisionCard' => $workflowCode === 'transfer_status_denied' && $latestDecision
                ? self::mapTransferDecisionCard($latestDecision)
                : null,
            'documentSummary' => $isReview ? self::buildTransferDocumentSummary($transfer) : null,
            'boxes' => $transfer->boxes->map(fn ($box) => self::mapTransferBox($transfer->code, $box))->values()->all(),
            'actions' => $isUnitDirectorReview
                ? self::transferUnitAuthorizationActions()
                : ($isUgdaReview ? self::transferReviewActions($workflowCode) : []),
            'history' => self::mapHistory($transfer->events),
        ];
    }

    public static function loanDetail(string $number, ?User $viewer = null): ?array
    {
        $loan = Loan::query()
            ->with([
                'requester.person',
                'unit',
                'authorizationStatus',
                'workflowStatus',
                'searchStatus',
                'authorizedBy.person',
                'ugdaAuthorizedBy.person',
                'searchStartedBy.person',
                'searchCompletedBy.person',
                'documents',
                'events.actor.person',
                'events.status',
                'latestDispatch.deliveredBy.person',
                'latestDispatch.items.document',
                'latestReturn.receivedBy.person',
                'latestReturn.items.document',
            ])
            ->where('number', $number)
            ->first();

        if ($loan === null) {
            return null;
        }

        if (!self::viewerCanAccessRequestUnit($viewer, $loan->unit_id)) {
            return null;
        }

        $observations = $loan->events->where('event_type', 'observation')->sortBy('occurred_at')->values();
        $latestDecision = $loan->events->where('event_type', 'decision')->sortByDesc('occurred_at')->first();
        $workflowCode = $loan->workflowStatus?->code;
        $authCode = $loan->authorizationStatus?->code;
        $searchCode = $loan->searchStatus?->code;
        $canManageUgda = self::viewerCanManageUgda($viewer);
        $isManage = $loan->view_mode === 'manage'
            && $canManageUgda
            && $authCode === 'loan_auth_authorized';
        $viewerProfile = $viewer?->activeProfile()?->name;
        $viewerUnitIds = $viewer
            ? $viewer->units()->pluck('units.id')->all()
            : [];
        $isUnitDirectorReview = $authCode === 'loan_auth_pending'
            && $workflowCode === 'loan_status_pending'
            && $viewerProfile === 'Director/Jefe de Unidad'
            && in_array($loan->unit_id, $viewerUnitIds, true);

        return [
            'number' => $loan->number,
            'unit' => $loan->unit?->name,
            'applicant' => self::userDisplayName($loan->requester),
            'requestedBy' => self::userDisplayName($loan->requester),
            'requestedAt' => self::formatDateTime($loan->requested_at),
            'auth' => $loan->authorizationStatus?->label ?? 'Pendiente',
            'status' => $loan->workflowStatus?->label ?? 'Pendiente',
            'search' => $loan->searchStatus?->label ? str_replace('Busqueda: ', '', $loan->searchStatus->label) : '-',
            'pageTitle' => $isUnitDirectorReview ? 'Revisar Solicitud de Prestamo' : ($isManage ? 'Gestionar Prestamo Documental' : 'Prestamo #' . $loan->number),
            'pageSubtitle' => $isUnitDirectorReview
                ? 'Solicitud #' . $loan->number . ' - ' . ($loan->unit?->name ?? 'Sin unidad')
                : ($isManage
                ? 'Solicitud #' . $loan->number . ' - ' . ($loan->unit?->name ?? 'Sin unidad')
                : 'Detalle de la solicitud'),
            'mode' => $isUnitDirectorReview ? 'unitAuthorization' : ($isManage ? 'manage' : 'detail'),
            'generalSectionTitle' => ($isManage || $isUnitDirectorReview) ? 'Datos Generales' : 'Informacion General',
            'documentsSectionTitle' => $workflowCode === 'loan_status_loaned' ? 'Documentos Prestados' : 'Documentos Solicitados',
            'authorizedBy' => self::userDisplayName($loan->authorizedBy),
            'authorizedAt' => self::formatDateTime($loan->authorized_at),
            'chips' => self::loanChips($loan),
            'workflowNotice' => $isUnitDirectorReview
                ? [
                    'title' => 'Solicitud de prestamo pendiente de autorizacion',
                    'description' => 'Revise los documentos solicitados antes de tomar una decision.',
                ]
                : ($isManage && $workflowCode === 'loan_status_pending'
                ? [
                    'title' => 'Solicitud de prestamo pendiente de gestion',
                    'description' => 'Esta solicitud ha sido autorizada por el Director y requiere su gestion.',
                ]
                : null),
            'observationsRegistered' => $observations->isNotEmpty()
                ? [
                    'count' => $observations->count(),
                    'title' => 'Observaciones Registradas',
                    'description' => 'Esta solicitud tiene ' . $observations->count() . ' observacion(es) registrada(s)',
                    'items' => $observations->map(fn ($event) => [
                        'actor' => self::eventActorName($event),
                        'dateTime' => self::formatDateTime($event->occurred_at),
                        'message' => $event->description,
                    ])->values()->all(),
                ]
                : null,
            'searchProgress' => $isManage && $searchCode === 'loan_search_in_progress'
                ? [
                    'title' => 'Busqueda En proceso',
                    'description' => 'Los documentos estan siendo localizados en el archivo',
                ]
                : null,
            'searchCompleted' => $isManage && in_array($searchCode, ['loan_search_completed', 'loan_search_not_found'], true) && $workflowCode === 'loan_status_authorized'
                ? self::buildLoanSearchCompleted($loan)
                : null,
            'loanSummary' => $workflowCode === 'loan_status_loaned'
                ? self::buildLoanSummary($loan)
                : null,
            'returnSummary' => $workflowCode === 'loan_status_returned'
                ? self::buildLoanReturnSummary($loan)
                : null,
            'decisionCard' => $latestDecision ? self::mapLoanDecisionCard($latestDecision) : null,
            'documentGroups' => $searchCode === 'loan_search_not_found' ? [] : self::mapLoanDocumentGroups($loan),
            'actions' => $isUnitDirectorReview ? self::loanUnitAuthorizationActions() : ($isManage ? self::loanActions($loan) : []),
            'loanRegistration' => $isManage ? self::buildLoanRegistrationConfig($loan) : null,
            'returnRegistration' => $isManage ? self::buildReturnRegistrationConfig($loan) : null,
            'history' => self::mapHistory($loan->events),
        ];
    }

    public static function transferDocumentDownload(string $number, string $code, ?User $viewer = null): ?array
    {
        $document = TransferDocument::query()
            ->where('code', $code)
            ->whereHas('box.transfer', fn ($query) => $query->where('code', $number))
            ->with('box.transfer')
            ->first();

        if ($document === null || empty($document->digital_file_name)) {
            return null;
        }

        if (!self::viewerCanAccessRequestUnit($viewer, $document->box?->transfer?->unit_id)) {
            return null;
        }

        return [
            'code' => $document->code,
            'name' => $document->name,
            'series' => $document->series_label,
            'support' => $document->support_type,
            'year' => $document->year_label,
            'pages' => $document->pages_label,
            'digitalFile' => $document->digital_file_name,
            'digitalPath' => $document->digital_file_path,
            'boxNumber' => str_pad((string) $document->box?->box_number, 3, '0', STR_PAD_LEFT),
        ];
    }

    public static function dashboard(User $user): array
    {
        $profile = $user->activeProfile()?->name;
        $activeUnit = $user->units()->wherePivot('is_active', true)->first() ?? $user->units()->first();
        $activeUnitName = $activeUnit?->name;

        $listings = self::listings($user);
        $transfers = collect($listings['transfers'])->map(fn ($item) => ['type' => 'Transferencia'] + $item);
        $loans = collect($listings['loans'])->map(fn ($item) => ['type' => 'Prestamo'] + $item);
        $all = $transfers->concat($loans)->values();

        $alerts = match ($profile) {
            'Director/Jefe de Unidad' => $all
                ->filter(fn ($item) => $item['auth'] === 'Pendiente' && ($activeUnitName === null || $item['unit'] === $activeUnitName))
                ->values(),
            'Unidad Solicitante' => $all
                ->filter(fn ($item) => in_array($item['status'], ['Pendiente', 'Observado'], true) && ($activeUnitName === null || $item['unit'] === $activeUnitName))
                ->values(),
            default => $all
                ->filter(fn ($item) => ($item['type'] === 'Transferencia' && $item['auth'] === 'Autorizado' && in_array($item['status'], ['Agendado', 'Pendiente', 'Observado'], true))
                    || ($item['type'] === 'Prestamo' && ($item['search'] === 'En proceso' || in_array($item['status'], ['Pendiente', 'Observado'], true))))
                ->values(),
        };

        $stats = [
            [
                'title' => 'Total de Solicitudes',
                'value' => (string) $all->count(),
                'icon' => 'pi pi-file',
                'color' => 'text-blue-500',
                'bg' => 'bg-blue-50',
            ],
            [
                'title' => 'Autorizadas',
                'value' => (string) $all->where('auth', 'Autorizado')->count(),
                'icon' => 'pi pi-check-circle',
                'color' => 'text-green-500',
                'bg' => 'bg-green-50',
            ],
            [
                'title' => 'Pendientes',
                'value' => (string) $alerts->count(),
                'icon' => 'pi pi-clock',
                'color' => 'text-gray-500',
                'bg' => 'bg-gray-100',
            ],
            [
                'title' => 'Denegadas',
                'value' => (string) $all->where('auth', 'Denegado')->count(),
                'icon' => 'pi pi-times-circle',
                'color' => 'text-red-500',
                'bg' => 'bg-red-50',
            ],
        ];

        $activeUnits = $all
            ->groupBy('unit')
            ->map(function (Collection $items, string $unit) use ($all) {
                $count = $items->count();
                $percent = max(15, (int) round(($count / max(1, $all->count())) * 100));

                return [
                    'name' => $unit,
                    'count' => $count,
                    'percent' => min($percent, 100),
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->all();

        $recentRequests = $all
            ->map(function ($item) {
                $rawDate = $item['receivedAt'] ?? $item['requestedAt'];
                $date = Carbon::createFromFormat('d/m/Y H:i', $rawDate);

                return [
                    'id' => '#' . $item['number'],
                    'user' => $item['responsible'] ?? $item['applicant'],
                    'date' => $rawDate,
                    'status' => $item['status'],
                    'type' => $item['type'],
                    'sort_date' => $date,
                ];
            })
            ->sortByDesc('sort_date')
            ->take(4)
            ->map(fn ($item) => collect($item)->except('sort_date')->all())
            ->values()
            ->all();

        return [
            'stats' => $stats,
            'alerts' => [
                'count' => $alerts->count(),
                'title' => match ($profile) {
                    'Director/Jefe de Unidad' => 'solicitudes pendientes de autorizacion',
                    'Unidad Solicitante' => 'solicitudes pendientes de seguimiento',
                    default => 'solicitudes pendientes de gestion',
                },
                'description' => match ($profile) {
                    'Director/Jefe de Unidad' => 'Las siguientes solicitudes requieren tu revision y autorizacion:',
                    'Unidad Solicitante' => 'Las siguientes solicitudes requieren tu atencion o seguimiento:',
                    default => 'Las siguientes solicitudes requieren gestion desde UGDA:',
                },
                'items' => $alerts->map(fn ($item) => [
                    'type' => $item['type'],
                    'number' => $item['number'],
                    'title' => $item['type'] . ' #' . $item['number'],
                    'subtitle' => $item['unit'] . ' · ' . ($item['responsible'] ?? $item['applicant']),
                    'route' => $item['type'] === 'Transferencia'
                        ? '/solicitudes/transferencias/' . $item['number']
                        : '/solicitudes/prestamos/' . $item['number'],
                ])->values()->all(),
            ],
            'active_units' => $activeUnits,
            'recent_requests' => $recentRequests,
        ];
    }

    private static function mapTransferListing(Transfer $transfer): array
    {
        return [
            'number' => $transfer->code,
            'responsible' => self::userDisplayName($transfer->requester),
            'unit' => $transfer->unit?->name,
            'receivedAt' => self::formatDateTime($transfer->requested_at),
            'auth' => $transfer->authorizationStatus?->label ?? 'Pendiente',
            'status' => $transfer->workflowStatus?->label ?? 'Pendiente',
        ];
    }

    private static function mapLoanListing(Loan $loan): array
    {
        return [
            'number' => $loan->number,
            'applicant' => self::userDisplayName($loan->requester),
            'unit' => $loan->unit?->name,
            'requestedAt' => self::formatDateTime($loan->requested_at),
            'auth' => $loan->authorizationStatus?->label ?? 'Pendiente',
            'status' => $loan->workflowStatus?->label ?? 'Pendiente',
            'search' => $loan->searchStatus?->label ? str_replace('Busqueda: ', '', $loan->searchStatus->label) : '-',
        ];
    }

    private static function buildTransferDocumentSummary(Transfer $transfer): array
    {
        $rows = $transfer->boxes->map(fn ($box, $index) => [
            'index' => $index + 1,
            'series' => $box->series_name,
            'startYear' => (string) $box->start_year,
            'endYear' => (string) $box->end_year,
            'boxNumber' => (string) $box->box_number,
            'documentsLabel' => $box->documents->count() . ' doc(s)',
        ])->values()->all();

        $totalDocuments = $transfer->boxes->sum(fn ($box) => $box->documents->count());

        return [
            'rows' => $rows,
            'totalBoxes' => $transfer->boxes->count(),
            'totalDocuments' => $totalDocuments,
            'summaryText' => $totalDocuments . ' documentos distribuidos en ' . $transfer->boxes->count() . ' cajas',
            'boxesTitle' => 'Documentos por Caja',
        ];
    }

    private static function mapTransferBox(string $transferNumber, $box): array
    {
        $number = str_pad((string) $box->box_number, 3, '0', STR_PAD_LEFT);

        return [
            'number' => $number,
            'title' => $box->title ?: 'Caja #' . $number,
            'period' => $box->period_label ?: self::buildPeriodLabel($box->start_year, $box->end_year),
            'documentsCount' => $box->documents->count(),
            'physicalLocation' => $box->location_code,
            'isLocated' => !empty($box->location_code),
            'documents' => $box->documents->map(fn ($document) => [
                'code' => $document->code,
                'name' => $document->name,
                'series' => $document->series_label,
                'support' => $document->support_type,
                'year' => $document->year_label,
                'pages' => $document->pages_label,
                'digitalFile' => $document->digital_file_name,
                'digitalAvailable' => !empty($document->digital_file_name),
                'digitalUrl' => $document->digital_file_name ? '/api/transfers/' . $transferNumber . '/documents/' . $document->code . '/download' : null,
            ])->values()->all(),
        ];
    }

    private static function mapTransferDecisionCard($event): array
    {
        $context = $event->context ?? [];
        $scope = $context['decision_scope'] ?? 'ugda';

        return [
            'variant' => 'danger',
            'title' => $scope === 'unit' ? 'Solicitud Denegada' : 'Solicitud Rechazada',
            'subtitle' => $scope === 'unit'
                ? 'La solicitud fue denegada por el director/jefe de la unidad productora'
                : 'La solicitud fue rechazada por UGDA',
            'actor' => self::eventActorName($event),
            'dateTime' => self::formatDateTime($event->occurred_at),
            'reasonLabel' => $context['reason_label'] ?? 'Motivo:',
            'reason' => $context['reason'] ?? $event->description,
            'icon' => 'pi pi-times-circle',
        ];
    }

    private static function buildLoanSearchCompleted(Loan $loan): array
    {
        $groups = $loan->documents
            ->where('found_in_search', true)
            ->groupBy('group_title')
            ->map(fn ($documents, $title) => [
                'title' => $title,
                'items' => $documents->map(fn ($document) => [
                    'title' => $document->title,
                    'meta' => collect([$document->series_label, $document->box_code ? 'Caja ' . $document->box_code : null])->filter()->implode(' • '),
                ])->values()->all(),
            ])
            ->values()
            ->all();
        $isNotFound = $loan->searchStatus?->code === 'loan_search_not_found';

        return [
            'title' => $isNotFound ? 'Busqueda No encontrados' : 'Busqueda Finalizada',
            'description' => $isNotFound
                ? 'Los documentos solicitados no fueron encontrados'
                : ($groups ? 'Algunos documentos fueron encontrados' : 'No se encontraron documentos disponibles'),
            'completedBy' => self::userDisplayName($loan->searchCompletedBy),
            'completedAt' => self::formatDateTime($loan->search_completed_at),
            'comments' => $isNotFound ? $loan->search_comments : ($loan->search_comments ?: 'Sin comentarios adicionales.'),
            'groups' => $groups,
            'result' => $isNotFound ? 'not_found' : 'completed',
            'tone' => $isNotFound ? 'danger' : 'warning',
        ];
    }

    private static function buildLoanSummary(Loan $loan): ?array
    {
        $dispatch = $loan->latestDispatch;

        if ($dispatch === null) {
            return null;
        }

        $groups = $dispatch->items
            ->map(fn ($item) => $item->document)
            ->filter()
            ->groupBy('group_title')
            ->map(fn ($documents, $title) => [
                'title' => $title,
                'items' => $documents->map(fn ($document) => [
                    'title' => $document->title,
                    'meta' => collect([$document->series_label, $document->box_code ? 'Caja ' . $document->box_code : null, $document->year_label])->filter()->implode(' • '),
                    'documentType' => $document->document_type_label,
                    'documentTypeColor' => $document->document_type_tone,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'title' => 'Documentos en Prestamo',
            'description' => 'Los documentos fueron entregados y deben ser devueltos en la fecha indicada.',
            'loanDate' => self::formatDate($dispatch->loan_date),
            'dueDate' => self::formatDate($dispatch->due_date),
            'responsible' => $dispatch->received_by_name,
            'receivedBy' => $dispatch->received_by_name,
            'deliveredBy' => self::userDisplayName($dispatch->deliveredBy),
            'groups' => $groups,
            'observations' => $dispatch->observations,
        ];
    }

    private static function buildLoanReturnSummary(Loan $loan): ?array
    {
        $return = $loan->latestReturn;

        if ($return === null) {
            return null;
        }

        return [
            'title' => 'Documentos Devueltos',
            'description' => 'El prestamo ha sido completado exitosamente',
            'returnDate' => self::formatDate($return->return_date),
            'returnTime' => self::formatTime($return->created_at),
            'receivedBy' => self::userDisplayName($return->receivedBy),
            'condition' => $return->condition_label,
            'observations' => $return->observations,
        ];
    }

    private static function mapLoanDecisionCard($event): array
    {
        $context = $event->context ?? [];
        $scope = $context['decision_scope'] ?? 'ugda';

        return [
            'title' => $scope === 'unit' ? 'Solicitud Denegada' : 'Solicitud Rechazada',
            'subtitle' => $scope === 'unit'
                ? 'La solicitud fue denegada por el jefe/director de la unidad solicitante'
                : 'La solicitud fue rechazada por UGDA',
            'actor' => self::eventActorName($event),
            'dateTime' => self::formatDateTime($event->occurred_at),
            'reasonLabel' => $context['reason_label'] ?? 'Motivo:',
            'reason' => $context['reason'] ?? $event->description,
            'icon' => 'pi pi-times-circle',
        ];
    }

    private static function mapLoanDocumentGroups(Loan $loan): array
    {
        return $loan->documents
            ->groupBy('group_title')
            ->map(function ($documents, $title) {
                $kind = $documents->first()->document_kind;

                return [
                    'kind' => $kind,
                    'title' => $title,
                    'items' => $documents->map(fn ($document) => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'series' => $document->series_label,
                        'box' => $document->box_code,
                        'year' => $document->year_label,
                        'unit' => $document->unit_name_snapshot,
                        'documentType' => $document->document_type_label,
                        'documentTypeColor' => $document->document_type_tone,
                        'quantity' => $document->quantity_label,
                        'note' => $document->note,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private static function loanActions(Loan $loan): array
    {
        $workflowCode = $loan->workflowStatus?->code;
        $searchCode = $loan->searchStatus?->code;

        if ($loan->view_mode !== 'manage') {
            return [];
        }

        if ($workflowCode === 'loan_status_pending') {
            return [
                ['key' => 'authorize', 'label' => 'Autorizar prestamo', 'icon' => 'pi pi-check-circle', 'variant' => 'success'],
                ['key' => 'observe', 'label' => 'Observar solicitud', 'icon' => 'pi pi-eye', 'variant' => 'warning'],
                ['key' => 'deny', 'label' => 'Denegar prestamo', 'icon' => 'pi pi-times-circle', 'variant' => 'danger'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($workflowCode === 'loan_status_authorized' && $searchCode === null) {
            return [
                ['key' => 'startSearch', 'label' => 'Iniciar busqueda de documentos', 'icon' => 'pi pi-search', 'variant' => 'primary'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($searchCode === 'loan_search_in_progress') {
            return [
                ['key' => 'finishSearch', 'label' => 'Finalizar busqueda', 'icon' => 'pi pi-check-circle', 'variant' => 'primary'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($searchCode === 'loan_search_not_found') {
            return [
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($workflowCode === 'loan_status_authorized' && $searchCode === 'loan_search_completed') {
            return [
                ['key' => 'registerLoan', 'label' => 'Registrar prestamo', 'icon' => 'pi pi-box', 'variant' => 'success'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($workflowCode === 'loan_status_loaned') {
            return [
                ['key' => 'registerReturn', 'label' => 'Registrar devolucion', 'icon' => 'pi pi-check-circle', 'variant' => 'muted'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        return [
            ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
        ];
    }

    private static function loanUnitAuthorizationActions(): array
    {
        return [
            ['key' => 'authorizeUnit', 'label' => 'Autorizar prestamo', 'icon' => 'pi pi-check-circle', 'variant' => 'success'],
            ['key' => 'denyUnit', 'label' => 'Denegar prestamo', 'icon' => 'pi pi-times-circle', 'variant' => 'danger'],
            ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
        ];
    }

    private static function buildLoanRegistrationConfig(Loan $loan): ?array
    {
        if ($loan->workflowStatus?->code !== 'loan_status_authorized' || $loan->searchStatus?->code !== 'loan_search_completed') {
            return null;
        }

        $documents = $loan->documents
            ->where('found_in_search', true)
            ->where('selected_for_loan', false)
            ->values();

        return [
            'title' => 'Registrar Prestamo de Documentos',
            'subtitle' => 'Seleccione los documentos que se estan prestando e ingrese los detalles',
            'helperTitle' => 'Seleccione los documentos a prestar',
            'helperText' => 'Solo se muestran los documentos que fueron encontrados en la busqueda',
            'selectAllLabel' => 'Seleccionar todos',
            'documentsTitle' => $documents->first()->group_title ?? 'Documentos encontrados',
            'selectionSummary' => 'Documentos seleccionados para prestamo: {count} de {total}',
            'documents' => $documents->map(fn ($document) => [
                'id' => $document->id,
                'title' => $document->title,
                'series' => $document->series_label,
                'box' => $document->box_code,
                'year' => $document->year_label,
                'documentType' => $document->document_type_label,
                'documentTypeColor' => $document->document_type_tone,
            ])->values()->all(),
        ];
    }

    private static function buildReturnRegistrationConfig(Loan $loan): ?array
    {
        if ($loan->workflowStatus?->code !== 'loan_status_loaned') {
            return null;
        }

        $documents = $loan->documents
            ->where('selected_for_loan', true)
            ->where('returned', false)
            ->values();

        return [
            'title' => 'Registrar Devolucion de Documentos',
            'subtitle' => 'Seleccione los documentos que se estan devolviendo e ingrese los detalles',
            'helperTitle' => 'Seleccione los documentos que se devuelven',
            'helperText' => 'Marque los documentos que el usuario esta devolviendo en este momento',
            'selectAllLabel' => 'Seleccionar todos',
            'documentsTitle' => $documents->first()->group_title ?? 'Documentos prestados',
            'selectionSummary' => 'Documentos seleccionados para devolucion: {count} de {total}',
            'defaultCondition' => 'Documentos devueltos en buen estado',
            'documents' => $documents->map(fn ($document) => [
                'id' => $document->id,
                'title' => $document->title,
                'series' => $document->series_label,
                'box' => $document->box_code,
                'year' => $document->year_label,
                'documentType' => $document->document_type_label,
                'documentTypeColor' => $document->document_type_tone,
            ])->values()->all(),
        ];
    }

    private static function mapHistory(Collection $events): array
    {
        return $events
            ->sortByDesc('occurred_at')
            ->values()
            ->map(fn ($event) => [
                'status' => $event->title,
                'dateTime' => self::formatDateTime($event->occurred_at),
                'description' => $event->description,
                'icon' => self::historyIcon($event->event_type),
            ])
            ->all();
    }

    private static function historyIcon(string $eventType): string
    {
        return match ($eventType) {
            'decision' => 'pi pi-times-circle',
            'observation' => 'pi pi-eye',
            default => 'pi pi-check-circle',
        };
    }

    private static function transferReviewActions(?string $workflowCode): array
    {
        if ($workflowCode !== 'transfer_status_pending') {
            return [];
        }

        return [
            ['key' => 'schedule', 'label' => 'Programar entrega', 'icon' => 'pi pi-calendar', 'variant' => 'primary'],
            ['key' => 'observe', 'label' => 'Observar transferencia', 'icon' => 'pi pi-eye', 'variant' => 'warning'],
            ['key' => 'deny', 'label' => 'Denegar transferencia', 'icon' => 'pi pi-times-circle', 'variant' => 'danger'],
            ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
        ];
    }

    private static function transferUnitAuthorizationActions(): array
    {
        return [
            ['key' => 'authorizeUnit', 'label' => 'Autorizar transferencia', 'icon' => 'pi pi-check-circle', 'variant' => 'success'],
            ['key' => 'denyUnit', 'label' => 'Denegar transferencia', 'icon' => 'pi pi-times-circle', 'variant' => 'danger'],
            ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
        ];
    }

    private static function loanChips(Loan $loan): array
    {
        $chips = [
            ['label' => $loan->authorizationStatus?->label ?? 'Pendiente', 'tone' => $loan->authorizationStatus?->tone ?? 'neutral'],
            ['label' => $loan->workflowStatus?->label ?? 'Pendiente', 'tone' => $loan->workflowStatus?->tone ?? 'warning'],
        ];

        if ($loan->searchStatus) {
            $chips[] = ['label' => $loan->searchStatus->label, 'tone' => $loan->searchStatus->tone];
        }

        return $chips;
    }

    private static function viewerCanManageUgda(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        $profile = $viewer->activeProfile()?->name;

        if (in_array($profile, ['Administrador', 'Usuario UGDA'], true)) {
            return true;
        }

        return $viewer->units()
            ->where('units.code', 'UGDA')
            ->exists();
    }

    private static function viewerUnitIds(?User $viewer): array
    {
        return $viewer
            ? $viewer->units()->pluck('units.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    private static function scopeToVisibleUnits($query, ?User $viewer): void
    {
        if (self::viewerCanManageUgda($viewer)) {
            return;
        }

        $unitIds = self::viewerUnitIds($viewer);

        if ($unitIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn('unit_id', $unitIds);
    }

    private static function viewerCanAccessRequestUnit(?User $viewer, ?int $unitId): bool
    {
        if (self::viewerCanManageUgda($viewer)) {
            return true;
        }

        if ($unitId === null) {
            return false;
        }

        return in_array((int) $unitId, self::viewerUnitIds($viewer), true);
    }

    private static function viewerCanCorrectObservedTransfer(?User $viewer, Transfer $transfer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->activeProfile()?->name !== 'Unidad Solicitante') {
            return false;
        }

        return $viewer->units()
            ->where('units.id', $transfer->unit_id)
            ->exists();
    }

    private static function userDisplayName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $parts = array_filter([
            $user->person?->first_name,
            $user->person?->second_name,
            $user->person?->first_last_name,
            $user->person?->second_last_name,
        ]);

        return implode(' ', $parts);
    }

    private static function eventActorName($event): ?string
    {
        return self::userDisplayName($event->actor) ?: ($event->actor_name_snapshot ?? null);
    }

    private static function formatDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->format('d/m/Y H:i');
    }

    private static function formatDate($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->format('d/m/Y');
    }

    private static function formatTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->format('H:i');
    }

    private static function buildPeriodLabel(?int $startYear, ?int $endYear): ?string
    {
        if ($startYear === null && $endYear === null) {
            return null;
        }

        if ($startYear === $endYear) {
            return '01/01/' . $startYear . ' - 31/12/' . $endYear;
        }

        return '01/01/' . $startYear . ' - 31/12/' . $endYear;
    }
}
