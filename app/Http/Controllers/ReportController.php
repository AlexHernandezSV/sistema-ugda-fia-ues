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
