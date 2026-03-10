<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_role')) {
            Schema::create('user_role', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained('role')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['role_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_role')) {
            Schema::drop('user_role');
        }
    }
};
