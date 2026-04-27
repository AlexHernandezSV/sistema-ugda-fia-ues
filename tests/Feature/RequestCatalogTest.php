<?php

namespace Tests\Feature;

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

    public function test_authenticated_user_can_get_review_transfer_detail_with_ugda_sections(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/transfers/000975');

        $response->assertOk()
            ->assertJsonPath('mode', 'review')
            ->assertJsonPath('pageTitle', 'Revisar Transferencia Documental')
            ->assertJsonPath('workflowNotice.title', 'Solicitud pendiente de gestion')
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
        $this->assertStringContainsString('Codigo: EXP-GRAD-2024', $response->streamedContent());
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
