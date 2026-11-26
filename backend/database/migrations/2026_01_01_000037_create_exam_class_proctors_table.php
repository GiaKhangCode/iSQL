<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giám thị theo ca và lớp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_class_proctors', function (Blueprint $table) {
            $table->string('session_id', 36);
            $table->string('class_id', 36);
            $table->string('proctor_id', 36);
            $table->timestamp('assigned_at')->nullable();

            $table->primary(['session_id', 'class_id', 'proctor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_class_proctors');
    }
};
