<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_case_scripts', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('test_case_id', 50);
            $table->string('table_name', 100);
            $table->text('create_script');
            $table->text('insert_script');
            $table->boolean('is_expected')->default(false);

            $table->foreign('test_case_id')->references('id')->on('test_cases_rel')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_case_scripts');
    }
};