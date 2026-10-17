<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('course', 255);                       // Tên môn: "Cơ sở dữ liệu"
            $table->string('term', 100);                         // Học kỳ: "HK1 2026-2027"
            $table->string('instructor_id', 50)->nullable();
            $table->string('start_date', 50)->nullable();
            $table->string('end_date', 50)->nullable();
            $table->string('status', 50)->default('Active');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('instructor_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};