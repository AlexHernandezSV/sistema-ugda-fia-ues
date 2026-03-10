<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacySchemaSyncSeeder extends Seeder
{
    public function run(): void
    {
        $this->syncTwoCodeFromTwoFactorCode();
        $this->syncPersonFromUsers();
        $this->syncUserProfileFromUsers();
        $this->syncUserRoleFromUsers();
        $this->syncUserUnitFromUsers();
    }

    private function syncTwoCodeFromTwoFactorCode(): void
    {
        if (
            !Schema::hasTable('users') ||
            !Schema::hasColumn('users', 'two_code') ||
            !Schema::hasColumn('users', 'two_factor_code')
        ) {
            return;
        }

        DB::table('users')
            ->whereNull('two_code')
            ->whereNotNull('two_factor_code')
            ->update(['two_code' => DB::raw('two_factor_code')]);
    }

    private function syncPersonFromUsers(): void
    {
        if (
            !Schema::hasTable('users') ||
            !Schema::hasTable('person') ||
            !Schema::hasColumn('users', 'first_name') ||
            !Schema::hasColumn('users', 'first_last_name')
        ) {
            return;
        }

        $now = now();
        $hasUsersCarnet = Schema::hasColumn('users', 'carnet');
        $hasPersonCarnet = Schema::hasColumn('person', 'carnet');

        $columns = [
            'id',
            'first_name',
            'second_name',
            'first_last_name',
            'second_last_name',
        ];

        if ($hasUsersCarnet && $hasPersonCarnet) {
            $columns[] = 'carnet';
        }

        $users = DB::table('users')->select($columns)->get();

        foreach ($users as $user) {
            $payload = [
                'first_name' => $user->first_name,
                'second_name' => $user->second_name,
                'first_last_name' => $user->first_last_name,
                'second_last_name' => $user->second_last_name,
                'updated_at' => $now,
                'created_at' => $now,
            ];

            if ($hasUsersCarnet && $hasPersonCarnet) {
                $payload['carnet'] = $user->carnet;
            }

            DB::table('person')->updateOrInsert(
                ['user_id' => $user->id],
                $payload
            );
        }
    }

    private function syncUserProfileFromUsers(): void
    {
        if (
            !Schema::hasTable('users') ||
            !Schema::hasColumn('users', 'role') ||
            !Schema::hasTable('profile') ||
            !Schema::hasTable('user_profile')
        ) {
            return;
        }

        $now = now();
        $usersWithRole = DB::table('users')->whereNotNull('role')->select('id', 'role')->get();

        foreach ($usersWithRole as $user) {
            $profileName = trim((string) $user->role);

            if ($profileName === '') {
                continue;
            }

            $profileId = DB::table('profile')->where('name', $profileName)->value('id');

            if ($profileId === null) {
                $profileId = DB::table('profile')->insertGetId([
                    'name' => $profileName,
                    'description' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }

            DB::table('user_profile')
                ->where('user_id', $user->id)
                ->update([
                    'is_active' => false,
                    'updated_at' => $now,
                ]);

            DB::table('user_profile')->updateOrInsert(
                [
                    'profile_id' => $profileId,
                    'user_id' => $user->id,
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

    private function syncUserRoleFromUsers(): void
    {
        if (
            !Schema::hasTable('users') ||
            !Schema::hasColumn('users', 'role') ||
            !Schema::hasTable('role') ||
            !Schema::hasTable('user_role')
        ) {
            return;
        }

        $now = now();
        $usersWithRole = DB::table('users')->whereNotNull('role')->select('id', 'role')->get();

        foreach ($usersWithRole as $user) {
            $roleName = trim((string) $user->role);

            if ($roleName === '') {
                continue;
            }

            $roleId = DB::table('role')->where('name', $roleName)->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('user_role')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'user_id' => $user->id,
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

    private function syncUserUnitFromUsers(): void
    {
        if (
            !Schema::hasTable('users') ||
            !Schema::hasColumn('users', 'unit_id') ||
            !Schema::hasTable('units') ||
            !Schema::hasTable('user_unit')
        ) {
            return;
        }

        $now = now();
        $usersWithUnit = DB::table('users')->whereNotNull('unit_id')->select('id', 'unit_id')->get();

        foreach ($usersWithUnit as $user) {
            DB::table('user_unit')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'unit_id' => $user->unit_id,
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
