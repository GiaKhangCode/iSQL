<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mã quyền của vai trò.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_role_permissions', function (Blueprint $table) {
            $table->string('access_role_id', 36);
            $table->string('permission', 64);

            $table->primary(['access_role_id', 'permission']);

            $table->foreign('access_role_id')->references('id')->on('access_roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_role_permissions');
    }
};
