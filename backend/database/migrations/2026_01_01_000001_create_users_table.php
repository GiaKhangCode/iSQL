<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tài khoản. `account_type` (student|staff) chỉ là loại danh tính, quyền nằm ở access_roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('email')->unique();
            $table->string('hashed_password');
            $table->string('name');
            $table->string('initials', 10);
            $table->string('account_type', 20)->default('student');
            $table->string('status', 50)->default('Active');
            $table->string('department')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->timestamps();

            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
