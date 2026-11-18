<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->string('user_id', 50);
            $table->string('problem_id', 50);
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'problem_id']);

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('problem_id')->references('id')->on('problems');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};