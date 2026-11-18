<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('session_id', 50);
            $table->string('role', 10);                          // 'user' | 'ai'
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('session_id')->references('id')->on('ai_chat_sessions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};