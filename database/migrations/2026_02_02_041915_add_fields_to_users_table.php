<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Si ya tenías 'name', lo haremos nullable o lo ignoramos, usaremos estos:
            $table->string('first_name')->after('id');
            $table->string('second_name')->nullable()->after('first_name');
            $table->string('first_last_name')->after('second_name');
            $table->string('second_last_name')->nullable()->after('first_last_name');
            $table->string('carnet')->nullable()->after('second_last_name'); // Carnet (puede ser nulo si es administrativo externo)
            $table->string('role')->default('Estudiante')->after('email'); // Rol
            $table->boolean('is_active')->default(true);
            
            // Relación con Unidades (Si borran la unidad, el usuario queda sin unidad: set null)
            $table->foreignId('unit_id')->nullable()->constrained('units')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
