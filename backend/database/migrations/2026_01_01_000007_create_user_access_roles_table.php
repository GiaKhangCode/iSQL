<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vai trò của từng người (một người nhiều vai trò).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_access_roles', function (Blueprint $table) {
            $table->string('user_id', 50);
            $table->string('access_role_id', 36);
            $table->timestamps();

            $table->primary(['user_id', 'access_role_id']);

            $table->foreign('access_role_id')->references('id')->on('access_roles')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_roles');
    }
};
