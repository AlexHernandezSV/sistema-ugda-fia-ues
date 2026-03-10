<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileRoleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('profile_role') || !Schema::hasTable('profile') || !Schema::hasTable('role')) {
            return;
        }

        $now = now();
        $profileId = DB::table('profile')->where('name', 'General')->value('id');

        if ($profileId === null) {
            return;
        }

        $roles = DB::table('role')->select('id')->get();

        foreach ($roles as $role) {
            DB::table('profile_role')->updateOrInsert(
                [
                    'role_id' => $role->id,
                    'profile_id' => $profileId,
                ],
                [
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
