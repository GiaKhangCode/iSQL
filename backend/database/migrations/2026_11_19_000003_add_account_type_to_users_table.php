<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm users.account_type (student | staff): loại danh tính của tài khoản, KHÔNG phải phân quyền.
 * Quyền nằm ở user_access_roles / user_permissions. Cột users.role cũ được bỏ ở migration kế tiếp
 * (sau khi code không còn đọc nó).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'account_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('account_type', 20)->default('student')->after('initials');
                $table->index('account_type');
            });
        }

        if (Schema::hasColumn('users', 'role')) {
            DB::table('users')->where('role', 'student')->update(['account_type' => 'student']);
            DB::table('users')->where('role', '!=', 'student')->update(['account_type' => 'staff']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'account_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['account_type']);
                $table->dropColumn('account_type');
            });
        }
    }
};
