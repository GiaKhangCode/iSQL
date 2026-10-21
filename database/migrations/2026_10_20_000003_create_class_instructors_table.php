<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_instructors', function (Blueprint $table) {
            $table->string('class_id', 50);
            $table->string('instructor_id', 50);
            $table->timestamp('assigned_at')->useCurrent();
            $table->primary(['class_id', 'instructor_id']);
            $table->index('instructor_id');
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
            $table->foreign('instructor_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::table('classes')->whereNotNull('instructor_id')->orderBy('id')->chunk(500, function ($classes): void {
            foreach ($classes as $class) {
                DB::table('class_instructors')->insertOrIgnore([
                    'class_id' => $class->id,
                    'instructor_id' => $class->instructor_id,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_instructors');
    }
};
