<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/regrade-assignments/candidates: bộ lọc danh sách bài cần phân công phúc khảo.
 * Frontend gửi snake_case, vài client cũ gửi camelCase nên chấp nhận cả hai.
 * Quyền (examination.manage) đã được chặn ở route và ở controller.
 */
class RegradeCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'examination_id' => ['nullable', 'string'],
            'examinationId'  => ['nullable', 'string'],
            'session_id'     => ['nullable', 'string'],
            'sessionId'      => ['nullable', 'string'],
            'q'              => ['nullable', 'string'],
            'appeal_only'    => ['nullable'],
        ];
    }

    /** @return array{examination_id: string, session_id: string, q: string, appeal_only: bool} */
    public function filters(): array
    {
        return [
            'examination_id' => trim((string) ($this->query('examination_id') ?? $this->query('examinationId') ?? '')),
            'session_id'     => trim((string) ($this->query('session_id') ?? $this->query('sessionId') ?? '')),
            'q'              => trim((string) ($this->query('q') ?? '')),
            'appeal_only'    => filter_var($this->query('appeal_only'), FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
