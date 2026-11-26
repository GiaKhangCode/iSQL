<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lượt làm bài của sinh viên: bản nháp, vi phạm, trạng thái.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_attempts', function (Blueprint $table) {
            $table->string('assignment_id', 50);
            $table->string('user_id', 50);
            $table->string('status', 30)->default('active');
            $table->string('schema_name', 64)->nullable();
            $table->json('drafts');
            $table->json('violations');
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('finish_reason', 50)->nullable();
            $table->timestamps();

            $table->primary(['assignment_id', 'user_id']);
            $table->index('schema_name');
            $table->index('status');

            $table->foreign('assignment_id')->references('id')->on('assignments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_attempts');
    }
};
