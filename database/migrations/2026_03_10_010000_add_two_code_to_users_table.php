<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'two_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('two_code')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'two_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('two_code');
            });
        }
    }
};
