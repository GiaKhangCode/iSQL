<?php

namespace App\Http\Requests\Teacher;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Đặt lại mật khẩu sinh viên trong lớp (POST .../classes/{id}/students/{studentId}/reset-password).
 * Chỉ admin và giảng viên thực hành; lớp phải thuộc giảng viên (kiểm tra ở ClassRosterService).
 */
class ResetClassStudentPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [
            User::ROLE_INSTRUCTOR_PRACTICAL,
            User::LEGACY_INSTRUCTOR_ROLE,
            User::ROLE_ADMIN,
        ], true);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpException(403, 'Only practical instructors can reset passwords for students in their own classes.');
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
