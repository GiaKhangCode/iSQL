<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate tạo (POST) và sửa (PUT/PATCH) học kỳ.
 * Quyền admin đã được chặn ở route (middleware role:admin) nên ở đây luôn cho phép.
 */
class AcademicTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('post')) {
            return [
                'name'       => ['required', 'string', 'max:100', 'unique:academic_terms,name'],
                'start_date' => ['sometimes', 'nullable', 'date'],
                'end_date'   => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
                'status'     => ['sometimes', Rule::in(['Active', 'Archived'])],
            ];
        }

        // Tham số route của apiResource('terms') là {term}.
        $id = $this->route('term');

        return [
            'name'       => ['sometimes', 'required', 'string', 'max:100', Rule::unique('academic_terms', 'name')->ignore($id, 'id')],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date'   => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status'     => ['sometimes', Rule::in(['Active', 'Archived'])],
        ];
    }
}
