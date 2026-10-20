<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\FunctionModel;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;

class PermissionService
{
    public function getUserPermission(string $accountID): array
    {
        $rows = DB::select("
            SELECT 
                f.code,
                f.name,
                MAX(r.view)     AS can_view,
                MAX(r.add)      AS can_add,
                MAX(r.edit)     AS can_edit,
                MAX(r.delete)   AS can_delete,
                MAX(r.download) AS can_download
            FROM (
                SELECT rgar.role_id 
                FROM account_assign_role_group aarg
                JOIN role_group_assign_role rgar ON aarg.role_group_id = rgar.role_group_id
                WHERE aarg.account_id = ?
                UNION
                SELECT aar.role_id 
                FROM account_assign_role aar
                WHERE aar.account_id = ?
            ) AS user_roles
            JOIN roles r ON user_roles.role_id = r.id
            JOIN functions f ON r.function_id = f.id
            GROUP BY f.code, f.name
        ",[$accountID,$accountID]);

        $permission = [];
        foreach ($rows as $row){
            $permission[$row->code] = [
                'name' => $row->name,
                'view' => (bool) $row->can_view,
                'add' => (bool) $row->can_add,
                'edit' => (bool) $row->can_edit,
                'delete' => (bool) $row->can_delete,
                'download' => (bool) $row->can_download,
            ];
        }

        return $permission;
    }
}
