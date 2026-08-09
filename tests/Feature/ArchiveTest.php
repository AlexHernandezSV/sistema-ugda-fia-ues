<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_numeric_box_search_matches_the_box_number_instead_of_the_period(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $transfer = Transfer::whereNotNull('completed_at')->firstOrFail();

        $matchingBox = $transfer->boxes()->create([
            'series_name' => 'Serie de prueba',
            'start_year' => 2024,
            'end_year' => 2024,
            'box_number' => 987654,
            'title' => 'Caja #987654',
            'period_label' => '01/01/987654 - 31/12/987654',
        ]);

        $transfer->boxes()->create([
            'series_name' => 'Otra serie de prueba',
            'start_year' => 2024,
            'end_year' => 2024,
            'box_number' => 987655,
            'title' => 'Caja #987655',
            'period_label' => '01/01/987654 - 31/12/987654',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/archive/boxes?q=987654')
            ->assertOk()
            ->assertJsonCount(1, 'boxes')
            ->assertJsonPath('boxes.0.id', $matchingBox->id)
            ->assertJsonPath('boxes.0.box_number', 987654)
            ->assertJsonPath('boxes.0.number', '987654')
            ->assertJsonPath('boxes.0.code', $matchingBox->boxCode($transfer->code));

        $this->actingAs($admin)
            ->getJson('/api/archive/boxes?q=' . $matchingBox->boxCode($transfer->code))
            ->assertOk()
            ->assertJsonCount(1, 'boxes')
            ->assertJsonPath('boxes.0.id', $matchingBox->id)
            ->assertJsonPath('boxes.0.code', $matchingBox->boxCode($transfer->code));
    }
}
