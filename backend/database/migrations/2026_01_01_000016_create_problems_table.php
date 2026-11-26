<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài toán SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problems', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('number', 50);
            $table->string('title');
            $table->string('topic', 100)->nullable();
            $table->json('topics')->nullable();
            $table->string('difficulty', 50);
            $table->text('description');
            $table->text('requirements');
            $table->text('hint')->nullable();
            $table->boolean('practice_listed')->default(true);
            $table->string('database_type', 50)->default('SQL Server');
            $table->json('workspace_parts')->nullable();
            $table->string('schema_file_name')->nullable()->default('database.sql');
            $table->json('database_description')->nullable();
            $table->boolean('database_description_visible')->default(true);
            $table->string('creator_id', 50)->nullable();
            $table->json('tables')->nullable();
            $table->json('expected')->nullable();
            $table->json('test_cases')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();

            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problems');
    }
};
