<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('person') && !Schema::hasColumn('person', 'carnet')) {
            Schema::table('person', function (Blueprint $table) {
                $table->string('carnet')->nullable()->after('second_last_name');
            });
        }

        if (!Schema::hasTable('users')) {
            return;
        }

        $legacyColumns = [
            'first_name',
            'second_name',
            'first_last_name',
            'second_last_name',
            'carnet',
            'role',
        ];

        $columnsToDrop = array_values(array_filter(
            $legacyColumns,
            fn (string $column) => Schema::hasColumn('users', $column)
        ));

        if ($columnsToDrop !== []) {
            Schema::table('users', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $needsFirstName = !Schema::hasColumn('users', 'first_name');
        $needsSecondName = !Schema::hasColumn('users', 'second_name');
        $needsFirstLastName = !Schema::hasColumn('users', 'first_last_name');
        $needsSecondLastName = !Schema::hasColumn('users', 'second_last_name');
        $needsCarnet = !Schema::hasColumn('users', 'carnet');
        $needsRole = !Schema::hasColumn('users', 'role');

        Schema::table('users', function (Blueprint $table) use (
            $needsFirstName,
            $needsSecondName,
            $needsFirstLastName,
            $needsSecondLastName,
            $needsCarnet,
            $needsRole
        ) {
            if ($needsFirstName) {
                $table->string('first_name')->nullable()->after('id');
            }
            if ($needsSecondName) {
                $table->string('second_name')->nullable()->after('first_name');
            }
            if ($needsFirstLastName) {
                $table->string('first_last_name')->nullable()->after('second_name');
            }
            if ($needsSecondLastName) {
                $table->string('second_last_name')->nullable()->after('first_last_name');
            }
            if ($needsCarnet) {
                $table->string('carnet')->nullable()->after('second_last_name');
            }
            if ($needsRole) {
                $table->string('role')->default('Estudiante')->after('email');
            }
        });
    }
};
