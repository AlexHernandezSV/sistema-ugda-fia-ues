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

        DB::table('profile')->updateOrInsert(
            ['name' => 'General'],
            [
                'description' => 'Perfil base del sistema',
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
                'deleted_at' => null,
            ]
        );
    }
}
