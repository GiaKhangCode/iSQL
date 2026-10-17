<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('user_id', 50);
            $table->string('problem_id', 50);
            $table->string('source', 100);                       // Practice | Assignments | Contests
            $table->string('context', 255);                      // ID của Assignment/Contest
            $table->string('result', 100);                       // Accepted | Wrong Answer | Partial | Error
            $table->integer('score')->default(0);
            $table->timestamp('submitted_at')->useCurrent();
            $table->text('query_text')->nullable();
            $table->string('database_type', 50)->nullable();
            $table->integer('evaluated_score')->nullable();
            $table->text('feedback')->nullable();                 // Scorecard JSON khi thi

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('problem_id')->references('id')->on('problems');
            $table->index(['user_id', 'problem_id']);
            $table->index('context');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};