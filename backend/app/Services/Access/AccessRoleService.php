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

        $roles = AccessRole::orderBy('name')->get()
            ->map(fn (AccessRole $role) => $this->roleData($role, (int) ($counts[$role->id] ?? 0)))
            ->all();

        return ['permissions' => PermissionCatalog::PERMISSIONS, 'roles' => $roles];
    }

    public function createRole(array $data, User $actor): array
    {
        $role = DB::transaction(function () use ($data) {
            $role = AccessRole::create(['name' => trim($data['name'])]);
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

        $hadAccessManage = in_array('access.manage', $role->permissionCodes(), true);

        DB::transaction(function () use ($role, $data, $hadAccessManage) {
            $role->update(['name' => trim($data['name'])]);
            $role->syncPermissions($data['permissions']);

            // Bỏ access.manage khỏi vai trò không được làm hệ thống không còn ai vào được trang Vai trò.
            if ($hadAccessManage && ! in_array('access.manage', $data['permissions'], true) && ! $this->access->hasActiveHolder('access.manage')) {
                $this->access->forget();
                throw new HttpException(422, 'Không thể bỏ quyền quản lý vai trò: sẽ không còn quản trị viên đang hoạt động nào giữ quyền này.');
            }
        });

        $this->activityLog->record($actor->id, 'access:save_role:' . $role->name);
        $this->access->forget();

        return $this->roleData($role->refresh(), $role->users()->count());
    }

    private function roleData(AccessRole $role, int $userCount): array
    {
        $permissions = $role->permissionCodes();
        sort($permissions);

        return [
            'id'          => $role->id,
            'name'        => $role->name,
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

        $wasAccessManager = $this->access->can($target, 'access.manage');

        DB::transaction(function () use ($target, $roleIds, $directPermissions, $wasAccessManager) {
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

            // Người giữ access.manage cuối cùng không được bị bỏ vai trò/quyền đó.
            $this->access->forget($target->id);
            if ($wasAccessManager && ! $this->access->can($target, 'access.manage') && ! $this->access->hasActiveHolder('access.manage', $target->id)) {
                $this->access->forget($target->id); // giao dịch sắp bị hủy: không để lại quyền đã tính theo dữ liệu bị hủy
                throw new HttpException(422, 'Không thể bỏ quyền quản lý vai trò của quản trị viên đang hoạt động cuối cùng.');
            }
        });

        $this->activityLog->record($actor->id, 'access:assign_roles:' . $target->id);
        $this->access->forget($target->id);

        return $this->userData($target);
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
        ];
    }

    // ───────────────────────── mã sinh viên ─────────────────────────

    public function setStudentCode(string $userId, string $code, User $actor): array
    {
        $target = User::find($userId) ?? throw new HttpException(404, 'Không tìm thấy người dùng.');

        if ($target->account_type !== User::ACCOUNT_STUDENT) {
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
