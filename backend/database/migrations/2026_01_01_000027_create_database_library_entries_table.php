<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thư viện cơ sở dữ liệu mẫu của giảng viên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_library_entries', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('instructor_id', 50)->nullable();
            $table->string('title');
            $table->string('database_file_name')->nullable();
            $table->string('create_table_file_name')->nullable();
            $table->string('insert_into_file_name')->nullable();
            $table->longText('create_table_sql');
            $table->longText('insert_into_sql')->nullable();
            $table->longText('datasets_json')->nullable();
            $table->longText('bundle_json')->nullable();
            $table->integer('revision')->default(1);
            $table->timestamps();

            $table->index('instructor_id');

            $table->foreign('instructor_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_library_entries');
    }
};
