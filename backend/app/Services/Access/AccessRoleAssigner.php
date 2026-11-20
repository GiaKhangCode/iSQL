<?php

namespace App\Services\Access;

use App\Models\AccessRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gán vai trò khởi tạo cho tài khoản mới (hoặc tài khoản chưa có vai trò nào).
 *
 * Chỉ dùng để khởi tạo: sau đó admin quản lý vai trò qua trang Vai trò và code kiểm quyền không đọc
 * loại tài khoản (users.account_type) để quyết định quyền.
 */
class AccessRoleAssigner
{
    /** Slug vai trò seed dành cho người học. */
    public const STUDENT_SLUG = 'student';

    /**
     * Gán vai trò khởi tạo nếu người này chưa có vai trò nào.
     *
     * @param  string|null  $initialRole id hoặc slug của AccessRole. Không có thì tài khoản sinh viên nhận vai trò
     *                                   "Sinh viên", tài khoản nhân sự không nhận vai trò nào. Giá trị không tìm thấy
     *                                   thì không gán gì (không rơi về mặc định).
     * @return bool có gán hay không
     */
    public function assignInitial(User $user, ?string $initialRole = null): bool
    {
        if (DB::table('user_access_roles')->where('user_id', $user->id)->exists()) {
            return false;
        }

        if ($initialRole === null || $initialRole === '') {
            if ($user->account_type !== User::ACCOUNT_STUDENT) {
                return false;
            }
            $initialRole = self::STUDENT_SLUG;
        }

        $roleId = AccessRole::where('id', $initialRole)->orWhere('slug', $initialRole)->value('id');
        if ($roleId === null) {
            return false; // chưa seed vai trò hoặc không có vai trò này
        }

        DB::table('user_access_roles')->insert([
            'user_id'        => $user->id,
            'access_role_id' => $roleId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return true;
    }
}
