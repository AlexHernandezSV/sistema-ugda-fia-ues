<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('profile')) {
            return;
        }

        $now = now();
        $profiles = [
            ['name' => 'Administrador', 'description' => 'Acceso administrativo completo'],
            ['name' => 'Docente', 'description' => 'Perfil para personal docente'],
            ['name' => 'Estudiante', 'description' => 'Perfil para usuarios estudiantes'],
            ['name' => 'Jefe de Unidad', 'description' => 'Perfil para jefaturas de unidad'],
            ['name' => 'Secretaria', 'description' => 'Perfil para personal de secretaria'],
        ];

        foreach ($profiles as $profile) {
            DB::table('profile')->updateOrInsert(
                ['name' => $profile['name']],
                [
                    'description' => $profile['description'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
