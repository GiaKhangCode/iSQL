<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kỳ thi (gồm nhiều ca thi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examinations', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('owner_id', 36)->nullable();
            $table->string('title');
            $table->string('exam_date', 16);
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->json('template')->nullable();
            $table->integer('revision')->default(1);
            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examinations');
    }
};
