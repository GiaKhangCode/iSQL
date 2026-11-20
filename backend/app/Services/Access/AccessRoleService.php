<?php

namespace App\Services\Access;

use App\Models\AccessRole;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Quản trị vai trò và việc gán vai trò (trang Vai trò của admin).
 *
 * Mọi thao tác ghi đều xóa bộ nhớ đệm quyền của request hiện tại để lần đọc kế tiếp thấy dữ liệu mới.
 * Lỗi nghiệp vụ ném HttpException (4xx) và được bootstrap/app.php trả về dạng {message, detail}.
 */
class AccessRoleService
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly ActivityLogService $activityLog,
    ) {
    }

    // ───────────────────────── vai trò ─────────────────────────

    /** @return array{permissions: array<string,string>, roles: array<int,array>} */
    public function catalog(): array
    {
        $counts = DB::table('user_access_roles')->select('access_role_id', DB::raw('COUNT(*) as total'))
            ->groupBy('access_role_id')->pluck('total', 'access_role_id');

        $roles = AccessRole::orderByDesc('is_super')->orderBy('name')->get()
            ->map(fn (AccessRole $role) => $this->roleData($role, (int) ($counts[$role->id] ?? 0)))
            ->all();

        return ['permissions' => PermissionCatalog::PERMISSIONS, 'roles' => $roles];
    }

    public function createRole(array $data, User $actor): array
    {
        $role = DB::transaction(function () use ($data) {
            $role = AccessRole::create(['name' => trim($data['name']), 'is_super' => false]);
            $role->syncPermissions($data['permissions']);

            return $role;
        });

        $this->activityLog->record($actor->id, 'access:save_role:' . $role->name);
        $this->access->forget();

        return $this->roleData($role, 0);
    }

    public function updateRole(string $id, array $data, User $actor): array
    {
        $role = AccessRole::find($id) ?? throw new HttpException(404, 'Không tìm thấy vai trò.');

        if ($role->is_super) {
            throw new HttpException(422, 'Vai trò Admin là siêu quản trị, luôn đủ quyền và không thể chỉnh sửa.');
        }

        DB::transaction(function () use ($role, $data) {
            $role->update(['name' => trim($data['name'])]);
            $role->syncPermissions($data['permissions']);
        });

        $this->activityLog->record($actor->id, 'access:save_role:' . $role->name);
        $this->access->forget();

        return $this->roleData($role->refresh(), $role->users()->count());
    }

    private function roleData(AccessRole $role, int $userCount): array
    {
        $permissions = $role->is_super ? PermissionCatalog::superCodes() : $role->permissionCodes();
        sort($permissions);

        return [
            'id'          => $role->id,
            'name'        => $role->name,
            'areas'       => PermissionCatalog::areasFor($permissions),
            'isSuper'     => (bool) $role->is_super,
            'permissions' => $permissions,
            'userCount'   => $userCount,
        ];
    }

    // ───────────────────────── gán cho người dùng ─────────────────────────

    public function userAccess(string $userId): array
    {
        $target = User::find($userId) ?? throw new HttpException(404, 'Không tìm thấy người dùng.');

        return $this->userData($target);
    }

    /**
     * @param string[]      $roleIds
     * @param string[]|null $directPermissions null = giữ nguyên, [] = xóa hết
     */
    public function assignRoles(string $userId, array $roleIds, ?array $directPermissions, User $actor): array
    {
        $target = User::find($userId) ?? throw new HttpException(404, 'Không tìm thấy người dùng.');
        $roleIds = array_values(array_unique($roleIds));

        $this->guardLastSuperAdmin($target, $roleIds);

        DB::transaction(function () use ($target, $roleIds, $directPermissions) {
            DB::table('user_access_roles')->where('user_id', $target->id)->delete();
            $now = now();
            $rows = array_map(fn (string $roleId) => [
                'user_id' => $target->id, 'access_role_id' => $roleId, 'created_at' => $now, 'updated_at' => $now,
            ], $roleIds);
            if ($rows) {
                DB::table('user_access_roles')->insert($rows);
            }

            if ($directPermissions !== null) {
                DB::table('user_permissions')->where('user_id', $target->id)->delete();
                $direct = array_map(fn (string $code) => [
                    'user_id' => $target->id, 'permission' => $code, 'created_at' => $now, 'updated_at' => $now,
                ], array_values(array_unique($directPermissions)));
                if ($direct) {
                    DB::table('user_permissions')->insert($direct);
                }
            }
        });

        $this->activityLog->record($actor->id, 'access:assign_roles:' . $target->id);
        $this->access->forget($target->id);

        return $this->userData($target);
    }

    /** Không được để hệ thống không còn siêu quản trị Active nào. */
    private function guardLastSuperAdmin(User $target, array $newRoleIds): void
    {
        $hadSuper = DB::table('user_access_roles as uar')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->where('uar.user_id', $target->id)->where('ar.is_super', true)->exists();
        if (!$hadSuper || $target->status !== 'Active') {
            return;
        }

        $keepsSuper = $newRoleIds && AccessRole::whereIn('id', $newRoleIds)->where('is_super', true)->exists();
        if ($keepsSuper) {
            return;
        }

        $others = DB::table('user_access_roles as uar')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->join('users as u', 'u.id', '=', 'uar.user_id')
            ->where('ar.is_super', true)->where('u.status', 'Active')->where('u.id', '!=', $target->id)
            ->exists();

        if (!$others) {
            throw new HttpException(422, 'Không thể bỏ vai trò Admin của quản trị viên đang hoạt động cuối cùng.');
        }
    }

    private function userData(User $target): array
    {
        $identity = StudentIdentity::find($target->id);
        $this->access->forget($target->id);

        return [
            'actor'              => $target->account_type,
            'studentCode'        => $identity?->student_code,
            'role_ids'           => DB::table('user_access_roles')->where('user_id', $target->id)->pluck('access_role_id')->all(),
            'direct_permissions' => DB::table('user_permissions')->where('user_id', $target->id)->orderBy('permission')->pluck('permission')->all(),
            'permissions'        => $this->access->permissionsOf($target),
            'areas'              => $this->access->areasOf($target),
        ];
    }

    // ───────────────────────── mã sinh viên ─────────────────────────

    public function setStudentCode(string $userId, string $code, User $actor): array
    {
        $target = User::find($userId) ?? throw new HttpException(404, 'Không tìm thấy người dùng.');

        if (!in_array('student', $this->access->areasOf($target), true)) {
            throw new HttpException(422, 'Hãy chọn tài khoản sinh viên.');
        }

        $taken = StudentIdentity::where('student_code', $code)->where('user_id', '!=', $target->id)->exists();
        if ($taken) {
            throw new HttpException(409, 'Mã sinh viên đã được dùng cho tài khoản khác.');
        }

        $identity = StudentIdentity::updateOrCreate(['user_id' => $target->id], ['student_code' => $code]);
        $this->activityLog->record($actor->id, 'identity:set_student_code:' . $target->id);

        return ['studentCode' => $identity->student_code];
    }
}
