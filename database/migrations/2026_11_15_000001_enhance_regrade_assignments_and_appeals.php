<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('submission_appeals')) {
            DB::table('submission_appeals')
                ->whereNotIn('submission_id', DB::table('submissions')->select('id'))
                ->delete();
            DB::table('submission_appeals')
                ->whereNotIn('student_id', DB::table('users')->select('id'))
                ->delete();

            Schema::table('submission_appeals', function (Blueprint $table) {
                $table->string('submission_id', 50)->change();
                $table->string('student_id', 50)->change();
                if (!Schema::hasColumn('submission_appeals', 'previous_evaluated_score')) {
                    $table->decimal('previous_evaluated_score', 8, 2)->nullable()->after('status');
                }
            });

            Schema::table('submission_appeals', function (Blueprint $table) {
                $table->foreign('submission_id', 'fk_submission_appeals_submission')
                    ->references('id')->on('submissions')
                    ->cascadeOnDelete();
                $table->foreign('student_id', 'fk_submission_appeals_student')
                    ->references('id')->on('users')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('submission_regrade_assignments')) {
            DB::table('submission_regrade_assignments')
                ->whereNotIn('submission_id', DB::table('submissions')->select('id'))
                ->delete();
            DB::table('submission_regrade_assignments')
                ->whereNotIn('assigned_instructor_id', DB::table('users')->select('id'))
                ->delete();
            DB::table('submission_regrade_assignments')
                ->whereNotNull('assigned_by_id')
                ->whereNotIn('assigned_by_id', DB::table('users')->select('id'))
                ->update(['assigned_by_id' => null]);

            Schema::table('submission_regrade_assignments', function (Blueprint $table) {
                $table->string('submission_id', 50)->change();
                $table->string('assigned_instructor_id', 50)->change();
                $table->string('assigned_by_id', 50)->nullable()->change();
                if (!Schema::hasColumn('submission_regrade_assignments', 'previous_evaluated_score')) {
                    $table->decimal('previous_evaluated_score', 8, 2)->nullable()->after('note');
                }
                if (!Schema::hasColumn('submission_regrade_assignments', 'previous_feedback')) {
                    $table->text('previous_feedback')->nullable()->after('previous_evaluated_score');
                }
            });

            Schema::table('submission_regrade_assignments', function (Blueprint $table) {
                $table->foreign('submission_id', 'fk_regrade_assignments_submission')
                    ->references('id')->on('submissions')
                    ->cascadeOnDelete();
                $table->foreign('assigned_instructor_id', 'fk_regrade_assignments_instructor')
                    ->references('id')->on('users')
                    ->cascadeOnDelete();
                $table->foreign('assigned_by_id', 'fk_regrade_assignments_assigned_by')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('submission_regrade_assignments')) {
            Schema::table('submission_regrade_assignments', function (Blueprint $table) {
                $table->dropForeign('fk_regrade_assignments_submission');
                $table->dropForeign('fk_regrade_assignments_instructor');
                $table->dropForeign('fk_regrade_assignments_assigned_by');
                if (Schema::hasColumn('submission_regrade_assignments', 'previous_feedback')) {
                    $table->dropColumn('previous_feedback');
                }
                if (Schema::hasColumn('submission_regrade_assignments', 'previous_evaluated_score')) {
                    $table->dropColumn('previous_evaluated_score');
                }
            });
        }

        if (Schema::hasTable('submission_appeals')) {
            Schema::table('submission_appeals', function (Blueprint $table) {
                $table->dropForeign('fk_submission_appeals_submission');
                $table->dropForeign('fk_submission_appeals_student');
                if (Schema::hasColumn('submission_appeals', 'previous_evaluated_score')) {
                    $table->dropColumn('previous_evaluated_score');
                }
            });
        }
    }
};

