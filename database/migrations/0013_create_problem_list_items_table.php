<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_list_items', function (Blueprint $table) {
            $table->string('list_id', 50);
            $table->string('problem_id', 50);
            $table->primary(['list_id', 'problem_id']);

            $table->foreign('list_id')->references('id')->on('problem_lists')->onDelete('cascade');
            $table->foreign('problem_id')->references('id')->on('problems')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_list_items');
    }
};