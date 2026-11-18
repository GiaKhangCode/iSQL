<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_shares')) {
            return;
        }

        Schema::create('content_shares', function (Blueprint $table) {
            $table->string('kind', 20);
            $table->string('resource_id', 50);
            $table->string('recipient_id', 50);
            $table->boolean('can_edit')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['kind', 'resource_id', 'recipient_id']);
            $table->index(['recipient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_shares');
    }
};

