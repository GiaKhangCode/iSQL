<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinh viên được giao riêng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_students', function (Blueprint $table) {
            $table->string('assignment_id', 50);
            $table->string('student_id', 50);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['assignment_id', 'student_id']);

            $table->foreign('assignment_id')->references('id')->on('assignments')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_students');
    }
};
