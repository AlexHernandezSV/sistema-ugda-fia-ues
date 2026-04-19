<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate([
            'email' => 'admin-ugda@yopmail.com',
        ], [
            'first_name' => 'Administrador',
            'second_name' => null,
            'first_last_name' => 'UGDA',
            'second_last_name' => null,
            'carnet' => null,
            'password' => Hash::make('password'),
            'role' => 'Administrador',
            'unit_id' => null,
            'is_active' => true,
        ]);
    }
}
