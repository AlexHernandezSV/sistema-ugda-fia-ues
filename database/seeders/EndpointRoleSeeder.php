<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EndpointRoleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('profiles') || !Schema::hasTable('profile_role')) {
            return;
        }

        $now = now();

        $endpointRoles = [
            'endpoint.api.user.read' => 'Permite consultar el usuario autenticado',
            'endpoint.api.users.read' => 'Permite listar usuarios',
            'endpoint.api.users.create' => 'Permite crear usuarios',
            'endpoint.api.users.update' => 'Permite actualizar usuarios',
            'endpoint.api.users.delete' => 'Permite eliminar usuarios',
            'endpoint.api.units.read' => 'Permite listar unidades',
            'endpoint.api.units.create' => 'Permite crear unidades',
            'endpoint.api.units.update' => 'Permite actualizar unidades',
            'endpoint.api.units.delete' => 'Permite eliminar unidades',
        ];

        foreach ($endpointRoles as $name => $description) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                [
                    'description' => $description,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }

        $adminProfileId = DB::table('profiles')->where('name', 'Administrador')->value('id');

        if ($adminProfileId === null) {
            return;
        }

        $endpointRoleIds = DB::table('roles')
            ->whereIn('name', array_keys($endpointRoles))
            ->pluck('id');

        foreach ($endpointRoleIds as $roleId) {
            DB::table('profile_role')->updateOrInsert(
                [
                    'profile_id' => $adminProfileId,
                    'role_id' => $roleId,
                ],
                [
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
