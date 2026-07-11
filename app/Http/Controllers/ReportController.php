<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use App\Models\Loan;
use App\Models\LoanDocument;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ReportController extends Controller
{
    private const ACCESS_HISTORY_STATUSES = [
        'pending' => 'Pendiente',
        'authorized' => 'Autorizado',
        'observed' => 'Observado',
        'returned' => 'Devuelto',
    ];

    public function summary(): JsonResponse
    {
        try {
            $documentsCount = TransferDocument::query()->count();
            $transfersCount = Transfer::query()->count();
            $boxesCount = TransferBox::query()->count();
            $unitsCount = Unit::query()->count();
            $loansCount = Loan::query()->count();
            $loanedDocumentsCount = LoanDocument::query()
                ->where('selected_for_loan', true)
                ->where('returned', false)
                ->count();
            $returnedLoansCount = Loan::query()
                ->whereHas('workflowStatus', fn ($query) => $query->where('code', 'loan_status_returned'))
                ->count();
            $seriesCount = DocumentarySeries::query()->count();
            $subseriesCount = DocumentarySubseries::query()->count();
            $locatedBoxesCount = TransferBox::query()
                ->whereNotNull('location_code')
                ->where('location_code', '<>', '')
                ->count();

            return response()->json([
                'categories' => [
                    'entry_and_registry' => $this->availableReportsCount([
                        $transfersCount,
                        $documentsCount,
                        $boxesCount,
                        $unitsCount,
                    ]),
                    'access_and_consultation' => $this->availableReportsCount([
                        $loansCount,
                        $loanedDocumentsCount,
                        $returnedLoansCount,
                    ]),
                    'classification_and_organization' => $this->availableReportsCount([
                        $seriesCount + $subseriesCount,
                        $locatedBoxesCount,
                    ]),
                ],
                'summary' => [
                    'total_documents' => $documentsCount,
                    'total_transfers' => $transfersCount,
                    'total_boxes' => $boxesCount,
                    'reserved_documents' => $loanedDocumentsCount,
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el resumen de reportes.',
            ], 500);
        }
    }

    public function entryRegistry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $baseQuery = TransferDocument::query()
                ->with(['box.transfer.unit', 'box.transfer.requester.person'])
                ->whereNotNull('created_at');

            $documents = $this->applyEntryRegistryFilters(clone $baseQuery, $validated)
                ->orderBy('created_at')
                ->orderBy('code')
                ->get();

            $availableDates = (clone $baseQuery)
                ->select('created_at')
                ->orderBy('created_at')
                ->get()
                ->map(fn (TransferDocument $document) => $document->created_at)
                ->filter();

            $mappedDocuments = $documents
                ->map(fn (TransferDocument $document) => $this->mapEntryRegistryDocument($document))
                ->values();

            $periodSeries = $documents
                ->groupBy(fn (TransferDocument $document) => $document->created_at?->toDateString() ?? 'Sin fecha')
                ->map(fn ($group, string $date) => [
                    'date' => $date,
                    'label' => $date === 'Sin fecha' ? $date : Carbon::parse($date)->format('d/m/Y'),
                    'total' => $group->count(),
                ])
                ->sortBy('date')
                ->values();

            return response()->json([
                'filters' => [
                    'years' => $availableDates
                        ->map(fn ($date) => (int) $date->format('Y'))
                        ->unique()
                        ->sort()
                        ->values(),
                    'months' => $availableDates
                        ->map(fn ($date) => (int) $date->format('n'))
                        ->unique()
                        ->sort()
                        ->values(),
                ],
                'summary' => [
                    'filtered_documents' => $mappedDocuments->count(),
                    'total_documents' => TransferDocument::query()->count(),
                    'units' => $mappedDocuments->pluck('unit')->filter()->unique()->count(),
                    'series' => $mappedDocuments->pluck('series')->filter()->unique()->count(),
                ],
                'charts' => [
                    'period' => $periodSeries,
                    'units' => $this->groupDocumentsForChart($mappedDocuments, 'unit', 'Sin unidad'),
                    'series' => $this->groupDocumentsForChart($mappedDocuments, 'series', 'Sin serie'),
                    'support' => $this->groupDocumentsForChart($mappedDocuments, 'support', 'Sin soporte'),
                ],
                'documents' => $mappedDocuments,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el reporte de ingreso y registro.',
            ], 500);
        }
    }

    public function accessConsultationHistory(Request $request): JsonResponse
    {
        try {
            $loans = Loan::query()
                ->with([
                    'documents',
                    'latestDispatch',
                    'requester.person',
                    'returns.items',
                    'unit',
                    'workflowStatus',
                ])
                ->orderBy('requested_at')
                ->orderBy('number')
                ->get();

            $mappedLoans = $loans
                ->map(fn (Loan $loan) => $this->mapAccessConsultationLoan($loan))
                ->filter()
                ->values();

            return response()->json([
                'filters' => [
                    'statuses' => collect(self::ACCESS_HISTORY_STATUSES)
                        ->map(fn (string $label, string $value) => [
                            'label' => $label,
                            'value' => $value,
                        ])
                        ->values(),
                    'units' => $mappedLoans
                        ->map(fn (array $loan) => [
                            'label' => $loan['unit'],
                            'value' => $loan['unit_id'],
                        ])
                        ->filter(fn (array $unit) => $unit['value'] !== null)
                        ->unique('value')
                        ->sortBy('label')
                        ->values(),
                    'users' => $mappedLoans
                        ->map(fn (array $loan) => [
                            'label' => $loan['user'],
                            'value' => $loan['user_id'],
                        ])
                        ->filter(fn (array $user) => $user['value'] !== null)
                        ->unique('value')
                        ->sortBy('label')
                        ->values(),
                ],
                'summary' => [
                    'filtered_loans' => $mappedLoans->count(),
                    'total_loans' => $mappedLoans->count(),
                    'users' => $mappedLoans->pluck('user_id')->filter()->unique()->count(),
                    'units' => $mappedLoans->pluck('unit_id')->filter()->unique()->count(),
                ],
                'loans' => $mappedLoans,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el historial de consultas por usuario.',
            ], 500);
        }
    }

    public function accessConsultationDocuments(Request $request): JsonResponse
    {
        try {
            $loans = Loan::query()
                ->with([
                    'documents',
                    'latestDispatch',
                    'requester.person',
                    'unit',
                    'workflowStatus',
                ])
                ->whereHas('workflowStatus', fn ($query) => $query->whereIn('code', [
                    'loan_status_authorized',
                    'loan_status_loaned',
                    'loan_status_returned',
                ]))
                ->orderBy('requested_at')
                ->orderBy('number')
                ->get();

            $documents = $loans
                ->flatMap(fn (Loan $loan) => $this->mapAccessConsultationDocumentRows($loan))
                ->values();

            return response()->json([
                'summary' => [
                    'total_documents' => $documents
                        ->pluck('document_key')
                        ->unique()
                        ->count(),
                    'total_consultations' => $documents->count(),
                ],
                'documents' => $documents,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el reporte de documentos consultados.',
            ], 500);
        }
    }

    public function accessConsultationResponseTimes(Request $request): JsonResponse
    {
        try {
            $loans = Loan::query()
                ->with([
                    'documents',
                    'unit',
                ])
                ->whereNotNull('search_started_at')
                ->whereNotNull('search_completed_at')
                ->orderBy('search_started_at')
                ->orderBy('number')
                ->get();

            $rows = $loans
                ->flatMap(fn (Loan $loan) => $this->mapAccessResponseTimeRows($loan))
                ->values();
            $times = $rows->pluck('minutes');

            return response()->json([
                'summary' => [
                    'total_rows' => $rows->count(),
                    'average_minutes' => $times->isNotEmpty() ? (int) round($times->average()) : 0,
                    'minimum_minutes' => $times->isNotEmpty() ? (int) $times->min() : 0,
                    'maximum_minutes' => $times->isNotEmpty() ? (int) $times->max() : 0,
                ],
                'rows' => $rows,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el reporte de tiempos promedio de respuesta.',
            ], 500);
        }
    }

    private function availableReportsCount(array $metricValues): int
    {
        return collect($metricValues)
            ->filter(fn ($value) => (int) $value > 0)
            ->count();
    }

    private function applyEntryRegistryFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['year'])) {
            $query->whereYear('created_at', (int) $filters['year']);
        }

        if (!empty($filters['month'])) {
            $query->whereMonth('created_at', (int) $filters['month']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('created_at', $filters['date']);
        }

        return $query;
    }

    private function mapEntryRegistryDocument(TransferDocument $document): array
    {
        $date = $document->created_at;
        $box = $document->box;
        $transfer = $box?->transfer;

        return [
            'id' => $document->id,
            'code' => $document->code,
            'name' => $document->name,
            'series' => $document->series_label ?: 'Sin serie',
            'unit_id' => $transfer?->unit?->id,
            'unit' => $transfer?->unit?->name ?: 'Sin unidad',
            'entered_by_id' => $transfer?->requester?->id,
            'entered_by' => $this->formatUserName($transfer?->requester),
            'date' => $date?->toDateString(),
            'date_label' => $date?->format('d/m/Y H:i') ?: 'Sin fecha',
            'support' => $document->support_type ?: 'Sin soporte',
            'year' => $document->year_label ?: 'N/D',
            'pages' => $document->pages_label ?: 'N/D',
            'transfer_code' => $transfer?->code ?: 'N/D',
            'box_number' => $box?->formattedBoxNumber() ?: 'N/D',
        ];
    }

    private function groupDocumentsForChart($documents, string $key, string $fallback): array
    {
        return $documents
            ->groupBy(fn (array $document) => $document[$key] ?: $fallback)
            ->map(fn ($group, string $label) => [
                'label' => $label,
                'total' => $group->count(),
            ])
            ->sortByDesc('total')
            ->take(12)
            ->values()
            ->all();
    }

    private function mapAccessConsultationLoan(Loan $loan): ?array
    {
        $statusCode = $this->accessHistoryStatusCode($loan);

        if ($statusCode === null) {
            return null;
        }

        $returnedDocumentIds = $loan->returns
            ->flatMap(fn ($return) => $return->items->pluck('loan_document_id'))
            ->unique()
            ->values();
        $selectedDocuments = $this->accessConsultationDocumentsForLoan($loan);

        if ($selectedDocuments->isEmpty()) {
            return null;
        }

        return [
            'id' => $loan->id,
            'number' => $loan->number,
            'user_id' => $loan->requester?->id,
            'user' => $this->formatUserName($loan->requester),
            'user_initials' => $this->initialsFromName($this->formatUserName($loan->requester)),
            'unit_id' => $loan->unit?->id,
            'unit' => $loan->unit?->name ?: 'Sin unidad',
            'status_code' => $statusCode,
            'status' => self::ACCESS_HISTORY_STATUSES[$statusCode],
            'requested_at' => $loan->requested_at?->toDateString(),
            'requested_at_label' => $loan->requested_at?->format('d/m/Y H:i') ?: 'Sin fecha',
            'documents_count' => $selectedDocuments->count(),
            'documents' => $selectedDocuments
                ->map(fn (LoanDocument $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'series' => $document->series_label ?: $document->group_title,
                    'date_label' => $loan->latestDispatch?->loan_date?->format('Y-m-d')
                        ?: $loan->requested_at?->format('Y-m-d')
                        ?: 'Sin fecha',
                    'status' => $returnedDocumentIds->contains($document->id) || $document->returned
                        ? 'Devuelta'
                        : 'Prestada',
                ])
                ->values(),
        ];
    }

    private function mapAccessConsultationDocumentRows(Loan $loan)
    {
        $consultationDate = $loan->latestDispatch?->loan_date ?: $loan->requested_at;

        return $this->accessConsultationDocumentsForLoan($loan)
            ->map(fn (LoanDocument $document) => [
                'id' => "{$loan->id}-{$document->id}",
                'document_key' => mb_strtolower(trim($document->title)),
                'title' => $document->title,
                'series' => $document->series_label ?: $document->group_title,
                'loan_number' => $loan->number,
                'user' => $this->formatUserName($loan->requester),
                'unit' => $loan->unit?->name ?: 'Sin unidad',
                'date' => $consultationDate?->toDateString(),
                'date_label' => $consultationDate?->format('d/m/Y') ?: 'Sin fecha',
            ]);
    }

    private function accessConsultationDocumentsForLoan(Loan $loan)
    {
        $archiveDocumentNames = $this->archiveDocumentNames();
        $selectedDocuments = $loan->documents
            ->filter(fn (LoanDocument $document) => $document->selected_for_loan || $document->found_in_search)
            ->filter(fn (LoanDocument $document) => $this->loanDocumentExistsInArchive($document, $archiveDocumentNames));

        if ($selectedDocuments->isNotEmpty()) {
            return $selectedDocuments;
        }

        return $loan->documents
            ->filter(fn (LoanDocument $document) => $this->loanDocumentExistsInArchive($document, $archiveDocumentNames));
    }

    private function archiveDocumentNames()
    {
        static $names = null;

        if ($names !== null) {
            return $names;
        }

        $names = TransferDocument::query()
            ->whereHas('box.transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
            ->pluck('name')
            ->map(fn (?string $name) => $this->normalizeDocumentTitle($name))
            ->filter()
            ->flip();

        return $names;
    }

    private function loanDocumentExistsInArchive(LoanDocument $document, $archiveDocumentNames): bool
    {
        if ($document->document_kind !== 'system') {
            return false;
        }

        return $archiveDocumentNames->has($this->normalizeDocumentTitle($document->title));
    }

    private function normalizeDocumentTitle(?string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower((string) $title)));
    }

    private function mapAccessResponseTimeRows(Loan $loan)
    {
        $startedAt = $loan->search_started_at;
        $completedAt = $loan->search_completed_at;

        if ($startedAt === null || $completedAt === null) {
            return collect();
        }

        $minutes = max(0, (int) round($startedAt->diffInMinutes($completedAt)));

        return $this->accessConsultationDocumentsForLoan($loan)
            ->map(fn (LoanDocument $document) => [
                'id' => "{$loan->id}-{$document->id}",
                'request' => $loan->number,
                'document' => $document->title,
                'unit' => $loan->unit?->name ?: 'Sin unidad',
                'started_at' => $startedAt->toDateString(),
                'started_at_label' => $startedAt->format('Y-m-d'),
                'completed_at' => $completedAt->toDateString(),
                'completed_at_label' => $completedAt->format('Y-m-d'),
                'minutes' => $minutes,
            ]);
    }

    private function accessHistoryStatusCode(Loan $loan): ?string
    {
        return match ($loan->workflowStatus?->code) {
            'loan_status_pending' => 'pending',
            'loan_status_authorized', 'loan_status_loaned' => 'authorized',
            'loan_status_observed' => 'observed',
            'loan_status_returned' => 'returned',
            default => null,
        };
    }

    private function initialsFromName(string $name): string
    {
        $words = collect(explode(' ', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)));

        return $words->implode('') ?: 'SU';
    }

    private function formatUserName(?User $user): string
    {
        if ($user === null) {
            return 'Sin usuario';
        }

        $person = $user->person;
        $name = collect([
            $person?->first_name,
            $person?->second_name,
            $person?->first_last_name,
            $person?->second_last_name,
        ])
            ->filter()
            ->implode(' ');

        return $name !== '' ? $name : $user->email;
    }
}
