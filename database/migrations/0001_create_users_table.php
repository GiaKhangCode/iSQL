<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 50)->primary();                // UUID
            $table->string('email', 255)->unique();
            $table->string('hashed_password', 255);
            $table->string('name', 255);
            $table->string('initials', 10);
            $table->string('role', 50)->default('student');      // student | instructor | admin
            $table->string('status', 50)->default('Active');     // Active | Inactive | Pending
            $table->string('department', 255)->nullable();
            $table->integer('current_streak')->default(0);
            $table->integer('longest_streak')->default(0);
            $table->timestamp('last_streak_date')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->timestamps();                                // created_at, updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};