<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phúc khảo theo cả bài thi: một dòng cho một sinh viên trong một ca thi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_regrades', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('activity_id', 50);
            $table->string('student_id', 50);
            $table->string('assigned_instructor_id', 50);
            $table->string('assigned_by_id', 50)->nullable();
            $table->text('note')->nullable();
            $table->decimal('previous_evaluated_score', 8, 2)->nullable();
            $table->text('previous_feedback')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unique(['activity_id', 'student_id'], 'uq_exam_regrades_activity_student');
            $table->index('assigned_instructor_id');

            $table->foreign('activity_id', 'fk_exam_regrades_activity')->references('id')->on('assignments')->cascadeOnDelete();
            $table->foreign('assigned_by_id', 'fk_exam_regrades_assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_instructor_id', 'fk_exam_regrades_instructor')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('student_id', 'fk_exam_regrades_student')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_regrades');
    }
};
