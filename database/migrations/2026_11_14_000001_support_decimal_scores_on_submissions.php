<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->decimal('score', 8, 2)->default(0)->change();
            $table->decimal('evaluated_score', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->integer('score')->default(0)->change();
            $table->integer('evaluated_score')->nullable()->change();
        });
    }
};

