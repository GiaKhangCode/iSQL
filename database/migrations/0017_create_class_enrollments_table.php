<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_enrollments', function (Blueprint $table) {
            $table->string('class_id', 50);
            $table->string('student_id', 50);
            $table->string('role', 50)->default('Member');
            $table->timestamp('joined_at')->useCurrent();
            $table->primary(['class_id', 'student_id']);

            $table->foreign('class_id')->references('id')->on('classes')->onDelete('cascade');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_enrollments');
    }
};