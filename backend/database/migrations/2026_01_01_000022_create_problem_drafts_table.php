<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bản nháp câu truy vấn khi luyện tập.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_drafts', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('user_id', 50);
            $table->string('problem_id', 50);
            $table->text('draft_query')->nullable();

            $table->unique(['user_id', 'problem_id']);

            $table->foreign('problem_id')->references('id')->on('problems');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_drafts');
    }
};
