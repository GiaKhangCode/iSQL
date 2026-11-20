<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate thay thế toàn bộ danh sách sinh viên của lớp (PUT .../classes/{id}/students).
 * `student_ids` phải có mặt nhưng được phép rỗng (xóa sạch danh sách).
 */
class ReplaceClassStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_ids'   => ['present', 'array', 'max:1000'],
            'student_ids.*' => ['required', 'string', 'distinct', 'exists:users,id'],
        ];
    }
}
