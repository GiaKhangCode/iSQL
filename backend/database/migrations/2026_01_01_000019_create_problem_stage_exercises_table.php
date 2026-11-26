<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Câu hỏi DDL / DML / Trigger của bài toán (JSON theo từng phần).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_stage_exercises', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('problem_id', 50);
            $table->string('part', 10);
            $table->json('config');
            $table->timestamps();

            $table->unique(['problem_id', 'part'], 'uq_problem_stage_exercise');
            $table->index('problem_id');

            $table->foreign('problem_id')->references('id')->on('problems')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_stage_exercises');
    }
};
