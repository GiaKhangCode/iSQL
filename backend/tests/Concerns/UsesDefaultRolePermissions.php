<?php

namespace Tests\Concerns;

use App\Models\AccessRole;
use App\Services\Access\AccessControlService;
use Database\Seeders\AccessRoleSeeder;

/**
 * Đặt quyền của các vai trò mặc định (theo slug) đúng như AccessRoleSeeder::DEFAULT_ROLES.
 *
 * DB dev có thể đã bị sửa quyền qua trang Vai trò (hoặc chưa nhận quyền mới thêm vào seeder);
 * test dựa vào "vai trò mặc định" gọi hàm này trong setUp để không phụ thuộc trạng thái đó.
 * Chạy trong DatabaseTransactions nên được rollback.
 */
trait UsesDefaultRolePermissions
{
    protected function useDefaultRolePermissions(): void
    {
        foreach (AccessRoleSeeder::DEFAULT_ROLES as $slug => [, $isSuper, $permissions]) {
            if ($isSuper) {
                continue;
            }
            AccessRole::where('slug', $slug)->first()?->syncPermissions($permissions);
        }

        app(AccessControlService::class)->forget();
    }
}
