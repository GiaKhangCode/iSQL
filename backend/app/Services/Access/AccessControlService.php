<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Trả lời "người này có quyền (capability) gì". Không bao giờ so sánh tên vai trò.
 *
 * Quyền hiệu lực = hợp của: quyền các vai trò được gán + quyền gán riêng cho tài khoản. Không có vai trò nào
 * tự cấp mọi quyền: mỗi vai trò chỉ có đúng các mã quyền đã lưu cho nó.
 * Mỗi vai trò là một khu vực giao diện (frontend lọc tab theo quyền của vai trò), nên ngoài danh sách quyền
 * còn trả về từng vai trò kèm quyền của nó (rolesOf). Quyền gán riêng nằm ngoài các vai trò.
 * Tài khoản không Active không có quyền nào. Kết quả được nhớ theo từng request (scoped).
 */
class AccessControlService
{
    /** @var array<string, array{permissions: string[], direct: string[], roles: array<int, array{id: string, name: string, permissions: string[]}>}> */
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

    /**
     * Các vai trò người dùng đang giữ, mỗi vai trò kèm mã quyền của nó (sắp theo tên vai trò).
     * Mỗi vai trò là một khu vực giao diện.
     *
     * @return array<int, array{id: string, name: string, permissions: string[]}>
     */
    public function rolesOf(User $user): array
    {
        return $this->resolve($user)['roles'];
    }

    /** Quyền gán riêng cho tài khoản (user_permissions), ngoài vai trò. */
    public function directPermissionsOf(User $user): array
    {
        return $this->resolve($user)['direct'];
    }

    /**
     * Truy vấn con: id người dùng được cấp một quyền qua vai trò hoặc quyền gán riêng.
     * Dùng cho `whereIn('users.id', ...)`. Không lọc trạng thái tài khoản; người gọi tự thêm `status = Active`.
     */
    public function holdersOf(string $permission): \Illuminate\Database\Query\Builder
    {
        $viaRole = DB::table('user_access_roles as uar')
            ->join('access_role_permissions as arp', 'arp.access_role_id', '=', 'uar.access_role_id')
            ->where('arp.permission', $permission)
            ->select('uar.user_id');

        return DB::table('user_permissions')
            ->where('permission', $permission)
            ->select('user_id')
            ->union($viaRole);
    }

    /** Còn tài khoản Active nào (khác $exceptUserId, nếu có) đang giữ quyền này không? */
    public function hasActiveHolder(string $permission, ?string $exceptUserId = null): bool
    {
        $query = DB::table('users')->where('status', 'Active')->whereIn('id', $this->holdersOf($permission));
        if ($exceptUserId !== null) {
            $query->where('id', '!=', $exceptUserId);
        }

        return $query->exists();
    }

    /**
     * Người này là người Active duy nhất còn giữ quyền access.manage? Khi đó không được xóa, khóa hay bỏ vai trò
     * của họ, vì sẽ không còn ai vào được trang Vai trò.
     */
    public function isLastActiveAccessManager(User $user): bool
    {
        return $user->status === 'Active'
            && $this->can($user, 'access.manage')
            && ! $this->hasActiveHolder('access.manage', $user->id);
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
            return $this->cache[$user->id] = ['permissions' => [], 'direct' => [], 'roles' => []];
        }

        $valid = fn (array $codes) => array_values(array_unique(array_filter($codes, [PermissionCatalog::class, 'isValid'])));

        $roleRows = DB::table('user_access_roles as uar')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->where('uar.user_id', $user->id)
            ->orderBy('ar.name')
            ->get(['ar.id', 'ar.name']);

        $byRole = [];
        if ($roleRows->isNotEmpty()) {
            foreach (DB::table('access_role_permissions')->whereIn('access_role_id', $roleRows->pluck('id')->all())->get(['access_role_id', 'permission']) as $row) {
                $byRole[$row->access_role_id][] = $row->permission;
            }
        }

        $roles = [];
        $rolePermissions = [];
        foreach ($roleRows as $role) {
            $codes = $valid($byRole[$role->id] ?? []);
            sort($codes);
            $roles[] = ['id' => (string) $role->id, 'name' => $role->name, 'permissions' => $codes];
            $rolePermissions = array_merge($rolePermissions, $codes);
        }

        $direct = $valid(DB::table('user_permissions')->where('user_id', $user->id)->pluck('permission')->all());
        sort($direct);

        // Quyền hiệu lực = quyền từ vai trò + quyền gán riêng (chỉ giữ mã còn nằm trong danh mục).
        $permissions = $valid(array_merge($rolePermissions, $direct));
        sort($permissions);

        return $this->cache[$user->id] = ['permissions' => $permissions, 'direct' => $direct, 'roles' => $roles];
    }
}
