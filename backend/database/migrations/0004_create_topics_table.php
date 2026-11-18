<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('name', 100)->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};