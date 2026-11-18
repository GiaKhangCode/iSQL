<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tham số của GET /api/student/dashboard.
 *
 * tz_offset là số phút chênh lệch UTC trừ giờ địa phương, đúng giá trị của
 * `new Date().getTimezoneOffset()` ở trình duyệt (vd: Việt Nam UTC+7 gửi -420).
 */
class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Chặn ngoài dải múi giờ thực tế (UTC-12 đến UTC+14).
            'tz_offset' => ['nullable', 'integer', 'between:-840,720'],
        ];
    }

    /**
     * Số phút lệch múi giờ, mặc định 0 (UTC) khi không gửi.
     */
    public function tzOffset(): int
    {
        return (int) ($this->validated('tz_offset') ?? 0);
    }
}
