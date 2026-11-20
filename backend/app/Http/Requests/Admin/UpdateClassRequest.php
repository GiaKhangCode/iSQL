<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate sửa lớp (PUT/PATCH /api/admin/classes/{id}).
 * Quyền admin đã được chặn ở route (middleware role:admin) nên ở đây luôn cho phép.
 *
 * Thứ tự ngày (kể cả khi chỉ gửi một đầu, so với ngày đang lưu) được kiểm tra trong
 * ClassService::updateClass, không phải ở đây.
 */
class UpdateClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course'          => 'sometimes|required|string|max:255',
            'course_id'       => 'sometimes|nullable|string|exists:courses,id',
            'term'            => 'sometimes|required|string|max:100',
            'semester'        => 'sometimes|string|max:100',
            'term_id'         => 'sometimes|nullable|string|exists:academic_terms,id',
            'start_date'      => 'sometimes|nullable|date',
            'startDate'       => 'sometimes|nullable|date',
            'end_date'        => 'sometimes|nullable|date',
            'endDate'         => 'sometimes|nullable|date',
            'status'          => 'sometimes|in:Active,Archived,Draft',
            'lecturer_id'     => 'sometimes|nullable|string',
            'lecturerId'      => 'sometimes|nullable|string',
            'instructor_id'   => 'sometimes|nullable|string',
        ];
    }
}
