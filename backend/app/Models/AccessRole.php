<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Vai trò phân quyền kiểu capability (xem PermissionCatalog). Khác `Role` (ma trận cũ).
 */
class AccessRole extends Model
{
    use HasUuid;

    protected $table = 'access_roles';

    protected $fillable = ['slug', 'name', 'is_super'];

    protected $casts = ['is_super' => 'boolean'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_access_roles', 'access_role_id', 'user_id')->withTimestamps();
    }

    /** Mã quyền đã lưu của vai trò (siêu quản trị không lưu, xem AccessControlService). */
    public function permissionCodes(): array
    {
        return DB::table('access_role_permissions')
            ->where('access_role_id', $this->id)
            ->orderBy('permission')
            ->pluck('permission')
            ->all();
    }

    public function syncPermissions(array $codes): void
    {
        DB::transaction(function () use ($codes) {
            DB::table('access_role_permissions')->where('access_role_id', $this->id)->delete();
            $rows = array_map(
                fn (string $code) => ['access_role_id' => $this->id, 'permission' => $code],
                array_values(array_unique($codes))
            );
            if ($rows) {
                DB::table('access_role_permissions')->insert($rows);
            }
        });
    }
}
