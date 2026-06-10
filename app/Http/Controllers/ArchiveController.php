<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ArchiveController extends Controller
{
    public function documents(Request $request): JsonResponse
    {
        if ($request->user()?->activeProfile()?->name !== 'Administrador') {
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
                ->with(['box.transfer.unit'])
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
            'box' => [
                'id' => $box?->id,
                'number' => $boxNumber,
                'title' => $box?->title,
                'period' => $box?->period_label,
                'location' => $box?->location_code,
                'series' => $box?->series_name,
            ],
            'unit' => [
                'id' => $unit?->id,
                'code' => $unit?->code,
                'name' => $unit?->name,
            ],
            'transfer' => [
                'id' => $transfer?->id,
                'code' => $transfer?->code,
                'completed_at' => $transfer?->completed_at?->toDateTimeString(),
                'completed_at_label' => $transfer?->completed_at?->format('d/m/Y'),
            ],
        ];
    }
}
