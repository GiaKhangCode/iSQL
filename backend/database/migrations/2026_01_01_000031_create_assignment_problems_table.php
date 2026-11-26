<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài toán trong hoạt động: điểm, phần thi, cách chọn câu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_problems', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('assignment_id', 50);
            $table->string('problem_id', 50);
            $table->decimal('points', 10, 4)->default(10);
            $table->integer('order_index')->default(0);
            $table->json('workspace_parts')->nullable();
            $table->json('dataset_visibility')->nullable();
            $table->json('question_selection')->nullable();
            $table->boolean('database_structure_visible')->default(true);
            $table->boolean('database_description_visible')->default(true);

            $table->foreign('assignment_id')->references('id')->on('assignments')->cascadeOnDelete();
            $table->foreign('problem_id')->references('id')->on('problems')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_problems');
    }
};
