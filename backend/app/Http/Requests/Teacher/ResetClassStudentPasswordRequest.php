<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Đặt lại mật khẩu sinh viên trong lớp (POST .../classes/{id}/students/{studentId}/reset-password).
 * Quyền student.reset_password được chặn ở route; lớp phải thuộc giảng viên (siêu quản trị: mọi lớp)
 * và được kiểm tra ở ClassRosterService.
 */
class ResetClassStudentPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
