<?php

namespace App\Http\Requests\Admin;

use App\Services\Access\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gán vai trò và quyền riêng cho một tài khoản (PUT /api/admin/access/users/{id}).
 * `direct_permissions` vắng mặt hoặc null = giữ nguyên; mảng rỗng = xóa hết quyền riêng.
 */
class AssignUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_ids'             => ['present', 'array'],
            'role_ids.*'           => ['string', Rule::exists('access_roles', 'id')],
            'direct_permissions'   => ['sometimes', 'nullable', 'array'],
            'direct_permissions.*' => ['string', Rule::in(PermissionCatalog::codes())],
        ];
    }

    public function messages(): array
    {
        return [
            'role_ids.*.exists'         => 'Có vai trò không tồn tại.',
            'direct_permissions.*.in'   => 'Có quyền không hợp lệ.',
        ];
    }

    /** @return string[]|null */
    public function directPermissions(): ?array
    {
        return $this->validated('direct_permissions');
    }
}
