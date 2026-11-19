<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Trả lời "người này có quyền (capability) gì". Không bao giờ so sánh tên vai trò.
 *
 * Quyền hiệu lực = hợp của: quyền các vai trò được gán + quyền gán riêng cho tài khoản.
 * Vai trò siêu quản trị (is_super) tự cấp mọi quyền trừ PermissionCatalog::NEVER_FOR_SUPER; quyền đó chỉ có khi
 * một vai trò khác hoặc quyền gán riêng của chính tài khoản cấp (cộng gộp thật: không bao giờ bị trừ đi).
 * Tài khoản không Active không có quyền nào. Kết quả được nhớ theo từng request (scoped).
 */
class AccessControlService
{
    /** @var array<string, array{permissions: string[], areas: string[], super: bool}> */
    protected array $cache = [];

    /** @return string[] mã quyền hiệu lực, đã sắp xếp */
    public function permissionsOf(User $user): array
    {
        return $this->resolve($user)['permissions'];
    }

    public function can(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsOf($user), true);
    }

    /** @param string[] $permissions có ít nhất một quyền trong danh sách */
    public function canAny(User $user, array $permissions): bool
    {
        return (bool) array_intersect($permissions, $this->permissionsOf($user));
    }

    /** Khu vực giao diện mà người dùng được vào, suy ra từ quyền hiệu lực (xem PermissionCatalog::areasFor). */
    public function areasOf(User $user): array
    {
        return $this->resolve($user)['areas'];
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->resolve($user)['super'];
    }

    /**
     * Truy vấn con: id người dùng được cấp một quyền qua vai trò (không phải siêu quản trị) hoặc quyền gán riêng.
     * Dùng cho `whereIn('users.id', ...)`. Không lọc trạng thái tài khoản; người gọi tự thêm `status = Active`.
     * Siêu quản trị không nằm trong kết quả (họ tự có mọi quyền, xem superAdminIds()).
     */
    public function holdersOf(string $permission): \Illuminate\Database\Query\Builder
    {
        $viaRole = DB::table('user_access_roles as uar')
            ->join('access_role_permissions as arp', 'arp.access_role_id', '=', 'uar.access_role_id')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->where('ar.is_super', false)
            ->where('arp.permission', $permission)
            ->select('uar.user_id');

        return DB::table('user_permissions')
            ->where('permission', $permission)
            ->select('user_id')
            ->union($viaRole);
    }

    /** Người này là siêu quản trị Active duy nhất còn lại? (không được xóa, khóa hay bỏ vai trò của họ). */
    public function isLastActiveSuperAdmin(User $user): bool
    {
        if ($user->status !== 'Active') {
            return false;
        }

        $isSuper = DB::table('user_access_roles as uar')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->where('uar.user_id', $user->id)->where('ar.is_super', true)
            ->exists();
        if (! $isSuper) {
            return false;
        }

        return ! DB::table('user_access_roles as uar')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->join('users as u', 'u.id', '=', 'uar.user_id')
            ->where('ar.is_super', true)->where('u.status', 'Active')->where('u.id', '!=', $user->id)
            ->exists();
    }

    /** Truy vấn con: id người dùng đang giữ một vai trò siêu quản trị. */
    public function superAdminIds(): \Illuminate\Database\Query\Builder
    {
        return DB::table('user_access_roles as uar')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->where('ar.is_super', true)
            ->select('uar.user_id');
    }

    public function forget(?string $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$userId]);
        }
    }

    private function resolve(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        if ($user->status !== 'Active') {
            return $this->cache[$user->id] = ['permissions' => [], 'areas' => [], 'super' => false];
        }

        $roles = DB::table('user_access_roles as uar')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->where('uar.user_id', $user->id)
            ->get(['ar.id', 'ar.is_super']);

        $permissions = [];
        $super = false;

        foreach ($roles as $role) {
            if ($role->is_super) {
                $super = true;
            }
        }

        $roleIds = $roles->where('is_super', false)->pluck('id')->all();
        if ($roleIds) {
            $permissions = DB::table('access_role_permissions')
                ->whereIn('access_role_id', $roleIds)
                ->pluck('permission')
                ->all();
        }

        $direct = DB::table('user_permissions')->where('user_id', $user->id)->pluck('permission')->all();
        $permissions = array_merge($permissions, $direct);

        if ($super) {
            $permissions = array_merge($permissions, PermissionCatalog::superCodes());
        }

        // Chỉ giữ mã còn nằm trong danh mục.
        $permissions = array_values(array_unique(array_filter($permissions, [PermissionCatalog::class, 'isValid'])));
        sort($permissions);

        // Khu vực suy ra từ quyền hiệu lực (gồm cả quyền gán riêng), không phải thuộc tính do admin đặt.
        return $this->cache[$user->id] = ['permissions' => $permissions, 'areas' => PermissionCatalog::areasFor($permissions), 'super' => $super];
    }
}
