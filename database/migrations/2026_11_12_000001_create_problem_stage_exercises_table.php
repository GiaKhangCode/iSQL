<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('problem_stage_exercises')) {
            Schema::create('problem_stage_exercises', function (Blueprint $table) {
                $table->string('id', 50)->primary();
                $table->string('problem_id', 50);
                $table->string('part', 10);
                $table->json('config');
                $table->timestamps();

                $table->unique(['problem_id', 'part'], 'uq_problem_stage_exercise');
                $table->index('problem_id');
                $table->foreign('problem_id')
                    ->references('id')
                    ->on('problems')
                    ->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('workspace_submission_results')) {
            Schema::create('workspace_submission_results', function (Blueprint $table) {
                $table->string('submission_id', 50)->primary();
                $table->json('sections');
                $table->timestamps();

                $table->foreign('submission_id')
                    ->references('id')
                    ->on('submissions')
                    ->onDelete('cascade');
            });
        }

        if (Schema::hasTable('database_library_entries')) {
            Schema::table('database_library_entries', function (Blueprint $table) {
                if (!Schema::hasColumn('database_library_entries', 'bundle_json')) {
                    $table->longText('bundle_json')->nullable()->after('datasets_json');
                }
                if (!Schema::hasColumn('database_library_entries', 'revision')) {
                    $table->integer('revision')->default(1)->after('bundle_json');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_submission_results');
        Schema::dropIfExists('problem_stage_exercises');

        if (Schema::hasTable('database_library_entries')) {
            Schema::table('database_library_entries', function (Blueprint $table) {
                $drop = [];
                foreach (['bundle_json', 'revision'] as $col) {
                    if (Schema::hasColumn('database_library_entries', $col)) {
                        $drop[] = $col;
                    }
                }
                if (!empty($drop)) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};

