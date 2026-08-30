<?php

namespace Database\Seeders;

use App\Models\Unit;
use Database\Seeders\Concerns\SeedsUserAccounts;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use SeedsUserAccounts;

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ProfileSeeder::class,
            ProfileRoleSeeder::class,
            EndpointRoleSeeder::class,
            DocumentarySeriesSeeder::class,
            RequestStatusCatalogSeeder::class,
        ]);

        $ugdaUnit = Unit::withTrashed()->updateOrCreate(
            ['code' => 'UGDA'],
            [
                'name' => 'Unidad de Gestión Documental y Archivos',
                'is_active' => true,
            ]
        );
        if ($ugdaUnit->trashed()) {
            $ugdaUnit->restore();
        }

        $decanatoUnit = Unit::withTrashed()->updateOrCreate(
            ['code' => 'DEC-FIA'],
            [
                'name' => 'Decanato FIA',
                'is_active' => true,
            ]
        );
        if ($decanatoUnit->trashed()) {
            $decanatoUnit->restore();
        }

        $this->seedUserWithProfileAndUnits(
            email: 'admin-ugda@yopmail.com',
            password: 'password',
            personData: [
                'first_name' => 'Administrador',
                'second_name' => null,
                'first_last_name' => 'UGDA',
                'second_last_name' => null,
                'carnet' => null,
            ],
            profileName: 'Administrador',
            unitIds: [$ugdaUnit->id],
        );

        $this->seedUserWithProfileAndUnits(
            email: 'usuario.ugda@yopmail.com',
            password: 'password',
            personData: [
                'first_name' => 'Carlos',
                'second_name' => null,
                'first_last_name' => 'Rodriguez',
                'second_last_name' => null,
                'carnet' => 'UGDA2026',
            ],
            profileName: 'Usuario UGDA',
            unitIds: [$ugdaUnit->id],
        );

        $this->seedUserWithProfileAndUnits(
            email: 'unidad.solicitante@yopmail.com',
            password: 'password',
            personData: [
                'first_name' => 'Patricia',
                'second_name' => 'Marlene',
                'first_last_name' => 'Callejas',
                'second_last_name' => 'de Velásquez',
                'carnet' => 'US2026',
            ],
            profileName: 'Unidad Solicitante',
            unitIds: [$decanatoUnit->id],
        );

        $this->seedUserWithProfileAndUnits(
            email: 'director.unidad@yopmail.com',
            password: 'password',
            personData: [
                'first_name' => 'Luis',
                'second_name' => 'Salvador',
                'first_last_name' => 'Barrera',
                'second_last_name' => 'Mancia',
                'carnet' => 'DJ2026',
            ],
            profileName: 'Director/Jefe de Unidad',
            unitIds: [$decanatoUnit->id],
        );

        $this->call([
            RequestWorkflowDemoSeeder::class,
        ]);
    }
}
