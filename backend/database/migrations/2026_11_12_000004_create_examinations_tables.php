<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('examinations')) {
            Schema::create('examinations', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('owner_id', 36)->nullable()->index();
                $table->string('title', 255);
                $table->string('exam_date', 16);
                $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
                $table->json('template')->nullable();
                $table->integer('revision')->default(1);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('assignments')) {
            Schema::table('assignments', function (Blueprint $table) {
                if (!Schema::hasColumn('assignments', 'examination_id')) {
                    $table->string('examination_id', 36)->nullable()->index()->after('instructor_id');
                }
                if (!Schema::hasColumn('assignments', 'session_index')) {
                    $table->integer('session_index')->nullable()->after('examination_id');
                }
                if (!Schema::hasColumn('assignments', 'proctors')) {
                    $table->json('proctors')->nullable()->after('session_index');
                }
                if (!Schema::hasColumn('assignments', 'room')) {
                    $table->string('room', 255)->nullable()->after('proctors');
                }
                if (!Schema::hasColumn('assignments', 'capacity')) {
                    $table->integer('capacity')->nullable()->after('room');
                }
                if (!Schema::hasColumn('assignments', 'admission_required')) {
                    $table->boolean('admission_required')->default(false)->after('capacity');
                }
            });
        }

        if (Schema::hasTable('classes')) {
            Schema::table('classes', function (Blueprint $table) {
                if (!Schema::hasColumn('classes', 'course_code')) {
                    $table->string('course_code', 64)->nullable()->after('course_id');
                }
                if (!Schema::hasColumn('classes', 'theory_class')) {
                    $table->string('theory_class', 128)->nullable()->after('course_code');
                }
                if (!Schema::hasColumn('classes', 'practice_class')) {
                    $table->string('practice_class', 128)->nullable()->after('theory_class');
                }
                if (!Schema::hasColumn('classes', 'practice_instructor_id')) {
                    $table->string('practice_instructor_id', 36)->nullable()->after('instructor_id');
                }
            });
        }

        if (!Schema::hasTable('exam_session_plans')) {
            Schema::create('exam_session_plans', function (Blueprint $table) {
                $table->string('session_id', 36)->primary();
                $table->timestamp('confirmed_at')->nullable();
                $table->string('confirmed_by', 36)->nullable();
                $table->timestamp('reopened_at')->nullable();
                $table->text('reopen_reason')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('exam_class_proctors')) {
            Schema::create('exam_class_proctors', function (Blueprint $table) {
                $table->string('session_id', 36);
                $table->string('class_id', 36);
                $table->string('proctor_id', 36);
                $table->timestamp('assigned_at')->nullable();
                $table->primary(['session_id', 'class_id', 'proctor_id'], 'exam_class_proctors_pk');
            });
        }

        if (!Schema::hasTable('exam_admissions')) {
            Schema::create('exam_admissions', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('examination_id', 36)->nullable()->index();
                $table->string('session_id', 36)->index();
                $table->string('student_id', 36)->index();
                $table->string('username', 128)->unique();
                $table->string('hashed_password', 255);
                $table->string('initial_password', 128)->nullable();
                $table->string('status', 32)->default('issued');
                $table->integer('generation')->default(1);
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->text('reason')->nullable();
                $table->timestamps();
                $table->unique(['session_id', 'student_id'], 'exam_admissions_session_student_uq');
            });
        }

        if (!Schema::hasTable('exam_access_states')) {
            Schema::create('exam_access_states', function (Blueprint $table) {
                $table->string('session_id', 36);
                $table->string('student_id', 36);
                $table->boolean('locked')->default(false);
                $table->string('lock_reason', 64)->nullable();
                $table->timestamp('incident_at')->nullable();
                $table->timestamp('unlocked_at')->nullable();
                $table->string('unlocked_by', 36)->nullable();
                $table->timestamps();
                $table->primary(['session_id', 'student_id'], 'exam_access_states_pk');
            });
        }

        if (!Schema::hasTable('exam_browser_sessions')) {
            Schema::create('exam_browser_sessions', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('admission_id', 36)->index();
                $table->string('session_id', 36)->index();
                $table->string('student_id', 36)->index();
                $table->string('browser_code', 64)->unique();
                $table->string('status', 32)->default('active');
                $table->text('user_agent')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->string('approved_by', 36)->nullable();
                $table->text('approval_reason')->nullable();
            });
        }

        if (!Schema::hasTable('student_identities')) {
            Schema::create('student_identities', function (Blueprint $table) {
                $table->string('user_id', 36)->primary();
                $table->string('student_code', 64)->unique();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('submission_appeals')) {
            Schema::create('submission_appeals', function (Blueprint $table) {
                $table->string('submission_id', 36)->primary();
                $table->string('student_id', 36)->index();
                $table->text('reason');
                $table->string('status', 32)->default('pending');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
            });
        }

        if (!Schema::hasTable('submission_regrade_assignments')) {
            Schema::create('submission_regrade_assignments', function (Blueprint $table) {
                $table->string('submission_id', 36)->primary();
                $table->string('assigned_instructor_id', 36)->index();
                $table->string('assigned_by_id', 36)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('assigned_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_regrade_assignments');
        Schema::dropIfExists('submission_appeals');
        Schema::dropIfExists('student_identities');
        Schema::dropIfExists('exam_browser_sessions');
        Schema::dropIfExists('exam_access_states');
        Schema::dropIfExists('exam_admissions');
        Schema::dropIfExists('exam_class_proctors');
        Schema::dropIfExists('exam_session_plans');
        Schema::dropIfExists('examinations');
    }
};

