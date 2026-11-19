<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Xóa các bảng của ma trận phân quyền cũ (functions x actions). Code dùng chúng đã bị gỡ;
 * quyền hiện nằm ở access_roles, access_role_permissions, user_access_roles, user_permissions.
 *
 * down() chỉ dựng lại cấu trúc rỗng, dữ liệu không khôi phục được.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bảng nối trước, bảng được tham chiếu sau (khóa ngoại).
        Schema::dropIfExists('account_assign_role');
        Schema::dropIfExists('account_assign_role_group');
        Schema::dropIfExists('role_group_assign_role');
        Schema::dropIfExists('role_groups');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('functions');
    }

    public function down(): void
    {
        if (! Schema::hasTable('functions')) {
            Schema::create('functions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 100)->nullable()->unique();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('roles')) {
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

        if (! Schema::hasTable('role_groups')) {
            Schema::create('role_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('role_group_assign_role')) {
            Schema::create('role_group_assign_role', function (Blueprint $table) {
                $table->foreignId('role_group_id')->constrained('role_groups')->onDelete('cascade');
                $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
                $table->timestamps();
                $table->primary(['role_group_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('account_assign_role_group')) {
            Schema::create('account_assign_role_group', function (Blueprint $table) {
                $table->string('account_id', 50);
                $table->foreign('account_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreignId('role_group_id')->constrained('role_groups')->onDelete('cascade');
                $table->timestamps();
                $table->primary(['account_id', 'role_group_id']);
            });
        }

        if (! Schema::hasTable('account_assign_role')) {
            Schema::create('account_assign_role', function (Blueprint $table) {
                $table->string('account_id', 50);
                $table->foreign('account_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
                $table->timestamps();
                $table->primary(['account_id', 'role_id']);
            });
        }
    }
};
