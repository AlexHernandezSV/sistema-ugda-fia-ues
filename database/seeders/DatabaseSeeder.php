<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ProfileSeeder::class,
            ProfileRoleSeeder::class,
            EndpointRoleSeeder::class,
        ]);

        $now = now();
        $admin = User::updateOrCreate(
            ['email' => 'admin-ugda@yopmail.com'],
            [
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $personPayload = [
            'first_name' => 'Administrador',
            'second_name' => null,
            'first_last_name' => 'UGDA',
            'second_last_name' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $personPayload['carnet'] = null;

        DB::table('person')->updateOrInsert(
            ['user_id' => $admin->id],
            $personPayload
        );

        $profile = Profile::withTrashed()->firstOrCreate(
            ['name' => 'Administrador'],
            [
                'description' => 'Acceso administrativo completo',
                'is_active' => true,
            ]
        );

        if ($profile->trashed()) {
            $profile->restore();
        }

        if (!$profile->is_active) {
            $profile->is_active = true;
            $profile->save();
        }

        DB::table('user_profile')
            ->where('user_id', $admin->id)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);

        DB::table('user_profile')->updateOrInsert(
            [
                'user_id' => $admin->id,
                'profile_id' => $profile->id,
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
