<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lớp học. Mỗi lớp một giảng viên (`instructor_id`); theory_class / practice_class là mã lớp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('course');
            $table->string('course_id', 50)->nullable();
            $table->string('course_code', 64)->nullable();
            $table->string('theory_class', 128)->nullable();
            $table->string('practice_class', 128)->nullable();
            $table->string('term', 100);
            $table->string('term_id', 50)->nullable();
            $table->string('instructor_id', 50)->nullable();
            $table->string('start_date', 50)->nullable();
            $table->string('end_date', 50)->nullable();
            $table->string('status', 50)->default('Active');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
            $table->foreign('instructor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('term_id')->references('id')->on('academic_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
