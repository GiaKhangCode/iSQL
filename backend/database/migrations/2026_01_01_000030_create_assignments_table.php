<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hoạt động giao bài. Có `examination_id` thì là một ca thi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('title');
            $table->string('scoring_mode', 30)->default('equal_questions');
            $table->text('instructions')->nullable();
            $table->string('audience_type', 20)->default('classes');
            $table->string('short_description', 180)->nullable();
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            $table->string('banner_url')->nullable();
            $table->string('banner_source_url')->nullable();
            $table->string('banner_crop', 160)->nullable();
            $table->timestamp('opens')->nullable();
            $table->timestamp('closes')->nullable();
            $table->boolean('published')->default(false);
            $table->string('instructor_id', 50)->nullable();
            $table->string('examination_id', 36)->nullable();
            $table->integer('session_index')->nullable();
            $table->json('proctors')->nullable();
            $table->string('room')->nullable();
            $table->integer('capacity')->nullable();
            $table->boolean('admission_required')->default(false);
            $table->boolean('hints_enabled')->default(true);
            $table->boolean('comments_enabled')->default(true);
            $table->boolean('leaderboard_enabled')->default(false);
            $table->boolean('ai_allowed')->default(true);
            $table->boolean('exam_mode')->default(false);
            $table->integer('exam_violation_limit')->default(2);
            $table->boolean('show_scores_after_submit')->default(true);
            $table->integer('max_submissions')->nullable();
            $table->string('exam_type', 50)->default('classic');
            $table->text('ddl_reference_script')->nullable();
            $table->text('dml_seed_script')->nullable();
            $table->integer('ddl_points')->default(30);
            $table->integer('dml_points')->default(20);
            $table->integer('sql_points')->default(50);
            $table->timestamps();

            $table->index('examination_id');

            $table->foreign('instructor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
