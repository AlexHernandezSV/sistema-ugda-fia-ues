<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Crear usuario administrador para la UGDA
        User::factory()->create([
            'name' => 'Administrador UGDA',
            'email' => 'admin-ugda@yopmail.com',
            'password' => Hash::make('password'), // La contraseña será 'password'
        ]);
    }

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_code')->nullable();
            $table->dateTime('two_factor_expires_at')->nullable();
        });
    }

    
}