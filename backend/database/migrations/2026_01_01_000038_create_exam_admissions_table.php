<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tài khoản thi của sinh viên trong ca thi (kèm mã đề).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_admissions', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('examination_id', 36)->nullable();
            $table->string('session_id', 36);
            $table->string('student_id', 36);
            $table->string('username', 128)->unique();
            $table->string('hashed_password');
            $table->string('initial_password', 128)->nullable();
            $table->string('status', 32)->default('issued');
            $table->string('variant_code', 32)->nullable();
            $table->integer('generation')->default(1);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'student_id'], 'exam_admissions_session_student_uq');
            $table->index('examination_id');
            $table->index('session_id');
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_admissions');
    }
};
