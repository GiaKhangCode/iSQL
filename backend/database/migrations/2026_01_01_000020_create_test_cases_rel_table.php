<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bộ dữ liệu kiểm tra của bài toán.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_cases_rel', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('problem_id', 50);
            $table->boolean('is_hidden')->default(false);
            $table->integer('order_index')->default(0);
            $table->text('expected_query')->nullable();

            $table->index('problem_id');

            $table->foreign('problem_id')->references('id')->on('problems')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_cases_rel');
    }
};
