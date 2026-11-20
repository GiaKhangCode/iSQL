<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nền tảng phân quyền mới (Giai đoạn 1): vai trò = tập mã quyền (capability).
 *
 * Thay thế dần ba cơ chế chạy song song (cột users.role, ma trận functions x actions,
 * danh sách capability viết cứng). Các bảng cũ `roles`, `role_groups`, `functions`... KHÔNG
 * bị đụng tới ở giai đoạn này, nên bảng mới đặt tên khác (access_*).
 *
 * - access_roles: vai trò. `slug` chỉ để seeder nhận ra 5 vai trò mặc định (mã không được
 *   so sánh theo slug/tên). `area` là khu vực giao diện của vai trò. `is_super` = siêu quản trị
 *   (luôn đủ mọi quyền, không sửa danh sách quyền).
 * - access_role_permissions: mã quyền của từng vai trò.
 * - user_access_roles: một người nhiều vai trò, quyền cộng dồn.
 * - user_permissions: quyền gán riêng cho một tài khoản, ngoài vai trò.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_roles')) {
            Schema::create('access_roles', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('slug', 50)->nullable()->unique();
                $table->string('name', 100)->unique();
                $table->string('area', 20);                       // student | teacher | office | admin
                $table->boolean('is_super')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('access_role_permissions')) {
            Schema::create('access_role_permissions', function (Blueprint $table) {
                $table->string('access_role_id', 36);
                $table->string('permission', 64);
                $table->primary(['access_role_id', 'permission']);
                $table->foreign('access_role_id')->references('id')->on('access_roles')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('user_access_roles')) {
            Schema::create('user_access_roles', function (Blueprint $table) {
                $table->string('user_id', 50);
                $table->string('access_role_id', 36);
                $table->timestamps();
                $table->primary(['user_id', 'access_role_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('access_role_id')->references('id')->on('access_roles')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('user_permissions')) {
            Schema::create('user_permissions', function (Blueprint $table) {
                $table->string('user_id', 50);
                $table->string('permission', 64);
                $table->timestamps();
                $table->primary(['user_id', 'permission']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('user_access_roles');
        Schema::dropIfExists('access_role_permissions');
        Schema::dropIfExists('access_roles');
    }
};
