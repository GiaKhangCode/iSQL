<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_library_entries', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('instructor_id', 50)->nullable()->index();
            $table->string('title', 255);
            $table->string('database_file_name', 255)->nullable();
            $table->string('create_table_file_name', 255)->nullable();
            $table->string('insert_into_file_name', 255)->nullable();
            $table->longText('create_table_sql');
            $table->longText('insert_into_sql')->nullable();
            $table->longText('datasets_json')->nullable();
            $table->timestamps();

            $table->foreign('instructor_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_library_entries');
    }
};
