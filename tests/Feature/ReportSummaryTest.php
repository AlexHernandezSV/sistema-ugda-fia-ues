<?php

namespace Tests\Feature;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use App\Models\Loan;
use App\Models\LoanDocument;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_user_with_reports_permission_can_get_dynamic_summary_from_database(): void
    {
        $user = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $documentsCount = TransferDocument::count();
        $transfersCount = Transfer::count();
        $boxesCount = TransferBox::count();
        $loanedDocumentsCount = LoanDocument::query()
            ->where('selected_for_loan', true)
            ->where('returned', false)
            ->count();
        $returnedLoansCount = Loan::query()
            ->whereHas('workflowStatus', fn ($query) => $query->where('code', 'loan_status_returned'))
            ->count();
        $seriesCount = DocumentarySeries::count() + DocumentarySubseries::count();
        $locatedBoxesCount = TransferBox::query()
            ->whereNotNull('location_code')
            ->where('location_code', '<>', '')
            ->count();

        $response = $this->actingAs($user)->getJson('/api/reports/summary');

        $response->assertOk()
            ->assertJsonPath('summary.total_documents', $documentsCount)
            ->assertJsonPath('summary.total_transfers', $transfersCount)
            ->assertJsonPath('summary.total_boxes', $boxesCount)
            ->assertJsonPath('summary.reserved_documents', $loanedDocumentsCount)
            ->assertJsonPath('categories.entry_and_registry', collect([
                $transfersCount,
                $documentsCount,
                $boxesCount,
                Unit::count(),
            ])->filter(fn ($value) => (int) $value > 0)->count())
            ->assertJsonPath('categories.access_and_consultation', collect([
                Loan::count(),
                $loanedDocumentsCount,
                $returnedLoansCount,
            ])->filter(fn ($value) => (int) $value > 0)->count())
            ->assertJsonPath('categories.classification_and_organization', collect([
                $seriesCount,
                $locatedBoxesCount,
            ])->filter(fn ($value) => (int) $value > 0)->count());
    }

    public function test_user_without_reports_permission_cannot_get_summary(): void
    {
        $user = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();

        $response = $this->actingAs($user)->getJson('/api/reports/summary');

        $response->assertForbidden();
    }
}
