<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phân quyền theo năng lực: một vai trò là một tập mã quyền.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('slug', 50)->nullable()->unique();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_roles');
    }
};
