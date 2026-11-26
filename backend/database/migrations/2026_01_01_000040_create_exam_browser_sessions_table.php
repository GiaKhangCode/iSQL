<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trình duyệt đang dùng tài khoản thi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_browser_sessions', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('admission_id', 36);
            $table->string('session_id', 36);
            $table->string('student_id', 36);
            $table->string('browser_code', 64)->unique();
            $table->string('status', 32)->default('active');
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('approved_by', 36)->nullable();
            $table->text('approval_reason')->nullable();

            $table->index('admission_id');
            $table->index('session_id');
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_browser_sessions');
    }
};
