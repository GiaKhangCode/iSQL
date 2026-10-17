<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('title', 255);
            $table->boolean('is_contest')->default(false);
            $table->text('instructions')->nullable();
            $table->string('audience_type', 20)->default('classes');
            $table->string('short_description', 180)->nullable();
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            $table->string('banner_url', 255)->nullable();
            $table->string('banner_source_url', 255)->nullable();
            $table->string('banner_crop', 160)->nullable();
            $table->timestamp('opens')->nullable();
            $table->timestamp('closes')->nullable();
            $table->boolean('published')->default(false);
            $table->string('instructor_id', 50)->nullable();

            // Student Options
            $table->boolean('hints_enabled')->default(true);
            $table->boolean('comments_enabled')->default(true);
            $table->boolean('leaderboard_enabled')->default(false);
            $table->boolean('ai_allowed')->default(true);

            // Comprehensive Contest Exam Fields
            $table->string('exam_type', 50)->default('classic'); // classic | comprehensive
            $table->text('ddl_reference_script')->nullable();    // Script DDL chuẩn của GV
            $table->text('dml_seed_script')->nullable();         // Script DML dữ liệu mẫu
            $table->integer('ddl_points')->default(30);
            $table->integer('dml_points')->default(20);
            $table->integer('sql_points')->default(50);

            $table->timestamps();

            $table->foreign('instructor_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};