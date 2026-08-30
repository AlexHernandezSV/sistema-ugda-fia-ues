<?php

namespace Database\Seeders;

use App\Models\Unit;
use Database\Seeders\Concerns\SeedsUserAccounts;
use Illuminate\Database\Seeder;

class AdminOnlySeeder extends Seeder
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
    }
}
