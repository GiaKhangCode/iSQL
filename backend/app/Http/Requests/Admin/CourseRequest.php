<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate tạo (POST) và sửa (PUT/PATCH) môn học.
 * Quyền admin đã được chặn ở route (middleware role:admin) nên ở đây luôn cho phép.
 */
class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('post')) {
            return [
                'code'        => ['required', 'string', 'max:50', 'unique:courses,code'],
                'name'        => ['required', 'string', 'max:255', 'unique:courses,name'],
                'description' => ['sometimes', 'nullable', 'string'],
                'status'      => ['sometimes', Rule::in(['Active', 'Archived'])],
            ];
        }

        // Tham số route của apiResource('courses') là {course}.
        $id = $this->route('course');

        return [
            'code'        => ['sometimes', 'required', 'string', 'max:50', Rule::unique('courses', 'code')->ignore($id, 'id')],
            'name'        => ['sometimes', 'required', 'string', 'max:255', Rule::unique('courses', 'name')->ignore($id, 'id')],
            'description' => ['sometimes', 'nullable', 'string'],
            'status'      => ['sometimes', Rule::in(['Active', 'Archived'])],
        ];
    }
}
