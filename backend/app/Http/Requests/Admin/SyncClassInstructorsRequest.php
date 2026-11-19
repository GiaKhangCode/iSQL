<?php

namespace App\Http\Requests\Admin;

use App\Services\ClassService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate gán cùng lúc giảng viên lý thuyết và thực hành
 * (PUT /api/admin/classes/{id}/instructors và /api/teacher/classes/{id}/instructors).
 * Cả hai phải là tài khoản Active có quyền class.teach (ClassService::canHoldAssignment).
 * Quyền của người gọi (class.manage) đã được chặn ở route nên ở đây luôn cho phép.
 */
class SyncClassInstructorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'theory_instructor_id'    => ['required', 'string', $this->eligibleInstructor('theory')],
            'practical_instructor_id' => ['required', 'string', $this->eligibleInstructor('practical')],
        ];
    }

    /** Phân công mới cần class.teach; giữ nguyên người đang dạy đúng loại đó thì không (ClassService::canHoldAssignment). */
    private function eligibleInstructor(string $assignmentType): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($assignmentType): void {
            $classId = (string) $this->route('id');
            if (! app(ClassService::class)->canHoldAssignment($classId, $assignmentType, (string) $value)) {
                $fail('The selected :attribute must be an active account allowed to teach classes.');
            }
        };
    }
}
