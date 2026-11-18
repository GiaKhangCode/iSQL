<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 255)->unique();
            $table->text('description')->nullable();
            $table->string('status', 50)->default('Active');
            $table->timestamps();
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('name', 100)->unique();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 50)->default('Active');
            $table->timestamps();
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->string('course_id', 50)->nullable()->after('course');
            $table->string('term_id', 50)->nullable()->after('term');
            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
            $table->foreign('term_id')->references('id')->on('academic_terms')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropForeign(['term_id']);
            $table->dropColumn(['course_id', 'term_id']);
        });

        Schema::dropIfExists('academic_terms');
        Schema::dropIfExists('courses');
    }
};
