<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate phân công một giảng viên cho lớp
 * (PUT|POST /api/admin/classes/{id}/instructor và /allocate-teacher).
 * Quyền admin đã được chặn ở route (middleware role:admin) nên ở đây luôn cho phép.
 */
class AssignClassInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignment_type' => ['sometimes', 'nullable', Rule::in(['theory', 'practical'])],
            'instructor_id'   => ['sometimes', 'nullable', 'string'],
            'lecturer_id'     => ['sometimes', 'nullable', 'string'],
            'lecturerId'      => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** Mặc định `practical` khi frontend không gửi (hoặc gửi null). */
    public function assignmentType(): string
    {
        return $this->validated('assignment_type') ?? 'practical';
    }

    /** Frontend gửi id giảng viên dưới 3 tên khác nhau; null nếu không có tên nào. */
    public function instructorId(): ?string
    {
        $data = $this->validated();

        return $data['instructor_id'] ?? $data['lecturer_id'] ?? $data['lecturerId'] ?? null;
    }
}
