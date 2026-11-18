<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate gán cùng lúc giảng viên lý thuyết và thực hành
 * (PUT /api/admin/classes/{id}/instructors). Cả hai phải là giảng viên đang Active.
 * Quyền admin đã được chặn ở route (middleware role:admin) nên ở đây luôn cho phép.
 */
class SyncClassInstructorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'theory_instructor_id'    => ['required', 'string', $this->activeInstructor()],
            'practical_instructor_id' => ['required', 'string', $this->activeInstructor()],
        ];
    }

    private function activeInstructor()
    {
        return Rule::exists('users', 'id')->where(fn ($query) => $query
            ->whereIn('role', User::INSTRUCTOR_ROLES)
            ->where('status', 'Active'));
    }
}
