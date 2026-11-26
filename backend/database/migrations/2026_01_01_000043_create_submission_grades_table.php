<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bản chấm riêng của từng người chấm: `initial` (chấm lần đầu) và `regrade` (phúc khảo), độc lập nhau.
 * submissions.evaluated_score / feedback là điểm chính thức: bằng bản phúc khảo khi có, không thì bằng bản chấm đầu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_grades', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('submission_id', 50);
            $table->string('kind', 16);
            $table->string('grader_id', 50)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->json('question_scores')->nullable();
            $table->json('question_feedback')->nullable();
            $table->timestamp('graded_at')->nullable();

            $table->unique(['submission_id', 'kind'], 'uq_submission_grades_kind');

            $table->foreign('grader_id', 'fk_submission_grades_grader')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submission_id', 'fk_submission_grades_submission')->references('id')->on('submissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_grades');
    }
};
