<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Enhance assignments table
        Schema::table('assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('assignments', 'scoring_mode')) {
                $table->string('scoring_mode', 30)->default('equal_questions')->after('is_contest');
            }
            if (!Schema::hasColumn('assignments', 'exam_violation_limit')) {
                $table->integer('exam_violation_limit')->default(2)->after('exam_mode');
            }
        });

        // 2. Enhance assignment_problems table
        Schema::table('assignment_problems', function (Blueprint $table) {
            if (!Schema::hasColumn('assignment_problems', 'question_selection')) {
                $table->json('question_selection')->nullable()->after('dataset_visibility');
            }
        });

        // Modify points column to support floating/decimal scores
        try {
            DB::statement("ALTER TABLE assignment_problems MODIFY COLUMN points DECIMAL(10, 4) NOT NULL DEFAULT 10.0000");
        } catch (\Throwable) {
            // Ignore if already decimal or not supported
        }

        // 3. Create assignment_students table for individual student assignments
        if (!Schema::hasTable('assignment_students')) {
            Schema::create('assignment_students', function (Blueprint $table) {
                $table->string('assignment_id', 50);
                $table->string('student_id', 50);
                $table->timestamp('created_at')->useCurrent();

                $table->primary(['assignment_id', 'student_id']);
                $table->foreign('assignment_id')->references('id')->on('assignments')->onDelete('cascade');
                $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignment_students');

        Schema::table('assignment_problems', function (Blueprint $table) {
            if (Schema::hasColumn('assignment_problems', 'question_selection')) {
                $table->dropColumn('question_selection');
            }
        });

        Schema::table('assignments', function (Blueprint $table) {
            if (Schema::hasColumn('assignments', 'scoring_mode')) {
                $table->dropColumn('scoring_mode');
            }
            if (Schema::hasColumn('assignments', 'exam_violation_limit')) {
                $table->dropColumn('exam_violation_limit');
            }
        });
    }
};

