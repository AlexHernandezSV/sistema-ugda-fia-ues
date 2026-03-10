<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('type_document')) {
            Schema::create('type_document', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->text('description')->nullable();
                $table->string('mask')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('type_document')) {
            Schema::drop('type_document');
        }
    }
};
