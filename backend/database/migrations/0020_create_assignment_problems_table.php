<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_problems', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('assignment_id', 50);
            $table->string('problem_id', 50);
            $table->integer('points')->default(10);
            $table->integer('order_index')->default(0);

            $table->foreign('assignment_id')->references('id')->on('assignments')->onDelete('cascade');
            $table->foreign('problem_id')->references('id')->on('problems')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_problems');
    }
};