<?php

namespace Tests\Feature;

use App\Models\LoanDocument;
use App\Models\Loan;
use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Models\User;
use App\Support\SignedPdfUrl;
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

    public function test_ugda_can_download_the_loan_summary_sheet_after_registering_a_loan(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->get(SignedPdfUrl::loanSummarySheet('PREST-007'));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertSee('PREST-007', false)
            ->assertSee('RECIBIDO POR', false)
            ->assertSee('Firma', false);
    }

    public function test_requester_cannot_download_the_ugda_loan_summary_sheet(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();

        $this->actingAs($requester)
            ->get(SignedPdfUrl::loanSummarySheet('PREST-007'))
            ->assertNotFound();
    }

    public function test_ugda_cannot_register_a_loan_with_due_date_before_loan_date(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $this->actingAs($admin)
            ->postJson('/api/loans/PREST-001/register', [
                'selected_ids' => [1],
                'loan_date' => '08/08/2026',
                'due_date' => '07/08/2026',
                'received_by' => 'Persona de prueba',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.due_date.0',
                'La fecha de devolución programada debe ser igual o posterior a la fecha de préstamo.'
            );
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
        $this->assertSame('C-TEST-LOAN-TYPE-001', $document['box']);

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
        $this->assertSame('C-TEST-LOAN-TYPE-001', $loanDocument->box_code);
    }

    public function test_request_catalog_marks_original_documents_that_are_still_loaned(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();
        $unit = $requester->units()->firstOrFail();
        $authorizedStatus = RequestStatusCatalog::where('code', 'transfer_auth_authorized')->firstOrFail();
        $transferredStatus = RequestStatusCatalog::where('code', 'transfer_status_transferred')->firstOrFail();
        $transfer = Transfer::create([
            'code' => 'TEST-LOANED-ORIGINAL',
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
            'title' => 'Caja de prueba',
            'period_label' => '2026',
        ]);
        $transferDocument = TransferDocument::create([
            'transfer_box_id' => $box->id,
            'code' => 'DOC-LOANED-ORIGINAL',
            'name' => 'Documento prestado como original',
            'series_label' => 'Actas y Resoluciones',
            'support_type' => 'Fisico',
            'year_label' => '2026',
            'sort_order' => 1,
        ]);
        $availableTransferDocument = TransferDocument::create([
            'transfer_box_id' => $box->id,
            'code' => 'DOC-AVAILABLE-ORIGINAL',
            'name' => $transferDocument->name,
            'series_label' => $transferDocument->series_label,
            'support_type' => 'Fisico',
            'year_label' => $transferDocument->year_label,
            'sort_order' => 2,
        ]);

        $loan = Loan::where('number', 'PREST-007')->firstOrFail();
        $loanDocument = $loan->documents()->create([
            'document_kind' => 'system',
            'group_title' => 'Documentos del Sistema (1)',
            'title' => $transferDocument->name,
            'series_label' => $transferDocument->series_label,
            'box_code' => $box->boxCode($transfer->code),
            'year_label' => $transferDocument->year_label,
            'document_type_label' => 'Original',
            'document_type_tone' => 'warning',
            'selected_for_loan' => true,
            'returned' => false,
            'sort_order' => 1,
        ]);
        $loan->events()->create([
            'status_catalog_id' => null,
            'actor_user_id' => null,
            'actor_name_snapshot' => null,
            'event_type' => 'status',
            'title' => 'Creado',
            'description' => 'Solicitud de prueba.',
            'context' => [
                'document_map' => [[
                    'loan_document_id' => $loanDocument->id,
                    'transfer_document_id' => $transferDocument->id,
                ]],
            ],
            'occurred_at' => now(),
        ]);

        $catalogResponse = $this->actingAs($requester)->getJson('/api/loans/create/catalog');
        $catalogResponse->assertOk();
        $catalogDocument = collect($catalogResponse->json('documents'))
            ->firstWhere('id', $transferDocument->id);
        $availableCatalogDocument = collect($catalogResponse->json('documents'))
            ->firstWhere('id', $availableTransferDocument->id);

        $this->assertTrue($catalogDocument['isLoanedOriginal']);
        $this->assertFalse($availableCatalogDocument['isLoanedOriginal']);

        $this->actingAs($requester)
            ->postJson('/api/loans', [
                'requester_name' => 'Patricia Marlene Callejas de Velásquez',
                'unit_name' => 'Decanato FIA',
                'system_documents' => [
                    [
                        'id' => $transferDocument->id,
                        'document_type' => 'Original',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Los documentos prestados como Original no estan disponibles para una nueva solicitud.');
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
            ->assertJsonPath('boxes.0.documents.0.digitalAvailable', true);

        $this->assertStringContainsString(
            '/api/transfers/000964/documents/document-',
            (string) $response->json('boxes.0.documents.0.digitalUrl')
        );
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

    public function test_general_archive_returns_current_box_code_and_active_loan_status(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $unit = $admin->units()->firstOrFail();
        $authorizedStatus = RequestStatusCatalog::where('code', 'loan_auth_authorized')->firstOrFail();
        $loanedStatus = RequestStatusCatalog::where('code', 'loan_status_loaned')->firstOrFail();
        $transferStatus = RequestStatusCatalog::where('code', 'transfer_status_transferred')->firstOrFail();

        $transfer = Transfer::create([
            'code' => 'ARCHIVE-TEST-LOAN',
            'user_id' => $admin->id,
            'unit_id' => $unit->id,
            'request_date' => now()->toDateString(),
            'requested_at' => now(),
            'status' => 'Aprobada',
            'authorization_status_id' => null,
            'workflow_status_id' => $transferStatus->id,
            'completed_by_user_id' => $admin->id,
            'completed_at' => now(),
        ]);
        $box = $transfer->boxes()->create([
            'series_name' => 'Actas y Resoluciones',
            'start_year' => 2026,
            'end_year' => 2026,
            'box_number' => 7,
            'title' => 'Caja #007',
        ]);
        $document = $box->documents()->create([
            'code' => 'ARCHIVE-DOCUMENT-LOAN',
            'name' => 'Documento actualmente prestado',
            'series_label' => 'Actas y Resoluciones',
            'support_type' => 'Fisico',
            'year_label' => '2026',
            'sort_order' => 1,
        ]);
        $loan = Loan::create([
            'number' => 'ARCHIVE-LOAN-TEST',
            'user_id' => $admin->id,
            'unit_id' => $unit->id,
            'requested_at' => now(),
            'authorization_status_id' => $authorizedStatus->id,
            'workflow_status_id' => $loanedStatus->id,
        ]);
        $loanDocument = $loan->documents()->create([
            'document_kind' => 'system',
            'group_title' => 'Documentos del Sistema (1)',
            'title' => $document->name,
            'series_label' => $document->series_label,
            'box_code' => $box->boxCode($transfer->code),
            'year_label' => $document->year_label,
            'document_type_label' => 'Original',
            'selected_for_loan' => true,
            'returned' => false,
            'sort_order' => 1,
        ]);
        $loan->events()->create([
            'status_catalog_id' => $loanedStatus->id,
            'actor_user_id' => $admin->id,
            'event_type' => 'status',
            'title' => 'Creado',
            'description' => 'Solicitud de prueba.',
            'context' => [
                'document_map' => [[
                    'loan_document_id' => $loanDocument->id,
                    'transfer_document_id' => $document->id,
                ]],
            ],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/archive/documents?q=ARCHIVE-DOCUMENT-LOAN');
        $response->assertOk();

        $result = collect($response->json('results'))->firstWhere('id', $document->id);

        $this->assertNotNull($result);
        $this->assertSame('C-ARCHIVE-TEST-LOAN-007', $result['box']['code']);
        $this->assertTrue($result['is_loaned']);
        $this->assertSame('Prestado', $result['loan']['status']);
    }

    public function test_general_archive_is_not_available_to_requester_profile(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();

        $response = $this->actingAs($requester)->getJson('/api/archive/documents?q=acta');

        $response->assertForbidden()
            ->assertJsonPath('message', 'Solo el personal UGDA puede consultar el archivo general.');
    }

    public function test_ugda_can_use_general_archive_but_not_physical_locations(): void
    {
        $ugda = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();

        $this->actingAs($ugda)
            ->getJson('/api/archive/documents?q=acta')
            ->assertOk();

        $this->actingAs($ugda)
            ->getJson('/api/physical-locations')
            ->assertForbidden();
    }

    public function test_transfer_review_actions_use_profile_instead_of_assigned_unit(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();
        $ugdaUnit = Unit::where('code', 'UGDA')->firstOrFail();
        $scheduledTransfer = Transfer::where('code', '000965')->firstOrFail();

        $requester->units()->sync([$ugdaUnit->id]);
        $scheduledTransfer->update(['unit_id' => $ugdaUnit->id]);

        $this->actingAs($requester)
            ->getJson('/api/transfers/000965')
            ->assertOk()
            ->assertJsonPath('processCard', null);

        $this->actingAs($requester)
            ->postJson('/api/transfers/000965/start-physical-review')
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo UGDA puede iniciar la revision fisica.');
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

    public function test_transfer_correction_notes_before_schedule_are_visible_to_director_and_ugda(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();
        $director = User::where('email', 'director.unidad@yopmail.com')->firstOrFail();
        $ugda = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $pendingAuth = RequestStatusCatalog::where('code', 'transfer_auth_pending')->firstOrFail();
        $pendingWorkflow = RequestStatusCatalog::where('code', 'transfer_status_pending')->firstOrFail();
        $transfer = Transfer::where('code', '000975')->firstOrFail();
        $notes = 'Se completaron las fichas descriptivas y se adjunto la firma solicitada.';

        $transfer->update([
            'authorization_status_id' => $pendingAuth->id,
            'workflow_status_id' => $pendingWorkflow->id,
            'scheduled_for' => null,
        ]);

        $transfer->events()->create([
            'status_catalog_id' => $pendingWorkflow->id,
            'actor_user_id' => $requester->id,
            'event_type' => 'status',
            'title' => 'Corregido y reenviado',
            'description' => 'Solicitud corregida por la unidad solicitante y reenviada a jefatura para autorizacion.',
            'context' => [
                'previous_status' => 'transfer_status_observed',
                'correction_notes' => $notes,
            ],
            'occurred_at' => now()->addMinute(),
        ]);

        $this->actingAs($director)->getJson('/api/transfers/000975')
            ->assertOk()
            ->assertJsonPath('subsanationSummary.title', 'Aclaraciones del solicitante')
            ->assertJsonPath('subsanationSummary.notes', $notes);

        $this->actingAs($ugda)->getJson('/api/transfers/000975')
            ->assertOk()
            ->assertJsonPath('subsanationSummary.title', 'Aclaraciones del solicitante')
            ->assertJsonPath('subsanationSummary.notes', $notes);
    }

    public function test_digital_observations_do_not_appear_as_physical_review_observations(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();
        $director = User::where('email', 'director.unidad@yopmail.com')->firstOrFail();
        $ugda = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $authorizedAuth = RequestStatusCatalog::where('code', 'transfer_auth_authorized')->firstOrFail();
        $physicalReview = RequestStatusCatalog::where('code', 'transfer_status_physical_review')->firstOrFail();
        $transfer = Transfer::where('code', '000975')->firstOrFail();

        $transfer->update([
            'authorization_status_id' => $authorizedAuth->id,
            'workflow_status_id' => $physicalReview->id,
            'scheduled_for' => now()->addDay(),
        ]);

        $this->actingAs($requester)->getJson('/api/transfers/000975')
            ->assertOk()
            ->assertJsonPath('physicalObservations', null);

        $this->actingAs($director)->getJson('/api/transfers/000975')
            ->assertOk()
            ->assertJsonPath('physicalObservations', null);

        $this->actingAs($ugda)->getJson('/api/transfers/000975')
            ->assertOk()
            ->assertJsonPath('physicalObservations', null);
    }

    public function test_physical_review_observations_enable_correction_only_after_physical_observation(): void
    {
        $requester = User::where('email', 'unidad.solicitante@yopmail.com')->firstOrFail();
        $ugda = User::where('email', 'usuario.ugda@yopmail.com')->firstOrFail();
        $authorizedAuth = RequestStatusCatalog::where('code', 'transfer_auth_authorized')->firstOrFail();
        $physicalObserved = RequestStatusCatalog::where('code', 'transfer_status_physical_observed')->firstOrFail();
        $transfer = Transfer::where('code', '000975')->firstOrFail();
        $physicalMessage = 'Falta rotular fisicamente la caja 2.';

        $transfer->update([
            'authorization_status_id' => $authorizedAuth->id,
            'workflow_status_id' => $physicalObserved->id,
            'scheduled_for' => now()->addDay(),
        ]);

        $transfer->events()->create([
            'status_catalog_id' => $physicalObserved->id,
            'actor_user_id' => $ugda->id,
            'event_type' => 'observation',
            'title' => 'Observación de revision fisica',
            'description' => $physicalMessage,
            'context' => [
                'observation_scope' => 'physical_review',
                'previous_status' => 'transfer_status_physical_review',
            ],
            'occurred_at' => now()->addMinute(),
        ]);

        $this->actingAs($requester)->getJson('/api/transfers/000975')
            ->assertOk()
            ->assertJsonPath('physicalObservations.count', 1)
            ->assertJsonPath('physicalObservations.canCorrect', true)
            ->assertJsonPath('physicalObservations.items.0.message', $physicalMessage);
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
