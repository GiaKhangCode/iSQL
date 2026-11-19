<?php

namespace App\Http\Requests\Admin;

use App\Services\Access\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tạo / sửa vai trò (POST /api/admin/access/roles, PUT|PATCH /api/admin/access/roles/{id}).
 * Quyền access.manage đã được chặn ở route nên ở đây luôn cho phép.
 */
class SaveAccessRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:100', Rule::unique('access_roles', 'name')->ignore($this->route('id'))],
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::codes())],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => 'Hãy nhập tên vai trò.',
            'name.unique'          => 'Tên vai trò đã tồn tại.',
            'permissions.*.in'     => 'Có quyền không hợp lệ.',
        ];
    }
}
