<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate thêm hàng loạt sinh viên vào lớp (POST .../classes/{id}/students).
 * Quyền thao tác đã được chặn ở route (permission:CLASS_MGMT,edit) và ở ClassRosterService.
 */
class AddClassStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_ids'   => ['required', 'array', 'min:1', 'max:1000'],
            'student_ids.*' => ['required', 'string', 'distinct', 'exists:users,id'],
        ];
    }
}
