<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileRoleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('profile_role') || !Schema::hasTable('profiles') || !Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        $profiles = DB::table('profiles')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->select('id', 'name')
            ->get();

        foreach ($profiles as $profile) {
            $roleId = DB::table('roles')
                ->where('name', $profile->name)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('profile_role')->upsert([
                [
                    'role_id' => $roleId,
                    'profile_id' => $profile->id,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ],
            ], ['role_id', 'profile_id'], ['is_active', 'updated_at', 'deleted_at']);
        }
    }
}
