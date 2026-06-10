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
use Illuminate\Http\JsonResponse;
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

    private function availableReportsCount(array $metricValues): int
    {
        return collect($metricValues)
            ->filter(fn ($value) => (int) $value > 0)
            ->count();
    }
}
