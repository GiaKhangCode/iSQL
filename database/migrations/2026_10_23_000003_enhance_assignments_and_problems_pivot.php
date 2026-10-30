<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('assignments', 'exam_mode')) {
                $table->boolean('exam_mode')->default(false)->after('ai_allowed');
            }
            if (!Schema::hasColumn('assignments', 'show_scores_after_submit')) {
                $table->boolean('show_scores_after_submit')->default(true)->after('exam_mode');
            }
            if (!Schema::hasColumn('assignments', 'max_submissions')) {
                $table->integer('max_submissions')->nullable()->after('show_scores_after_submit');
            }
        });

        Schema::table('assignment_problems', function (Blueprint $table) {
            if (!Schema::hasColumn('assignment_problems', 'workspace_parts')) {
                $table->json('workspace_parts')->nullable()->after('order_index');
            }
            if (!Schema::hasColumn('assignment_problems', 'dataset_visibility')) {
                $table->json('dataset_visibility')->nullable()->after('workspace_parts');
            }
            if (!Schema::hasColumn('assignment_problems', 'database_structure_visible')) {
                $table->boolean('database_structure_visible')->default(true)->after('dataset_visibility');
            }
            if (!Schema::hasColumn('assignment_problems', 'database_description_visible')) {
                $table->boolean('database_description_visible')->default(true)->after('database_structure_visible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn(['exam_mode', 'show_scores_after_submit', 'max_submissions']);
        });

        Schema::table('assignment_problems', function (Blueprint $table) {
            $table->dropColumn(['workspace_parts', 'dataset_visibility', 'database_structure_visible', 'database_description_visible']);
        });
    }
};
