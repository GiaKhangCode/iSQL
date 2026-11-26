<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\ExamRegrade;
use App\Models\Submission;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phân công giảng viên chấm phúc khảo cho bài thi (giáo vụ phân công, giảng viên được giao chấm).
 *
 * Quyền gọi (examination.manage để phân công, results.regrade để xem việc của mình) được kiểm ở route và controller;
 * service chỉ lo dữ liệu và luật nghiệp vụ. Người chấm là tài khoản Active giữ results.regrade (siêu quản trị
 * không tự có quyền này) và không phải giảng viên thực hành đứng lớp của chính sinh viên đó.
 */
class RegradeAssignmentService
{
    public function __construct(
        private readonly ExaminationService $examinationService,
        private readonly NotificationService $notificationService,
        private readonly AccessControlService $access,
    ) {
    }

    /**
     * Người có thể được chọn chấm phúc khảo; nếu biết ca thi và sinh viên thì loại giảng viên thực hành đứng lớp của sinh viên.
     *
     * @return array<int, array{id: string, name: string, email: string}>
     */
    public function reviewers(?string $activityId = null, ?string $studentId = null): array
    {
        $excludedIds = [];
        if (!empty($activityId) && !empty($studentId)) {
            $activity = Assignment::find($activityId);
            if ($activity) {
                $excludedIds = $this->classInstructorIds($studentId, $activity);
            }
        }

        $query = $this->eligibleReviewerQuery();
        if (!empty($excludedIds)) {
            $query->whereNotIn('id', $excludedIds);
        }

        return $query->orderBy('name')->orderBy('email')->get()
            ->map(fn (User $u) => [
                'id'    => (string) $u->id,
                'name'  => (string) $u->name,
                'email' => (string) $u->email,
            ])->values()->all();
    }

    /**
     * Phân công (hoặc đổi người chấm) phúc khảo cho cả bài thi của một sinh viên trong một ca thi.
     * Chỉ bài thi đã có điểm chính thức mới được phân công.
     *
     * @return array dòng ứng viên sau khi phân công
     */
    public function assign(string $activityId, string $studentId, string $reviewerId, ?string $note, User $assigner): array
    {
        [$activity, $sub] = $this->examOf($activityId, $studentId);
        abort_unless($sub->evaluated_score !== null, 422, 'Bài thi chưa được chấm chính thức, chưa thể phân công chấm phúc khảo.');
        $existing = $this->assertNotCompleted($activity->id, $studentId);

        // Người chấm nằm trong dữ liệu gửi lên nên chọn sai là lỗi dữ liệu (422, gắn vào reviewerId), không phải 404.
        $reviewer = $this->eligibleReviewerQuery()->where('id', $reviewerId)->first();
        if ($reviewer === null) {
            throw ValidationException::withMessages(['reviewerId' => ['Không tìm thấy giảng viên chấm phúc khảo.']]);
        }

        $excludedIds = $this->classInstructorIds($studentId, $activity);
        if (in_array((string) $reviewer->id, $excludedIds, true)) {
            throw ValidationException::withMessages([
                'reviewerId' => ['Không được chọn giảng viên đứng lớp của sinh viên này để chấm phúc khảo.'],
            ]);
        }

        $previousReviewerId = $existing && $existing->assigned_instructor_id !== $reviewer->id
            ? (string) $existing->assigned_instructor_id
            : null;

        // Ảnh chụp điểm và nhận xét trước phúc khảo được giữ từ lần phân công đầu tiên.
        $previousEvaluatedScore = $existing && $existing->previous_evaluated_score !== null
            ? $existing->previous_evaluated_score
            : ($sub->evaluated_score !== null ? (float) $sub->evaluated_score : null);
        $previousFeedback = $existing && $existing->previous_feedback !== null
            ? $existing->previous_feedback
            : $sub->feedback;

        ExamRegrade::updateOrCreate(
            ['activity_id' => $activity->id, 'student_id' => $studentId],
            [
                'assigned_instructor_id'   => $reviewer->id,
                'assigned_by_id'           => $assigner->id,
                'note'                     => $note,
                'previous_evaluated_score' => $previousEvaluatedScore,
                'previous_feedback'        => $previousFeedback,
                'assigned_at'              => now(),
                'completed_at'             => null,
            ]
        );

        $problemTitle = $this->problemTitle($sub);

        if ($previousReviewerId) {
            $this->notificationService->create(
                $previousReviewerId,
                'Thay đổi phân công chấm phúc khảo: ' . $problemTitle,
                'Bài thi này đã được chuyển phân công phúc khảo cho giảng viên khác.',
                'Info',
                '/teacher/regrade',
            );
        }

        $this->notificationService->create(
            $reviewer->id,
            'Phân công chấm phúc khảo: ' . $problemTitle,
            $note ?: 'Giáo vụ khoa đã phân công một bài thi cần chấm phúc khảo cho bạn.',
            'Review',
            "/teacher/results/review/{$sub->id}",
        );

        return array_merge($this->serializeRow($sub), ['ok' => true]);
    }

