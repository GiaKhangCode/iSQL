<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhãn loại câu hỏi và độ khó; thứ tự hiển thị theo độ khó rồi created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_tags', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('kind', 20);
            $table->string('name', 100);
            $table->string('part', 20)->default('ALL');
            $table->timestamps();

            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_tags');
    }
};
