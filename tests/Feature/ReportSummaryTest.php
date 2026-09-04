<?php

namespace Tests\Feature;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use App\Models\Loan;
use App\Models\LoanDocument;
use App\Models\RequestStatusCatalog;
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
        $transferredDocumentsCount = TransferDocument::query()
            ->whereHas('box.transfer.workflowStatus', fn ($query) =>
                $query->where('code', 'transfer_status_transferred')
            )
            ->count();
        $transferredTransfersCount = Transfer::query()
            ->whereHas('workflowStatus', fn ($query) =>
                $query->where('code', 'transfer_status_transferred')
            )
            ->count();
        $transferredBoxesCount = TransferBox::query()
            ->whereHas('transfer.workflowStatus', fn ($query) =>
                $query->where('code', 'transfer_status_transferred')
            )
            ->count();
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
            ->assertJsonPath('summary.total_documents', $transferredDocumentsCount)
            ->assertJsonPath('summary.total_transfers', $transferredTransfersCount)
            ->assertJsonPath('summary.total_boxes', $transferredBoxesCount)
            ->assertJsonPath('summary.reserved_documents', $loanedDocumentsCount)
            ->assertJsonPath('categories.entry_and_registry', collect([
                $transferredTransfersCount,
                $transferredDocumentsCount,
                $transferredBoxesCount,
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

    public function test_classification_report_can_filter_by_a_series_and_subseries_label(): void
    {
        $user = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $document = TransferDocument::query()
            ->whereHas('box.transfer.workflowStatus', fn ($query) =>
                $query->where('code', 'transfer_status_transferred')
            )
            ->firstOrFail();
        $seriesLabel = '010 - ACTAS / 01 - Actas Asamblea General Universitaria';

        $document->update(['series_label' => $seriesLabel]);

        $response = $this->actingAs($user)->getJson(
            '/api/reports/classification-organization/series-subseries?' . http_build_query([
                'series' => $seriesLabel,
            ])
        );

        $response->assertOk()
            ->assertJsonPath('summary.filtered_documents', 1)
            ->assertJsonFragment([
                'label' => $seriesLabel,
                'documents_count' => 1,
            ]);
    }

    public function test_reports_merge_physical_support_variants(): void
    {
        $user = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $documents = TransferDocument::query()
            ->whereHas('box.transfer.workflowStatus', fn ($query) =>
                $query->where('code', 'transfer_status_transferred')
            )
            ->get();

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

    public function test_entry_registry_can_filter_by_a_specific_date_or_date_range(): void
    {
        $user = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $documents = TransferDocument::query()
            ->whereHas('box.transfer.workflowStatus', fn ($query) =>
                $query->where('code', 'transfer_status_transferred')
            )
            ->take(3)
            ->get();

        $this->assertCount(3, $documents);

        $documents[0]->update(['created_at' => '2026-01-10 08:00:00']);
        $documents[1]->update(['created_at' => '2026-01-11 08:00:00']);
        $documents[2]->update(['created_at' => '2026-01-12 08:00:00']);

        $specificDateResponse = $this->actingAs($user)
            ->getJson('/api/reports/entry-registry?date=2026-01-11');

        $specificDateResponse->assertOk()
            ->assertJsonPath('summary.filtered_documents', 1)
            ->assertJsonPath('documents.0.id', $documents[1]->id);

        $rangeResponse = $this->actingAs($user)
            ->getJson('/api/reports/entry-registry?start_date=2026-01-10&end_date=2026-01-11');

        $rangeResponse->assertOk()
            ->assertJsonPath('summary.filtered_documents', 2);
    }

    public function test_document_reports_only_include_documents_from_transferred_transfers(): void
    {
        $user = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $transferredDocuments = TransferDocument::query()
            ->whereHas('box.transfer.workflowStatus', fn ($query) =>
                $query->where('code', 'transfer_status_transferred')
            )
            ->get();
        $deniedDocument = TransferDocument::query()
            ->whereHas('box.transfer.workflowStatus', fn ($query) =>
                $query->where('code', 'transfer_status_denied')
            )
            ->firstOrFail();
        $transferredUnclassifiedCount = $transferredDocuments
            ->filter(fn (TransferDocument $document) => blank($document->series_label))
            ->count();

        $summaryResponse = $this->actingAs($user)->getJson('/api/reports/summary');
        $entryResponse = $this->actingAs($user)->getJson('/api/reports/entry-registry');
        $classificationResponse = $this->actingAs($user)
            ->getJson('/api/reports/classification-organization/series-subseries');
        $unclassifiedResponse = $this->actingAs($user)
            ->getJson('/api/reports/classification-organization/unclassified-documents');

        $summaryResponse->assertOk()
            ->assertJsonPath('summary.total_documents', $transferredDocuments->count());
        $entryResponse->assertOk()
            ->assertJsonPath('summary.filtered_documents', $transferredDocuments->count())
            ->assertJsonPath('summary.total_documents', $transferredDocuments->count())
            ->assertJsonMissing(['code' => $deniedDocument->code]);
        $classificationResponse->assertOk()
            ->assertJsonPath('summary.filtered_documents', $transferredDocuments->count())
            ->assertJsonPath('summary.total_documents', $transferredDocuments->count())
            ->assertJsonMissing(['code' => $deniedDocument->code]);
        $unclassifiedResponse->assertOk()
            ->assertJsonPath('summary.total_documents', $transferredUnclassifiedCount)
            ->assertJsonMissing(['code' => $deniedDocument->code]);
    }

    public function test_access_consultation_history_only_includes_documents_in_a_dispatch(): void
    {
        $user = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $unit = Unit::query()->firstOrFail();
        $document = TransferDocument::query()->firstOrFail();
        $authorizedStatus = RequestStatusCatalog::where('code', 'loan_status_authorized')->firstOrFail();

        $loan = Loan::query()->create([
            'number' => 'RPT-NOT-DELIVERED-2026',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'requested_at' => now(),
            'authorization_status_id' => $authorizedStatus->id,
            'workflow_status_id' => $authorizedStatus->id,
            'view_mode' => 'detail',
            'description' => 'Solicitud de prueba sin entrega.',
        ]);

        $deliveredDocument = $loan->documents()->create([
            'document_kind' => 'system',
            'group_title' => 'Documentos solicitados (2)',
            'title' => $document->name,
            'series_label' => $document->series_label,
            'found_in_search' => true,
            'selected_for_loan' => true,
            'sort_order' => 1,
        ]);
        $notDeliveredDocument = $loan->documents()->create([
            'document_kind' => 'system',
            'group_title' => 'Documentos solicitados (2)',
            'title' => $document->name . ' no entregado',
            'series_label' => $document->series_label,
            'found_in_search' => true,
            'selected_for_loan' => false,
            'sort_order' => 2,
        ]);

        $dispatch = $loan->dispatches()->create([
            'loan_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'received_by_name' => 'Usuario de prueba',
        ]);
        $dispatch->items()->create(['loan_document_id' => $deliveredDocument->id]);

        $response = $this->actingAs($user)->getJson('/api/reports/access-consultation/history');

        $response->assertOk();
        $reportedLoan = collect($response->json('loans'))->firstWhere('number', $loan->number);

        $this->assertNotNull($reportedLoan);
        $this->assertSame(1, $reportedLoan['documents_count']);
        $this->assertSame($deliveredDocument->title, $reportedLoan['documents'][0]['title']);
        $this->assertNotSame($notDeliveredDocument->title, $reportedLoan['documents'][0]['title']);
    }
}
