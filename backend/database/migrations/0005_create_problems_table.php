<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problems', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('number', 50);
            $table->string('title', 255);
            $table->string('topic', 100)->nullable();
            $table->json('topics')->nullable();                  // Danh sách topic dạng JSON array
            $table->string('difficulty', 50);                    // Easy | Medium | Hard
            $table->text('description');
            $table->text('requirements');
            $table->text('hint')->nullable();
            $table->boolean('practice_listed')->default(true);
            $table->string('database_type', 50)->default('SQL Server');
            $table->string('creator_id', 50)->nullable();
            $table->json('tables')->nullable();                  // Định nghĩa DataTable dạng JSON
            $table->json('expected')->nullable();                // Kết quả mẫu dạng JSON
            $table->json('test_cases')->nullable();              // Test case Legacy dạng JSON

            $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problems');
    }
};