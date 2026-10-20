<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. functions
        if (!Schema::hasTable('functions')) {
            Schema::create('functions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 100)->nullable()->unique();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        // 2. roles
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('function_id')->constrained('functions')->onDelete('cascade');
                $table->string('name')->nullable();
                $table->boolean('add')->default(false);
                $table->boolean('edit')->default(false);
                $table->boolean('delete')->default(false);
                $table->boolean('download')->default(false);
                $table->boolean('view')->default(false);
                $table->timestamps();
            });
        }

        // 3. role_groups
        if (!Schema::hasTable('role_groups')) {
            Schema::create('role_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        // 4. role_group_assign_role
        if (!Schema::hasTable('role_group_assign_role')) {
            Schema::create('role_group_assign_role', function (Blueprint $table) {
                $table->foreignId('role_group_id')->constrained('role_groups')->onDelete('cascade');
                $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
                $table->timestamps();
                $table->primary(['role_group_id', 'role_id']);
            });
        }

        // 5. account_assign_role_group
        if (!Schema::hasTable('account_assign_role_group')) {
            Schema::create('account_assign_role_group', function (Blueprint $table) {
                $table->string('account_id', 50);
                $table->foreign('account_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreignId('role_group_id')->constrained('role_groups')->onDelete('cascade');
                $table->timestamps();
                $table->primary(['account_id', 'role_group_id']);
            });
        }

        // 6. account_assign_role
        if (!Schema::hasTable('account_assign_role')) {
            Schema::create('account_assign_role', function (Blueprint $table) {
                $table->string('account_id', 50);
                $table->foreign('account_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
                $table->timestamps();
                $table->primary(['account_id', 'role_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_assign_role');
        Schema::dropIfExists('account_assign_role_group');
        Schema::dropIfExists('role_group_assign_role');
        Schema::dropIfExists('role_groups');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('functions');
    }
};
