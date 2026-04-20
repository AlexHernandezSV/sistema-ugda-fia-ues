<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCodeMail;
use App\Mail\UserCredentialsMail;
use App\Models\Profile;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NormalizedAuthAndUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_database_seeder_creates_admin_with_person_and_profile(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')
            ->with(['person', 'profiles'])
            ->first();

        $this->assertNotNull($admin);
        $this->assertSame('Administrador', $admin->person?->first_name);
        $this->assertSame('UGDA', $admin->person?->first_last_name);
        $this->assertSame('Administrador', $admin->profiles->first()?->name);
    }

    public function test_login_auth_sends_two_factor_code_for_normalized_user(): void
    {
        Mail::fake();

        $response = $this->postJson('/login-auth', [
            'email' => 'admin-ugda@yopmail.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Código enviado',
                'require_2fa' => true,
                'email' => 'admin-ugda@yopmail.com',
            ]);

        $this->assertNotNull(User::where('email', 'admin-ugda@yopmail.com')->value('two_factor_code'));
        Mail::assertSent(TwoFactorCodeMail::class);
    }

    public function test_verify_2fa_logs_in_normalized_user(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $admin->forceFill([
            'two_factor_code' => Hash::make('123456'),
            'two_factor_expires_at' => now()->addMinutes(10),
        ])->save();

        $response = $this->postJson('/login-verify', [
            'email' => $admin->email,
            'code' => '123456',
        ]);

        $response->assertOk()
            ->assertJson(['redirect' => '/dashboard']);

        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_api_user_returns_normalized_payload(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $unit = Unit::create([
            'name' => 'Decanato FIA',
            'code' => 'DEC',
            'is_active' => true,
        ]);

        $this->syncUnits($admin, [$unit->id]);

        $response = $this->actingAs($admin)->getJson('/api/user');

        $response->assertOk()
            ->assertJson([
                'email' => 'admin-ugda@yopmail.com',
                'first_name' => 'Administrador',
                'first_last_name' => 'UGDA',
                'role' => 'Administrador',
                'unit_id' => $unit->id,
                'is_active' => true,
                'unit' => [
                    'id' => $unit->id,
                    'name' => 'Decanato FIA',
                ],
            ]);
    }

    public function test_store_user_creates_person_and_profile_without_breaking_payload_shape(): void
    {
        Mail::fake();

        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $unit = Unit::create([
            'name' => 'Unidad de Tecnología',
            'code' => 'UTI',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/users', [
            'first_name' => 'Bryan',
            'second_name' => 'Alexander',
            'first_last_name' => 'Aguilar',
            'second_last_name' => 'Rivas',
            'carnet' => 'AR2026',
            'email' => 'bryan.aguilar@ues.edu.sv',
            'unit_ids' => [$unit->id],
            'role' => 'Docente',
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'message' => 'Usuario creado y credenciales enviadas',
                'email' => 'bryan.aguilar@ues.edu.sv',
                'first_name' => 'Bryan',
                'first_last_name' => 'Aguilar',
                'role' => 'Docente',
            ]);

        $user = User::where('email', 'bryan.aguilar@ues.edu.sv')->firstOrFail();

        $this->assertDatabaseHas('person', [
            'user_id' => $user->id,
            'first_name' => 'Bryan',
            'first_last_name' => 'Aguilar',
            'carnet' => 'AR2026',
        ]);

        $this->assertDatabaseHas('user_unit', [
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'is_active' => 1,
            'deleted_at' => null,
        ]);

        $profileId = Profile::where('name', 'Docente')->value('id');

        $this->assertDatabaseHas('user_profile', [
            'user_id' => $user->id,
            'profile_id' => $profileId,
            'is_active' => 1,
        ]);

        Mail::assertSent(UserCredentialsMail::class);
    }

    public function test_update_user_moves_person_data_and_profile_assignment(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $unitA = Unit::create([
            'name' => 'Decanato',
            'code' => 'DEC',
            'is_active' => true,
        ]);
        $unitB = Unit::create([
            'name' => 'Escuela de Arquitectura',
            'code' => 'EA',
            'is_active' => true,
        ]);

        $user = $this->createNormalizedUser([
            'email' => 'persona.demo@ues.edu.sv',
        ], [
            'first_name' => 'María',
            'first_last_name' => 'López',
            'carnet' => 'ML1001',
        ], 'Estudiante');
        $this->syncUnits($user, [$unitA->id]);

        $response = $this->actingAs($admin)->putJson("/api/users/{$user->id}", [
            'first_name' => 'María Fernanda',
            'second_name' => 'del Carmen',
            'first_last_name' => 'López',
            'second_last_name' => 'Pineda',
            'carnet' => 'ML2026',
            'email' => 'persona.demo@ues.edu.sv',
            'unit_ids' => [$unitB->id, $unitA->id],
            'role' => 'Jefe de Unidad',
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Usuario actualizado correctamente']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'persona.demo@ues.edu.sv',
            'is_active' => 0,
        ]);

        $this->assertDatabaseHas('user_unit', [
            'user_id' => $user->id,
            'unit_id' => $unitB->id,
            'is_active' => 1,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('user_unit', [
            'user_id' => $user->id,
            'unit_id' => $unitA->id,
            'is_active' => 0,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('person', [
            'user_id' => $user->id,
            'first_name' => 'María Fernanda',
            'second_name' => 'del Carmen',
            'second_last_name' => 'Pineda',
            'carnet' => 'ML2026',
        ]);

        $newProfileId = Profile::where('name', 'Jefe de Unidad')->value('id');

        $this->assertDatabaseHas('user_profile', [
            'user_id' => $user->id,
            'profile_id' => $newProfileId,
            'is_active' => 1,
        ]);
    }

    public function test_delete_unit_soft_deletes_it_and_hides_it_from_listing(): void
    {
        $admin = User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $unit = Unit::create([
            'name' => 'Unidad Temporal',
            'code' => 'TMP',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->deleteJson("/api/units/{$unit->id}")
            ->assertOk()
            ->assertJson(['message' => 'Unidad eliminada']);

        $this->assertSoftDeleted('units', ['id' => $unit->id]);

        $this->actingAs($admin)->getJson('/api/units')
            ->assertOk()
            ->assertJsonMissing(['id' => $unit->id]);
    }

    private function createNormalizedUser(array $userAttributes, array $personAttributes, string $profileName): User
    {
        $user = User::create(array_merge([
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'is_active' => true,
        ], $userAttributes));

        $user->person()->create(array_merge([
            'first_name' => 'Usuario',
            'second_name' => null,
            'first_last_name' => 'Demo',
            'second_last_name' => null,
            'carnet' => null,
        ], $personAttributes));

        $profile = Profile::firstOrCreate(
            ['name' => $profileName],
            [
                'description' => null,
                'is_active' => true,
            ]
        );

        DB::table('user_profile')->insert([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        return $user;
    }

    private function syncUnits(User $user, array $unitIds): void
    {
        DB::table('user_unit')->where('user_id', $user->id)->delete();

        foreach ($unitIds as $index => $unitId) {
            DB::table('user_unit')->insert([
                'user_id' => $user->id,
                'unit_id' => $unitId,
                'is_active' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]);
        }
    }
}
