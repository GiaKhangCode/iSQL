<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate tạo lớp (POST /api/admin/classes).
 * Quyền admin đã được chặn ở route (middleware role:admin) nên ở đây luôn cho phép.
 *
 * Thứ tự ngày cho cả bản camelCase (`startDate`/`endDate`) được kiểm tra trong
 * ClassService::createManagedClass; ở đây `after_or_equal` chỉ bắt sớm bản snake_case.
 */
class StoreClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'              => 'sometimes|string|max:50|unique:classes,id',
            'course'          => 'required_without:course_id|string|max:255',
            'course_id'       => 'required_without:course|nullable|string|exists:courses,id',
            'term'            => 'required_without_all:term_id,semester|string|max:100',
            'semester'        => 'sometimes|string|max:100',
            'term_id'         => 'required_without_all:term,semester|nullable|string|exists:academic_terms,id',
            'start_date'      => 'sometimes|nullable|date',
            'startDate'       => 'sometimes|nullable|date',
            'end_date'        => 'sometimes|nullable|date|after_or_equal:start_date',
            'endDate'         => 'sometimes|nullable|date',
            'status'          => 'sometimes|in:Active,Archived,Draft',
            'lecturer_id'     => 'sometimes|nullable|string',
            'lecturerId'      => 'sometimes|nullable|string',
            'instructor_id'   => 'sometimes|nullable|string',
            'assignment_type' => ['sometimes', 'string', Rule::in(['theory', 'practical'])],
        ];
    }
}
