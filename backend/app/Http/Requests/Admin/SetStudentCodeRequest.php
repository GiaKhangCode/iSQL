<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Đặt mã số sinh viên (PUT /api/admin/access/users/{id}/student-code). */
class SetStudentCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_code' => ['required', 'string', 'regex:/^[0-9]{6,20}$/'],
        ];
    }

    public function messages(): array
    {
        return ['student_code.regex' => 'Mã sinh viên gồm 6 đến 20 chữ số.'];
    }
}
