<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài nộp; `evaluated_score` / `feedback` là điểm chính thức.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('user_id', 50);
            $table->string('problem_id', 50);
            $table->string('source', 100);
            $table->string('context');
            $table->string('result', 100);
            $table->decimal('score', 8, 2)->default(0);
            $table->timestamp('submitted_at')->useCurrent();
            $table->text('query_text')->nullable();
            $table->string('database_type', 50)->nullable();
            $table->string('schema_name', 64)->nullable();
            $table->decimal('evaluated_score', 8, 2)->nullable();
            $table->text('feedback')->nullable();

            $table->index('context');
            $table->index('schema_name');
            $table->index(['user_id', 'problem_id']);

            $table->foreign('problem_id')->references('id')->on('problems');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
