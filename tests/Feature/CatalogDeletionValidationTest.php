<?php

namespace Tests\Feature;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use App\Models\PhysicalLocationAisle;
use App\Models\PhysicalLocationOffice;
use App\Models\PhysicalLocationShelf;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogDeletionValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_series_with_subseries_is_blocked_by_check_and_delete(): void
    {
        $series = DocumentarySeries::create(['code' => '901', 'name' => 'Serie con hijos', 'is_active' => true]);
        DocumentarySubseries::create([
            'documentary_series_id' => $series->id,
            'code' => '01',
            'name' => 'Subserie hija',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())->postJson("/api/documentary-series/{$series->id}/check-deletion")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar la serie documental porque tiene subseries asociadas. Elimine primero sus subseries.');

        $this->actingAs($this->admin())->deleteJson("/api/documentary-series/{$series->id}")
            ->assertUnprocessable();
    }

    public function test_series_with_units_is_blocked(): void
    {
        $series = DocumentarySeries::create(['code' => '902', 'name' => 'Serie con unidad', 'is_active' => true]);
        $unit = Unit::create(['code' => 'UT902', 'name' => 'Unidad 902', 'is_active' => true]);
        $series->units()->attach($unit->id);

        $this->actingAs($this->admin())->postJson("/api/documentary-series/{$series->id}/check-deletion")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar la serie documental porque tiene unidades productoras asociadas. Desvincule primero las unidades productoras.');

        $this->actingAs($this->admin())->deleteJson("/api/documentary-series/{$series->id}")
            ->assertUnprocessable();
    }

    public function test_series_with_documents_is_blocked(): void
    {
        $series = DocumentarySeries::create(['code' => '903', 'name' => 'Serie con documentos', 'is_active' => true]);
        $unit = Unit::create(['code' => 'UT903', 'name' => 'Unidad 903', 'is_active' => true]);
        $this->createDocument($this->seriesLabel($series), $unit);

        $this->actingAs($this->admin())->postJson("/api/documentary-series/{$series->id}/check-deletion")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar la serie documental porque está asociada a documentos. Desclasifique o reasigne primero los documentos asociados.');

        $this->actingAs($this->admin())->deleteJson("/api/documentary-series/{$series->id}")
            ->assertUnprocessable();
    }

    public function test_series_without_dependencies_is_soft_deleted_and_logged(): void
    {
        $series = DocumentarySeries::create(['code' => '904', 'name' => 'Serie eliminable', 'is_active' => true]);

        $response = $this->actingAs($this->admin())->deleteJson("/api/documentary-series/{$series->id}");

        $response->assertOk()->assertJsonStructure(['message', 'event_id']);
        $this->assertSoftDeleted('documentary_series', ['id' => $series->id]);
        $this->assertDatabaseHas('user_activity_logs', [
            'event_type' => 'documentary_series_deleted',
            'title' => 'Eliminacion de serie documental',
        ]);
    }

    public function test_subseries_with_units_is_blocked(): void
    {
        [$series, $subseries] = $this->createSubseries('905', '01');
        $unit = Unit::create(['code' => 'UT905', 'name' => 'Unidad 905', 'is_active' => true]);
        $subseries->units()->attach($unit->id);

        $this->actingAs($this->admin())->postJson("/api/documentary-subseries/{$subseries->id}/check-deletion")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar la subserie documental porque tiene unidades productoras asociadas. Desvincule primero las unidades productoras.');

        $this->actingAs($this->admin())->deleteJson("/api/documentary-subseries/{$subseries->id}")
            ->assertUnprocessable();
    }

    public function test_subseries_with_documents_is_blocked(): void
    {
        [$series, $subseries] = $this->createSubseries('906', '01');
        $unit = Unit::create(['code' => 'UT906', 'name' => 'Unidad 906', 'is_active' => true]);
        $this->createDocument($this->subseriesLabel($series, $subseries), $unit);

        $this->actingAs($this->admin())->postJson("/api/documentary-subseries/{$subseries->id}/check-deletion")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar la subserie documental porque está asociada a documentos. Desclasifique o reasigne primero los documentos asociados.');

        $this->actingAs($this->admin())->deleteJson("/api/documentary-subseries/{$subseries->id}")
            ->assertUnprocessable();
    }

    public function test_subseries_without_dependencies_is_soft_deleted_and_logged(): void
    {
        [, $subseries] = $this->createSubseries('907', '01');

        $response = $this->actingAs($this->admin())->deleteJson("/api/documentary-subseries/{$subseries->id}");

        $response->assertOk()->assertJsonStructure(['message', 'event_id']);
        $this->assertSoftDeleted('documentary_subseries', ['id' => $subseries->id]);
        $this->assertDatabaseHas('user_activity_logs', [
            'event_type' => 'documentary_subseries_deleted',
            'title' => 'Eliminacion de subserie documental',
        ]);
    }

    public function test_series_unit_removal_is_blocked_when_documents_belong_to_that_unit(): void
    {
        $series = DocumentarySeries::create(['code' => '908', 'name' => 'Serie unidad documento', 'is_active' => true]);
        $unit = Unit::create(['code' => 'UT908', 'name' => 'Unidad 908', 'is_active' => true]);
        $series->units()->attach($unit->id);
        $this->createDocument($this->seriesLabel($series), $unit);

        $this->actingAs($this->admin())->postJson("/api/documentary-series/{$series->id}/check-unit-removal", [
            'unit_ids' => [$unit->id],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede quitar la unidad productora porque existen documentos de la serie o sus subseries asociados a esa unidad.');
    }

    public function test_subseries_unit_removal_is_blocked_when_documents_belong_to_that_unit(): void
    {
        [$series, $subseries] = $this->createSubseries('909', '01');
        $unit = Unit::create(['code' => 'UT909', 'name' => 'Unidad 909', 'is_active' => true]);
        $subseries->units()->attach($unit->id);
        $this->createDocument($this->subseriesLabel($series, $subseries), $unit);

        $this->actingAs($this->admin())->postJson("/api/documentary-subseries/{$subseries->id}/check-unit-removal", [
            'unit_ids' => [$unit->id],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede quitar la unidad productora porque existen documentos de la subserie asociados a esa unidad.');
    }

    public function test_deleted_series_and_subseries_codes_cannot_be_reused_manually(): void
    {
        $series = DocumentarySeries::create(['code' => '910', 'name' => 'Serie borrada', 'is_active' => true]);
        $this->actingAs($this->admin())->deleteJson("/api/documentary-series/{$series->id}")->assertOk();

        $this->actingAs($this->admin())->postJson('/api/documentary-series', [
            'code' => '910', 'name' => 'Serie nueva', 'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('code');

        $parent = DocumentarySeries::create(['code' => '911', 'name' => 'Serie padre', 'is_active' => true]);
        $subseries = DocumentarySubseries::create([
            'documentary_series_id' => $parent->id,
            'code' => '01',
            'name' => 'Subserie borrada',
            'is_active' => true,
        ]);
        $subseries->delete();

        $this->actingAs($this->admin())->postJson("/api/documentary-series/{$parent->id}/subseries", [
            'code' => '01', 'name' => 'Subserie nueva', 'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('code');
    }

    public function test_office_with_aisles_is_blocked(): void
    {
        $office = PhysicalLocationOffice::create(['code' => 'OF912', 'name' => 'Oficina 912', 'is_active' => true]);
        PhysicalLocationAisle::create([
            'physical_location_office_id' => $office->id,
            'code' => 'A', 'name' => 'Pasillo A', 'is_active' => true,
        ]);

        $this->actingAs($this->admin())->postJson("/api/physical-location-offices/{$office->id}/check-deletion")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar la oficina porque tiene pasillos registrados. Elimine primero los pasillos.');

        $this->actingAs($this->admin())->deleteJson("/api/physical-location-offices/{$office->id}")
            ->assertUnprocessable();
    }

    public function test_aisle_with_shelves_is_blocked(): void
    {
        $office = PhysicalLocationOffice::create(['code' => 'OF913', 'name' => 'Oficina 913', 'is_active' => true]);
        $aisle = PhysicalLocationAisle::create([
            'physical_location_office_id' => $office->id,
            'code' => 'A', 'name' => 'Pasillo A', 'is_active' => true,
        ]);
        PhysicalLocationShelf::create([
            'physical_location_aisle_id' => $aisle->id,
            'code' => '01', 'name' => 'Estante 1', 'is_active' => true,
        ]);

        $this->actingAs($this->admin())->postJson("/api/physical-location-aisles/{$aisle->id}/check-deletion")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar el pasillo porque tiene estantes registrados. Elimine primero los estantes.');

        $this->actingAs($this->admin())->deleteJson("/api/physical-location-aisles/{$aisle->id}")
            ->assertUnprocessable();
    }

    public function test_shelf_with_box_is_blocked_using_current_location(): void
    {
        $office = PhysicalLocationOffice::create(['code' => 'OF914', 'name' => 'Oficina 914', 'is_active' => true]);
        $aisle = PhysicalLocationAisle::create([
            'physical_location_office_id' => $office->id,
            'code' => 'A', 'name' => 'Pasillo A', 'is_active' => true,
        ]);
        $shelf = PhysicalLocationShelf::create([
            'physical_location_aisle_id' => $aisle->id,
            'code' => '01', 'name' => 'Estante 1', 'is_active' => true,
        ]);
        $this->createBoxAtLocation(' of914-a-01 ');

        $this->actingAs($this->admin())->postJson("/api/physical-location-shelves/{$shelf->id}/check-deletion")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar el estante porque tiene cajas asociadas. Retire primero las cajas de esta ubicación.');

        $this->actingAs($this->admin())->deleteJson("/api/physical-location-shelves/{$shelf->id}")
            ->assertUnprocessable();
    }

    public function test_empty_locations_are_deleted_and_logged(): void
    {
        $office = PhysicalLocationOffice::create(['code' => 'OF915', 'name' => 'Oficina 915', 'is_active' => true]);
        $aisle = PhysicalLocationAisle::create([
            'physical_location_office_id' => $office->id,
            'code' => 'A', 'name' => 'Pasillo A', 'is_active' => true,
        ]);
        $shelf = PhysicalLocationShelf::create([
            'physical_location_aisle_id' => $aisle->id,
            'code' => '01', 'name' => 'Estante 1', 'is_active' => true,
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->deleteJson("/api/physical-location-shelves/{$shelf->id}")
            ->assertOk()->assertJsonStructure(['message', 'event_id']);
        $this->actingAs($admin)->deleteJson("/api/physical-location-aisles/{$aisle->id}")
            ->assertOk()->assertJsonStructure(['message', 'event_id']);
        $this->actingAs($admin)->deleteJson("/api/physical-location-offices/{$office->id}")
            ->assertOk()->assertJsonStructure(['message', 'event_id']);

        $this->assertDatabaseMissing('physical_location_shelves', ['id' => $shelf->id]);
        $this->assertDatabaseMissing('physical_location_aisles', ['id' => $aisle->id]);
        $this->assertDatabaseMissing('physical_location_offices', ['id' => $office->id]);
        $this->assertDatabaseHas('user_activity_logs', ['event_type' => 'physical_location_shelf_deleted']);
        $this->assertDatabaseHas('user_activity_logs', ['event_type' => 'physical_location_aisle_deleted']);
        $this->assertDatabaseHas('user_activity_logs', ['event_type' => 'physical_location_office_deleted']);
    }

    private function admin(): User
    {
        return User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
    }

    private function createSubseries(string $seriesCode, string $subseriesCode): array
    {
        $series = DocumentarySeries::create([
            'code' => $seriesCode,
            'name' => 'Serie ' . $seriesCode,
            'is_active' => true,
        ]);
        $subseries = DocumentarySubseries::create([
            'documentary_series_id' => $series->id,
            'code' => $subseriesCode,
            'name' => 'Subserie ' . $subseriesCode,
            'is_active' => true,
        ]);

        return [$series, $subseries];
    }

    private function createDocument(string $seriesLabel, Unit $unit): TransferDocument
    {
        $transfer = Transfer::create([
            'code' => 'TRF-' . $unit->code,
            'user_id' => $this->admin()->id,
            'unit_id' => $unit->id,
            'request_date' => '2026-01-01',
            'status' => 'Borrador',
        ]);
        $box = TransferBox::create([
            'transfer_id' => $transfer->id,
            'series_name' => $seriesLabel,
            'start_year' => 2026,
            'end_year' => 2026,
            'box_number' => 1,
        ]);

        return TransferDocument::create([
            'transfer_box_id' => $box->id,
            'code' => 'DOC-' . $unit->code,
            'name' => 'Documento asociado',
            'series_label' => $seriesLabel,
            'support_type' => 'Fisico',
            'sort_order' => 0,
        ]);
    }

    private function createBoxAtLocation(string $locationCode): TransferBox
    {
        $unit = Unit::firstOrFail();
        $transfer = Transfer::create([
            'code' => 'TRF-LOC-' . $locationCode,
            'user_id' => $this->admin()->id,
            'unit_id' => $unit->id,
            'request_date' => '2026-01-01',
            'status' => 'Borrador',
        ]);

        return TransferBox::create([
            'transfer_id' => $transfer->id,
            'series_name' => 'Ubicacion de prueba',
            'start_year' => 2026,
            'end_year' => 2026,
            'box_number' => 1,
            'location_code' => $locationCode,
        ]);
    }

    private function seriesLabel(DocumentarySeries $series): string
    {
        return $series->code . ' - ' . $series->name;
    }

    private function subseriesLabel(DocumentarySeries $series, DocumentarySubseries $subseries): string
    {
        return $this->seriesLabel($series) . ' / ' . $subseries->code . ' - ' . $subseries->name;
    }
}
