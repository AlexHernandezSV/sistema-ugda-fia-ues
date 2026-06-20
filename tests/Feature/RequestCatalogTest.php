<?php

namespace Tests\Feature;

use App\Models\LoanDocument;
use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_authenticated_user_can_get_loan_detail_with_history(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/loans/PREST-001');

        $response->assertOk()
            ->assertJson([
                'number' => 'PREST-001',
                'applicant' => 'Lic. Maria Elena Rodriguez',
                'requestedAt' => '16/02/2026 09:15',
            ])
            ->assertJsonPath('documents.0.title', 'Actas de Junta Directiva 2023')
            ->assertJsonPath('history.0.status', 'Creado');
    }

    public function test_requester_can_store_system_loan_document_type(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();
        $unit = $requester->units()->firstOrFail();
        $authorizedStatus = RequestStatusCatalog::where('code', 'transfer_auth_authorized')->firstOrFail();
        $transferredStatus = RequestStatusCatalog::where('code', 'transfer_status_transferred')->firstOrFail();
        $transfer = Transfer::create([
            'code' => 'TEST-LOAN-TYPE',
            'user_id' => $requester->id,
            'unit_id' => $unit->id,
            'request_date' => now()->toDateString(),
            'requested_at' => now(),
            'status' => 'Aprobada',
            'authorization_status_id' => $authorizedStatus->id,
            'workflow_status_id' => $transferredStatus->id,
            'completed_at' => now(),
        ]);
        $box = TransferBox::create([
            'transfer_id' => $transfer->id,
            'series_name' => 'Actas y Resoluciones',
            'start_year' => 2026,
            'end_year' => 2026,
            'box_number' => 1,
            'title' => 'Caja #001 - Actas y Resoluciones',
            'period_label' => '2026',
        ]);
        $transferDocument = TransferDocument::create([
            'transfer_box_id' => $box->id,
            'code' => 'DOC-ORIGINAL-TEST',
            'name' => 'Documento de prueba original',
            'series_label' => 'Actas y Resoluciones',
            'support_type' => 'Fisico',
            'year_label' => '2026',
            'sort_order' => 1,
        ]);

        $catalogResponse = $this->actingAs($requester)->getJson('/api/loans/create/catalog');
        $catalogResponse->assertOk();

        $document = collect($catalogResponse->json('documents'))
            ->firstWhere('id', $transferDocument->id);
        $this->assertNotNull($document);

        $response = $this->actingAs($requester)->postJson('/api/loans', [
            'requester_name' => 'Patricia Marlene Callejas de Velásquez',
            'unit_name' => 'Decanato FIA',
            'system_documents' => [
                [
                    'id' => $document['id'],
                    'document_type' => 'Original',
                ],
            ],
        ]);

        $response->assertCreated();

        $loanDocument = LoanDocument::query()
            ->where('title', $document['title'])
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Original', $loanDocument->document_type_label);
        $this->assertSame('warning', $loanDocument->document_type_tone);
    }

    public function test_authenticated_user_can_get_transfer_detail_with_complete_box_document_information(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/transfers/000964');

        $response->assertOk()
            ->assertJsonPath('boxes.0.number', '001')
            ->assertJsonPath('boxes.0.documents.0.code', 'EXP-GRAD-2024')
            ->assertJsonPath('boxes.0.documents.0.series', 'Expedientes Academicos')
            ->assertJsonPath('boxes.0.documents.0.support', 'Fisico')
            ->assertJsonPath('boxes.0.documents.0.pages', '350 pags')
            ->assertJsonPath('boxes.0.documents.0.digitalAvailable', true)
            ->assertJsonPath('boxes.0.documents.0.digitalUrl', '/api/transfers/000964/documents/EXP-GRAD-2024/download');
    }

    public function test_admin_can_search_general_archive_by_any_document_related_value(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $completedTransfers = Transfer::whereNotNull('completed_at')->pluck('id');
        $completedBoxes = TransferBox::whereIn('transfer_id', $completedTransfers)->pluck('id');

        $response = $this->actingAs($admin)->getJson('/api/archive/documents?q=A-03-2-05');

        $response->assertOk()
            ->assertJsonPath('totals.documents', TransferDocument::whereIn('transfer_box_id', $completedBoxes)->count())
            ->assertJsonPath('totals.boxes', $completedBoxes->count())
            ->assertJsonPath('totals.completed_transfers', $completedTransfers->count());

        $document = collect($response->json('results'))
            ->firstWhere('code', 'EXP-GRAD-2024');

        $this->assertNotNull($document);
        $this->assertSame('A-03-2-05', $document['box']['location']);
        $this->assertSame('000964', $document['transfer']['code']);
    }

    public function test_admin_can_search_general_archive_by_completed_transfer_date(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/archive/documents?q=19/04/2025');

        $response->assertOk();

        $codes = collect($response->json('results'))->pluck('code');

        $this->assertTrue($codes->contains('EXP-GRAD-2024'));
        $this->assertTrue($codes->contains('ACTA-GRAD-2024'));
    }

    public function test_general_archive_is_only_available_to_admin_profile(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();

        $response = $this->actingAs($requester)->getJson('/api/archive/documents?q=acta');

        $response->assertForbidden()
            ->assertJsonPath('message', 'Solo el administrador puede consultar el archivo general.');
    }

    public function test_authenticated_user_can_get_review_transfer_detail_with_ugda_sections(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/transfers/000975');

        $response->assertOk()
            ->assertJsonPath('mode', 'review')
            ->assertJsonPath('pageTitle', 'Revisar Transferencia Documental')
            ->assertJsonPath('workflowNotice.title', 'Solicitud pendiente de gestión')
            ->assertJsonPath('observationsRegistered.count', 2)
            ->assertJsonPath('documentSummary.totalBoxes', 3)
            ->assertJsonPath('boxes.0.title', 'Caja #001 - Expedientes Academicos');
    }

    public function test_authenticated_user_can_get_denied_transfer_detail_with_decision_card(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/transfers/000973');

        $response->assertOk()
            ->assertJsonPath('auth', 'Denegado')
            ->assertJsonPath('status', 'Cancelada')
            ->assertJsonPath('decisionCard.title', 'Solicitud Denegada')
            ->assertJsonPath('decisionCard.actor', 'Dr. Jose Antonio Mendez')
            ->assertJsonPath('history.0.status', 'Denegado');
    }

    public function test_authenticated_user_can_download_transfer_document_from_hyperlink_route(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/api/transfers/000964/documents/EXP-GRAD-2024/download');

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=EXP-GRAD-2024.pdf');
        $this->assertStringContainsString('Transferencia: 000964', $response->streamedContent());
        $this->assertStringContainsString('Código: EXP-GRAD-2024', $response->streamedContent());
    }

    public function test_dashboard_alert_routes_point_to_loan_detail_when_alert_is_for_a_loan(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/dashboard-summary');

        $response->assertOk();

        $loanAlert = collect($response->json('alerts.items', []))
            ->first(fn (array $item) => $item['type'] === 'Prestamo');

        $this->assertNotNull($loanAlert);
        $this->assertStringStartsWith('/solicitudes/prestamos/', $loanAlert['route']);
    }
}
