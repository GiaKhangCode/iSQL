<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ExamClassProctor;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionRegradeAssignment;
use App\Models\User;
use App\Services\Access\StaffAccess;
use Illuminate\Support\Facades\DB;

/**
 * Ai được xem / chấm bài nộp và bài làm của sinh viên (phạm vi theo dữ liệu; quyền capability do StaffAccess).
 *
 * Quy tắc: người xem mọi kết quả (StaffAccess::seesEverything) xem được tất cả. Bài thi chỉ do giảng viên THỰC HÀNH
 * đứng lớp của sinh viên xem. Bài tập thường: chủ hoạt động, giám thị được phân công, giảng viên của lớp được giao.
 * Bài luyện tập: tác giả bài toán. Người được giao chấm lại một bài nộp thì chấm / xem được bài đó (và đọc các lần
 * nộp khác của cùng bài khi $allowSiblingAttemptRead).
 */
class SubmissionAccessService
{
    public function __construct(protected readonly StaffAccess $staff)
    {
    }

    /** GET /submissions/{id}: sinh viên xem bài của mình; nhân sự xem bài trong phạm vi của họ. */
    public function assertCanView(User $user, Submission $sub): void
    {
        if (!$this->staff->isStaff($user)) {
            abort_unless($sub->user_id === $user->id, 403, 'Bạn không có quyền xem bài nộp này.');
        } else {
            abort_unless($this->canGradeSubmission($user, $sub, null, true), 403, 'Bạn không có quyền xem bài nộp này.');
        }
    }

    /**
     * GET /submissions/activity/{a}/students/{s}/problems/{p}: phạm vi xem bài làm của một sinh viên trong một hoạt động
     * (người gọi đã được kiểm tra là nhân sự).
     */
    public function assertCanViewStudentWork(User $user, Assignment $activity, string $studentId): void
    {
        $isPrivileged = $this->staff->seesEverything($user);
        if (!$isPrivileged && $this->isExamActivity($activity)) {
            abort_unless(
                $this->isPracticalClassInstructorForStudent($user, $studentId, $activity),
                403,
                'Chỉ giảng viên thực hành đứng lớp của sinh viên mới có quyền xem bài thi này.'
            );
        } else {
            abort_unless($this->canAccessActivityForGrading($user, $activity), 403, 'Bạn không có quyền xem bài làm của hoạt động này.');
        }
    }

    public function isExamActivity(Assignment $activity, ?Submission $sub = null): bool
    {
        if ($activity->examination_id || $activity->exam_mode) {
            return true;
        }
        return $sub !== null && in_array($sub->source, ['ExamSession'], true);
    }

    /** Id các lớp mà giảng viên dạy THỰC HÀNH (cột practice_instructor_id hoặc class_instructors loại practical). */
    protected function practicalClassIds(User $viewer): array
    {
        return ClassModel::where(function ($q) use ($viewer) {
            $q->where('practice_instructor_id', $viewer->id)
                ->orWhereIn('id', DB::table('class_instructors')
                    ->where('instructor_id', $viewer->id)
                    ->where('assignment_type', 'practical')
                    ->select('class_id'));
        })->pluck('id')->all();
    }

    public function isPracticalClassInstructorForStudent(User $viewer, string $studentId, Assignment $activity): bool
    {
        $practicalClassIds = $this->practicalClassIds($viewer);

        if (empty($practicalClassIds)) {
            return false;
        }

        $activityClassIds = $activity->classes()->pluck('classes.id')->all();
        $scopedClassIds = !empty($activityClassIds)
            ? array_values(array_intersect($practicalClassIds, $activityClassIds))
            : $practicalClassIds;

        if (empty($scopedClassIds)) {
            return false;
        }

        return ClassEnrollment::where('student_id', $studentId)
            ->whereIn('class_id', $scopedClassIds)
            ->exists();
    }

    public function canAccessActivityForGrading(User $viewer, Assignment $activity): bool
    {
        if ($this->staff->seesEverything($viewer)) {
            return true;
        }
        if (!$this->staff->isStaff($viewer)) {
            return false;
        }
        if ($this->isExamActivity($activity)) {
            $practicalClassIds = $this->practicalClassIds($viewer);
            if (empty($practicalClassIds)) {
                return false;
            }
            $classIds = $activity->classes()->pluck('classes.id')->all();
            return empty($classIds) || !empty(array_intersect($practicalClassIds, $classIds));
        }
        if ($activity->instructor_id === $viewer->id) {
            return true;
        }
        if (ExamClassProctor::where('session_id', $activity->id)->where('proctor_id', $viewer->id)->exists()) {
            return true;
        }
        $classIds = $activity->classes()->pluck('classes.id')->all();
        if (!empty($classIds)) {
            $teachesAssignedClass = ClassModel::whereIn('id', $classIds)
                ->where(function ($q) use ($viewer) {
                    $q->where('instructor_id', $viewer->id)
                        ->orWhere('practice_instructor_id', $viewer->id)
                        ->orWhereHas('instructors', fn ($iq) => $iq->where('users.id', $viewer->id));
                })
                ->exists();
            if ($teachesAssignedClass) {
                return true;
            }
        }
        return false;
    }

    public function canGradeSubmission(User $viewer, Submission $sub, ?Assignment $activity = null, bool $allowSiblingAttemptRead = false): bool
    {
        if ($this->staff->seesEverything($viewer)) {
            return true;
        }
        if (!$this->staff->isStaff($viewer)) {
            return false;
        }

        $isActivity = in_array($sub->source, ['Assignments', 'ExamSession', 'Assignment'], true)
            && $sub->context && $sub->context !== 'Practice';

        if ($isActivity) {
            $activity = $activity ?? Assignment::find($sub->context);
            if (!$activity) {
                return false;
            }
            if (SubmissionRegradeAssignment::where('submission_id', $sub->id)
                ->where('assigned_instructor_id', $viewer->id)
                ->exists()) {
                return true;
            }
            if ($allowSiblingAttemptRead) {
                $siblingIds = Submission::where('user_id', $sub->user_id)
                    ->where('problem_id', $sub->problem_id)
                    ->where('context', $sub->context)
                    ->pluck('id')
                    ->all();
                if (!empty($siblingIds) && SubmissionRegradeAssignment::whereIn('submission_id', $siblingIds)
                    ->where('assigned_instructor_id', $viewer->id)
                    ->exists()) {
                    return true;
                }
            }
            if ($this->isExamActivity($activity, $sub)) {
                return $this->isPracticalClassInstructorForStudent($viewer, (string) $sub->user_id, $activity);
            }
            return $this->canAccessActivityForGrading($viewer, $activity);
        }

        $problem = Problem::find($sub->problem_id);
        return $problem && $problem->creator_id === $viewer->id;
    }
}
