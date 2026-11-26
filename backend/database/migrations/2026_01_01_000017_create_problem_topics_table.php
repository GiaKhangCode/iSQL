<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chủ đề của bài toán.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_topics', function (Blueprint $table) {
            $table->string('problem_id', 50);
            $table->string('topic_id', 50);

            $table->primary(['problem_id', 'topic_id']);

            $table->foreign('problem_id')->references('id')->on('problems')->cascadeOnDelete();
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_topics');
    }
};
