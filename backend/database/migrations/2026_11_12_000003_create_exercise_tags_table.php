<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('exercise_tags')) {
            Schema::create('exercise_tags', function (Blueprint $table) {
                $table->string('id', 50)->primary();
                $table->string('kind', 20); // 'difficulty' | 'category'
                $table->string('name', 100);
                $table->string('part', 20)->default('ALL'); // 'ALL', 'DDL', 'DML', 'DQL', 'TRIGGER'
                $table->integer('order_index')->default(0);
                $table->timestamps();

                $table->index(['kind', 'order_index']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exercise_tags');
    }
};