    /** Hủy phân công (chưa chấm xong). @return array dòng ứng viên sau khi hủy */
    public function unassign(string $activityId, string $studentId): array
    {
        [$activity, $sub] = $this->examOf($activityId, $studentId);
        $existing = $this->assertNotCompleted($activity->id, $studentId);
        $previousReviewerId = $existing ? (string) $existing->assigned_instructor_id : null;

        ExamRegrade::where('activity_id', $activity->id)->where('student_id', $studentId)->delete();

        if ($previousReviewerId) {
            $this->notificationService->create(
                $previousReviewerId,
                'Hủy phân công chấm phúc khảo: ' . $this->problemTitle($sub),
                'Giáo vụ khoa đã hủy phân công chấm phúc khảo cho bài thi này.',
                'Info',
                '/teacher/regrade',
            );
        }

        return array_merge($this->serializeRow($sub), ['ok' => true]);
    }

    /** Các bài thi được phân công cho $reviewer chấm, phân công mới nhất trước. @return array<int, array> */
    public function mine(User $reviewer): array
    {
        $rows = ExamRegrade::where('assigned_instructor_id', $reviewer->id)
            ->orderByDesc('assigned_at')
            ->get();

        $subs = $rows->map(fn (ExamRegrade $row) => $this->examSubmission((string) $row->activity_id, (string) $row->student_id))
            ->filter()
            ->values();

        return $this->serializeRows($subs);
    }

    // ───────────────────────── nội bộ ─────────────────────────

    /** Tài khoản Active giữ results.regrade (siêu quản trị không tự có quyền này). */
    private function eligibleReviewerQuery()
    {
        return User::whereIn('id', $this->access->holdersOf('results.regrade'))
            ->where('status', 'Active');
    }

    private function problemTitle(Submission $sub): string
    {
        $problem = Problem::find($sub->problem_id);

        return $problem ? $problem->title : 'Bài nộp SQL';
    }

