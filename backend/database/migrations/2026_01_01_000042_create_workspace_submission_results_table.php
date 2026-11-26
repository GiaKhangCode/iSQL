<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kết quả từng phần, từng câu của bài nộp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_submission_results', function (Blueprint $table) {
            $table->string('submission_id', 50);
            $table->json('sections');
            $table->timestamps();

            $table->primary('submission_id');

            $table->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_submission_results');
    }
};
