<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/regrade-assignments/{submissionId}: người chấm phúc khảo và ghi chú.
 * Người chấm có thể được gửi dưới bốn tên khóa (reviewerId, reviewer_id, instructorId, instructor_id).
 * Không có "required": vì khóa có bốn tên nên thiếu hoặc sai người chấm được service kiểm và trả 422
 * (errors.reviewerId: "Không tìm thấy giảng viên chấm phúc khảo.") sau khi đã xác nhận bài nộp tồn tại.
 */
class AssignRegradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reviewerId'    => ['nullable', 'string'],
            'reviewer_id'   => ['nullable', 'string'],
            'instructorId'  => ['nullable', 'string'],
            'instructor_id' => ['nullable', 'string'],
            'note'          => ['nullable', 'string'],
        ];
    }

    public function reviewerId(): string
    {
        return (string) (
            $this->input('reviewerId')
            ?? $this->input('reviewer_id')
            ?? $this->input('instructorId')
            ?? $this->input('instructor_id')
            ?? ''
        );
    }

    public function note(): ?string
    {
        return trim((string) ($this->input('note') ?? '')) ?: null;
    }
}
