<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('problems', function (Blueprint $table) {
            if (!Schema::hasColumn('problems', 'workspace_parts')) {
                $table->json('workspace_parts')->nullable()->after('database_type');
            }
            if (!Schema::hasColumn('problems', 'schema_file_name')) {
                $table->string('schema_file_name', 255)->nullable()->default('database.sql')->after('workspace_parts');
            }
            if (!Schema::hasColumn('problems', 'database_description')) {
                $table->json('database_description')->nullable()->after('schema_file_name');
            }
            if (!Schema::hasColumn('problems', 'database_description_visible')) {
                $table->boolean('database_description_visible')->default(true)->after('database_description');
            }
            if (!Schema::hasColumn('problems', 'created_at')) {
                $table->timestamp('created_at')->nullable()->useCurrent()->after('test_cases');
            }
            if (!Schema::hasColumn('problems', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate()->after('created_at');
            }
        });

        if (!Schema::hasTable('problem_sql_solutions')) {
            Schema::create('problem_sql_solutions', function (Blueprint $table) {
                $table->string('id', 50)->primary();
                $table->string('problem_id', 50);
                $table->string('name', 120);
                $table->longText('query');
                $table->text('description')->nullable();
                $table->json('expected')->nullable();
                $table->boolean('enabled')->default(true);
                $table->integer('time_limit_ms')->default(5000);
                $table->integer('order_index')->default(0);
                $table->timestamps();

                $table->foreign('problem_id')->references('id')->on('problems')->onDelete('cascade');
                $table->index('problem_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_sql_solutions');

        Schema::table('problems', function (Blueprint $table) {
            $dropColumns = [];
            foreach (['workspace_parts', 'schema_file_name', 'database_description', 'database_description_visible', 'created_at', 'updated_at'] as $col) {
                if (Schema::hasColumn('problems', 'col')) {
                    $dropColumns[] = $col;
                }
            }
            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
