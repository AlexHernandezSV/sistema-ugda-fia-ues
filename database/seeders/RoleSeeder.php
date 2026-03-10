<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('role')) {
            return;
        }

        $now = now();
        $baseRoles = [
            'Administrador',
            'Docente',
            'Estudiante',
            'Jefe de Unidad',
            'Secretaria',
        ];

        foreach ($baseRoles as $roleName) {
            DB::table('role')->updateOrInsert(
                ['name' => $roleName],
                [
                    'description' => null,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
