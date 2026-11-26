<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Xác nhận danh sách thí sinh của ca thi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_plans', function (Blueprint $table) {
            $table->string('session_id', 36)->primary();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_by', 36)->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_plans');
    }
};
