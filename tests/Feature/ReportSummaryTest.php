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

    public function test_classification_report_splits_multiple_series_stored_in_one_document_label(): void
    {
        $user = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $document = TransferDocument::query()->firstOrFail();
        $document->update([
            'series_label' => '010 - ACTAS; 020 - CORRESPONDENCIA',
        ]);

        $response = $this->actingAs($user)->getJson('/api/reports/classification-organization/series-subseries');

        $response->assertOk()
            ->assertJsonFragment([
                'label' => '010 - ACTAS',
                'value' => '010 - ACTAS',
            ])
            ->assertJsonFragment([
                'label' => '020 - CORRESPONDENCIA',
                'value' => '020 - CORRESPONDENCIA',
            ])
            ->assertJsonFragment([
                'label' => '010 - ACTAS',
                'documents_count' => 1,
            ])
            ->assertJsonFragment([
                'label' => '020 - CORRESPONDENCIA',
                'documents_count' => 1,
            ])
            ->assertJsonMissing([
                'label' => '010 - ACTAS; 020 - CORRESPONDENCIA',
            ]);
    }

    public function test_reports_merge_physical_support_variants(): void
    {
        $user = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $documents = TransferDocument::query()->get();

        $documents->each->update(['support_type' => 'Fisico']);
        $documents->take(2)->each->update(['support_type' => 'Físico']);

        $entryResponse = $this->actingAs($user)->getJson('/api/reports/entry-registry');
        $classificationResponse = $this->actingAs($user)
            ->getJson('/api/reports/classification-organization/series-subseries?support=F%C3%ADsico');

        $entryResponse->assertOk();
        $supportRows = collect($entryResponse->json('charts.support'));

        $this->assertCount(1, $supportRows);
        $this->assertSame('Físico', $supportRows->first()['label']);
        $this->assertSame($documents->count(), $supportRows->first()['total']);

        $classificationResponse->assertOk()
            ->assertJsonPath('filters.support', [
                ['label' => 'Físico', 'value' => 'Físico'],
            ])
            ->assertJsonPath('summary.filtered_documents', $documents->count());
    }
}
