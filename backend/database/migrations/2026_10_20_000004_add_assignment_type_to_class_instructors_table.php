<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('class_instructors', 'assignment_type')) {
            Schema::table('class_instructors', function (Blueprint $table): void {
                $table->string('assignment_type', 20)->default('practical');
            });
        }

        DB::table('class_instructors')
            ->whereIn('instructor_id', DB::table('users')->select('id')->where('role', 'instructor_theory'))
            ->update(['assignment_type' => 'theory']);

        // Previous versions allowed several instructors per class without a
        // position. Keep the legacy class instructor when possible, then one
        // deterministic instructor for each new position.
        DB::statement("DELETE ci FROM class_instructors AS ci INNER JOIN (SELECT assignments.class_id, assignments.assignment_type, COALESCE(MIN(CASE WHEN classes.instructor_id = assignments.instructor_id THEN assignments.instructor_id END), MIN(assignments.instructor_id)) AS keep_id FROM class_instructors AS assignments INNER JOIN classes ON classes.id = assignments.class_id GROUP BY assignments.class_id, assignments.assignment_type) AS keep_assignment ON keep_assignment.class_id = ci.class_id AND keep_assignment.assignment_type = ci.assignment_type WHERE ci.instructor_id <> keep_assignment.keep_id");

        // Keep explicit indexes for both foreign-key columns while replacing
        // the old composite primary key. The old key is the only index that
        // starts with class_id on databases where this migration was partial.
        if (! Schema::hasIndex('class_instructors', ['class_id'])) {
            Schema::table('class_instructors', function (Blueprint $table): void {
                $table->index('class_id', 'class_instructors_class_id_support_index');
            });
        }

        if (! Schema::hasIndex('class_instructors', ['class_id', 'assignment_type'], 'primary')) {
            Schema::table('class_instructors', function (Blueprint $table): void {
                $table->dropPrimary();
                $table->primary(['class_id', 'assignment_type']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('class_instructors', 'assignment_type')) {
            return;
        }

        // The old key cannot represent one instructor in both positions.
        DB::statement("DELETE theory FROM class_instructors AS theory INNER JOIN class_instructors AS practical ON practical.class_id = theory.class_id AND practical.instructor_id = theory.instructor_id AND practical.assignment_type = 'practical' WHERE theory.assignment_type = 'theory'");

        Schema::table('class_instructors', function (Blueprint $table): void {
            $table->dropPrimary();
            $table->dropColumn('assignment_type');
            $table->primary(['class_id', 'instructor_id']);
        });
    }
};
