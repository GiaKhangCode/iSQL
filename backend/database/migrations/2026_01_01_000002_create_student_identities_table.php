<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mã số sinh viên của tài khoản sinh viên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_identities', function (Blueprint $table) {
            $table->string('user_id', 36)->primary();
            $table->string('student_code', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_identities');
    }
};
