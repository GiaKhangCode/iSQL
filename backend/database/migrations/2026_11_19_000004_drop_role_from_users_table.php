<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ cột users.role (tên vai trò kiểu cũ, từng bị dùng như phân quyền). Quyền nằm ở vai trò (user_access_roles),
 * loại danh tính nằm ở users.account_type (migration 2026_11_19_000003).
 *
 * down() chỉ dựng lại cột và suy giá trị gần đúng từ account_type và vai trò đang giữ (không khôi phục chính xác
 * những khác biệt như instructor_practical / instructor_theory).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('student')->after('initials');
        });

        DB::table('users')->where('account_type', 'student')->update(['role' => 'student']);

        $slugToRole = [
            'admin'           => 'admin',
            'faculty_office'  => 'faculty_office',
            'department_head' => 'department_head',
            'instructor'      => 'instructor_practical',
        ];
        foreach ($slugToRole as $slug => $legacy) {
            $ids = DB::table('user_access_roles as uar')
                ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
                ->where('ar.slug', $slug)
                ->pluck('uar.user_id');
            if ($ids->isNotEmpty()) {
                DB::table('users')->whereIn('id', $ids)->where('account_type', 'staff')->update(['role' => $legacy]);
            }
        }
        // Nhân sự không khớp vai trò nào: coi là giảng viên thực hành (giá trị gần đúng).
        DB::table('users')->where('account_type', 'staff')->where('role', 'student')->update(['role' => 'instructor_practical']);
    }
};
