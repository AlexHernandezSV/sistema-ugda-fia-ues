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
        $baseRoles = ['Administrador'];

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            $dynamicRoles = DB::table('users')
                ->whereNotNull('role')
                ->select('role')
                ->distinct()
                ->pluck('role')
                ->filter(fn ($role) => trim((string) $role) !== '')
                ->map(fn ($role) => trim((string) $role))
                ->values()
                ->all();

            $baseRoles = array_values(array_unique(array_merge($baseRoles, $dynamicRoles)));
        }

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
