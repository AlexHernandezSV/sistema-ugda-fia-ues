<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('document_person')) {
            Schema::create('document_person', function (Blueprint $table) {
                $table->id();
                $table->foreignId('type_document_id')->constrained('type_document')->cascadeOnDelete();
                $table->foreignId('person_id')->constrained('person')->cascadeOnDelete();
                $table->string('document');
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('document_person')) {
            Schema::drop('document_person');
        }
    }
};
