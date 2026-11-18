<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Trả lời "người này có quyền (capability) gì". Không bao giờ so sánh tên vai trò.
 *
 * Quyền hiệu lực = hợp của: quyền các vai trò được gán + quyền gán riêng cho tài khoản.
 * Siêu quản trị (vai trò is_super) có mọi quyền trừ PermissionCatalog::NEVER_FOR_SUPER.
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

    /** Khu vực giao diện mà người dùng được vào (thuộc tính của các vai trò được gán). */
    public function areasOf(User $user): array
    {
        return $this->resolve($user)['areas'];
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->resolve($user)['super'];
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
            ->get(['ar.id', 'ar.area', 'ar.is_super']);

        $permissions = [];
        $areas = [];
        $super = false;

        foreach ($roles as $role) {
            $areas[$role->area] = true;
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

        // Chỉ giữ mã còn nằm trong danh mục; siêu quản trị không bao giờ chấm bài.
        $permissions = array_values(array_unique(array_filter($permissions, [PermissionCatalog::class, 'isValid'])));
        if ($super) {
            $permissions = array_values(array_diff($permissions, PermissionCatalog::NEVER_FOR_SUPER));
        }
        sort($permissions);

        $areaList = array_keys($areas);
        sort($areaList);

        return $this->cache[$user->id] = ['permissions' => $permissions, 'areas' => $areaList, 'super' => $super];
    }
}
