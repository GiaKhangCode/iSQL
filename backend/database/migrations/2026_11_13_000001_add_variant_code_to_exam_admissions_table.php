<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_admissions') && !Schema::hasColumn('exam_admissions', 'variant_code')) {
            Schema::table('exam_admissions', function (Blueprint $table) {
                $table->string('variant_code', 32)->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('exam_admissions') && Schema::hasColumn('exam_admissions', 'variant_code')) {
            Schema::table('exam_admissions', function (Blueprint $table) {
                $table->dropColumn('variant_code');
            });
        }
    }
};

