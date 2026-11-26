<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lớp được giao hoạt động.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_classes', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('assignment_id', 50);
            $table->string('class_id', 50);

            $table->unique(['assignment_id', 'class_id']);

            $table->foreign('assignment_id')->references('id')->on('assignments')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_classes');
    }
};
