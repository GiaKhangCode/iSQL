<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhật ký chấm bài: mỗi lần lưu điểm thêm một dòng, không sửa dòng cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_grade_logs', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('submission_id', 50);
            $table->string('kind', 16);
            $table->string('grader_id', 50)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->json('question_scores')->nullable();
            $table->json('question_feedback')->nullable();
            $table->timestamp('graded_at')->nullable();

            $table->index(['submission_id', 'graded_at'], 'idx_submission_grade_logs_submission');

            $table->foreign('grader_id', 'fk_submission_grade_logs_grader')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submission_id', 'fk_submission_grade_logs_submission')->references('id')->on('submissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_grade_logs');
    }
};
