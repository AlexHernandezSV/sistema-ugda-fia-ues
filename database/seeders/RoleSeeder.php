<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }

        $baseRoles = [
            'Administrador',
            'Usuario UGDA',
            'Unidad Solicitante',
            'Director/Jefe de Unidad',
        ];

        foreach ($baseRoles as $roleName) {
            $role = Role::withTrashed()->firstOrCreate(
                ['name' => $roleName],
                [
                    'description' => null,
                    'is_active' => true,
                ]
            );

            if ($role->trashed()) {
                $role->restore();
            }

            $role->update(['is_active' => true]);
        }
    }
}
