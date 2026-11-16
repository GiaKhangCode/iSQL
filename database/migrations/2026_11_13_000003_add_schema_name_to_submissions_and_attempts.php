<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('submissions') && !Schema::hasColumn('submissions', 'schema_name')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->string('schema_name', 64)->nullable()->after('database_type');
                $table->index('schema_name');
            });
        }

        if (Schema::hasTable('activity_attempts') && !Schema::hasColumn('activity_attempts', 'schema_name')) {
            Schema::table('activity_attempts', function (Blueprint $table) {
                $table->string('schema_name', 64)->nullable()->after('status');
                $table->index('schema_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('submissions') && Schema::hasColumn('submissions', 'schema_name')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->dropIndex(['schema_name']);
                $table->dropColumn('schema_name');
            });
        }

        if (Schema::hasTable('activity_attempts') && Schema::hasColumn('activity_attempts', 'schema_name')) {
            Schema::table('activity_attempts', function (Blueprint $table) {
                $table->dropIndex(['schema_name']);
                $table->dropColumn('schema_name');
            });
        }
    }
};

