<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Câu hỏi DQL của bài toán và đáp án.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_sql_solutions', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('problem_id', 50);
            $table->string('name', 120);
            $table->longText('query');
            $table->text('description')->nullable();
            $table->json('expected')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('time_limit_ms')->default(5000);
            $table->integer('order_index')->default(0);
            $table->timestamps();

            $table->index('problem_id');

            $table->foreign('problem_id')->references('id')->on('problems')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_sql_solutions');
    }
};
