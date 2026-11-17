<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('submission_regrade_assignments') && !Schema::hasColumn('submission_regrade_assignments', 'completed_at')) {
            Schema::table('submission_regrade_assignments', function (Blueprint $table) {
                $table->timestamp('completed_at')->nullable()->after('assigned_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('submission_regrade_assignments') && Schema::hasColumn('submission_regrade_assignments', 'completed_at')) {
            Schema::table('submission_regrade_assignments', function (Blueprint $table) {
                $table->dropColumn('completed_at');
            });
        }
    }
};