    /**
     * Bài nộp chính thức của sinh viên trong ca thi (mỗi bài thi chỉ có một; nếu dữ liệu cũ có nhiều thì lấy bài điểm
     * tự động cao nhất, bằng điểm thì lấy bài nộp sau cùng). Bài nộp từng câu (workspace) không được tính.
     */
    private function examSubmission(string $activityId, string $studentId): ?Submission
    {
        return Submission::where('context', $activityId)
            ->where('user_id', $studentId)
            ->whereIn('source', ['ExamSession', 'Assignments', 'Assignment'])
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->orderByDesc('score')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Ca thi và bài nộp của sinh viên. 404 nếu ca thi hoặc sinh viên không tồn tại; 422 nếu không phải bài thi hoặc
     * sinh viên chưa nộp bài.
     *
     * @return array{0: Assignment, 1: Submission}
     */
    private function examOf(string $activityId, string $studentId): array
    {
        $activity = Assignment::findOrFail($activityId);
        User::findOrFail($studentId);
        abort_unless($activity->examination_id || $activity->exam_mode, 422, 'Chỉ bài thi mới được phân công chấm phúc khảo.');

        $sub = $this->examSubmission($activity->id, $studentId);
        abort_if($sub === null, 422, 'Sinh viên chưa nộp bài thi này.');

        return [$activity, $sub];
    }

    /**
     * @return ExamRegrade|null phân công hiện có; 409 nếu đã chấm xong. Yêu cầu hợp lệ nhưng
     *         bài thi đang ở trạng thái không cho phép thao tác (xung đột trạng thái), nên không phải 422.
     */
    private function assertNotCompleted(string $activityId, string $studentId): ?ExamRegrade
    {
        $existing = ExamRegrade::where('activity_id', $activityId)->where('student_id', $studentId)->first();
        if ($existing && $existing->completed_at !== null) {
            abort(409, 'Bài thi này đã được chấm phúc khảo xong, không thể phân công lại hoặc hủy.');
        }

        return $existing;
    }

    /**
     * Giảng viên thực hành đứng lớp của sinh viên trong ca thi: không được chấm phúc khảo
     * chính sinh viên của mình.
     *
     * @return string[]
     */
    private function classInstructorIds(string $studentId, Assignment $activity, ?array $activityClassIds = null): array
    {
        $excluded = [];
        $activityClassIds = $activityClassIds ?? $activity->classes()->pluck('classes.id')->all();

        $studentClassIds = ClassEnrollment::where('student_id', $studentId)
            ->when(!empty($activityClassIds), fn ($q) => $q->whereIn('class_id', $activityClassIds))
            ->pluck('class_id')
            ->all();
        if (empty($studentClassIds)) {
            $studentClassIds = ClassEnrollment::where('student_id', $studentId)
                ->pluck('class_id')
                ->all();
        }

        if (!empty($studentClassIds)) {
            $classes = ClassModel::whereIn('id', $studentClassIds)->get(['id', 'instructor_id']);
            foreach ($classes as $cls) {
                if ($cls->instructor_id) {
                    $excluded[] = (string) $cls->instructor_id;
                }
            }
        }

        return array_values(array_unique(array_filter($excluded)));
    }

    private function serializeRow(Submission $sub): array
    {
        return $this->serializeRows(collect([$sub]))[0];
    }

    /** @return array<int, array> mỗi dòng đúng khóa mà frontend đang đọc */
    private function serializeRows(Collection $subs): array
    {
        if ($subs->isEmpty()) {
            return [];
        }

        $userIds = $subs->pluck('user_id')->filter()->unique()->values()->all();
        $problemIds = $subs->pluck('problem_id')->filter()->unique()->values()->all();
        $contextIds = $subs->pluck('context')->filter(fn ($c) => $c && $c !== 'Practice')->unique()->values()->all();

        $assignmentsMap = ExamRegrade::mapForSubmissions($subs);
        $reviewerIds = $assignmentsMap->pluck('assigned_instructor_id')->filter()->unique()->values()->all();
        $allUserIds = array_values(array_unique(array_merge($userIds, $reviewerIds)));

        $usersMap = !empty($allUserIds) ? User::whereIn('id', $allUserIds)->get()->keyBy('id') : collect();
        $problemsMap = !empty($problemIds) ? Problem::whereIn('id', $problemIds)->get()->keyBy('id') : collect();
        $activitiesMap = !empty($contextIds) ? Assignment::whereIn('id', $contextIds)->get()->keyBy('id') : collect();

        $apMap = [];
        if (!empty($contextIds) && !empty($problemIds)) {
            foreach (AssignmentProblem::whereIn('assignment_id', $contextIds)->whereIn('problem_id', $problemIds)->get() as $ap) {
                $apMap["{$ap->assignment_id}:{$ap->problem_id}"] = $ap;
            }
        }

        $activityClassMap = [];
        if (!empty($contextIds)) {
            foreach (DB::table('assignment_classes')->whereIn('assignment_id', $contextIds)->get(['assignment_id', 'class_id']) as $ac) {
                $activityClassMap[(string) $ac->assignment_id][] = (string) $ac->class_id;
            }
        }

        $studentEnrollmentsMap = [];
        $allClassIds = [];
        if (!empty($userIds)) {
            foreach (ClassEnrollment::whereIn('student_id', $userIds)->get(['student_id', 'class_id']) as $enr) {
                $studentEnrollmentsMap[(string) $enr->student_id][] = (string) $enr->class_id;
                $allClassIds[] = (string) $enr->class_id;
            }
        }
        $allClassIds = array_values(array_unique($allClassIds));
        $classesMap = !empty($allClassIds)
            ? ClassModel::whereIn('id', $allClassIds)->get(['id', 'course', 'instructor_id'])->keyBy('id')
            : collect();

        $rows = [];
        foreach ($subs as $sub) {
            $student = $usersMap->get($sub->user_id);
            $problem = $problemsMap->get($sub->problem_id);
            $activity = (!empty($sub->context) && $sub->context !== 'Practice') ? $activitiesMap->get($sub->context) : null;
            $ap = $activity ? ($apMap["{$activity->id}:{$sub->problem_id}"] ?? null) : null;
            $assignment = $assignmentsMap->get($sub->id);
            $reviewer = $assignment ? $usersMap->get($assignment->assigned_instructor_id) : null;

            $maxScore = $ap ? (float) $ap->points : ($activity ? 10.0 : 100.0);
            $finalScore = $sub->evaluated_score !== null ? (float) $sub->evaluated_score : (float) ($sub->score ?? 0);
            $studentCode = $student ? $this->examinationService->studentCode($student->email, $student->id) : null;

            $enrolledClassIds = $studentEnrollmentsMap[(string) $sub->user_id] ?? [];
            $activityClassIds = $activity ? ($activityClassMap[(string) $activity->id] ?? []) : [];
            $scopedClassIds = !empty($activityClassIds)
                ? array_values(array_intersect($enrolledClassIds, $activityClassIds))
                : $enrolledClassIds;
            if (empty($scopedClassIds)) {
                $scopedClassIds = $enrolledClassIds;
            }

            $className = null;
            $excluded = [];
            foreach ($scopedClassIds as $cid) {
                $cls = $classesMap->get($cid);
                if ($cls) {
                    if ($className === null) {
                        $className = (string) $cls->course;
                    }
                    if ($cls->instructor_id) {
                        $excluded[] = (string) $cls->instructor_id;
                    }
                }
            }

            $rows[] = [
                'submissionId'            => (string) $sub->id,
                'sessionId'               => $activity ? (string) $activity->id : (string) ($sub->context ?? ''),
                'sessionTitle'            => $activity ? (string) $activity->title : (string) ($sub->source ?? 'Practice'),
                'examinationId'           => $activity && $activity->examination_id ? (string) $activity->examination_id : null,
                'activityId'              => $activity ? (string) $activity->id : (string) ($sub->context ?? ''),
                'activityTitle'           => $activity ? (string) $activity->title : (string) ($sub->source ?? 'Practice'),
                'isExamSession'           => $activity ? (bool) ($activity->examination_id || $activity->exam_mode) : ($sub->source === 'ExamSession'),
                'studentId'               => (string) $sub->user_id,
                'studentName'             => $student ? (string) $student->name : 'Unknown Student',
                'student'                 => $student ? (string) $student->name : 'Unknown Student',
                'studentCode'             => $studentCode,
                'studentEmail'            => $student ? (string) $student->email : '',
                'className'               => $className,
                'problemId'               => (string) $sub->problem_id,
                'problemTitle'            => $problem ? (string) $problem->title : 'Unknown Problem',
                'problem'                 => $problem ? (string) $problem->title : 'Unknown Problem',
                'score'                   => $finalScore,
                'maxScore'                => $maxScore,
                'status'                  => (string) ($sub->result ?: 'Submitted'),
                'submittedAt'             => $sub->submitted_at ? $sub->submitted_at->toISOString() : null,
                'reviewerId'              => $assignment ? (string) $assignment->assigned_instructor_id : null,
                'reviewerName'            => $reviewer ? (string) $reviewer->name : null,
                'assignedInstructorId'    => $assignment ? (string) $assignment->assigned_instructor_id : null,
                'assignedInstructor'      => $reviewer ? (string) $reviewer->name : null,
                'assignedInstructorEmail' => $reviewer ? (string) $reviewer->email : null,
                'assignedAt'              => $assignment && $assignment->assigned_at ? $assignment->assigned_at->toISOString() : null,
                'completedAt'             => $assignment && $assignment->completed_at ? $assignment->completed_at->toISOString() : null,
                'assignmentNote'          => $assignment ? $assignment->note : null,
                'note'                    => $assignment ? $assignment->note : null,
                'excludedReviewerIds'     => array_values(array_unique(array_filter($excluded))),
            ];
        }

        return $rows;
    }
}
