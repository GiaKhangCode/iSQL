<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phân công giảng viên chấm phúc khảo cho bài thi (giáo vụ phân công, giảng viên được giao chấm).
 *
 * Quyền gọi (examination.manage để phân công, results.grade để xem việc của mình) được kiểm ở route và controller;
 * service chỉ lo dữ liệu và luật nghiệp vụ. Người chấm là tài khoản Active giữ results.grade (siêu quản trị
 * không tự có quyền này) và không phải giảng viên thực hành đứng lớp của chính sinh viên đó.
 */
class RegradeAssignmentService
{
    /** Số dòng tối đa trả về trong một lần liệt kê. */
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly ExaminationService $examinationService,
        private readonly NotificationService $notificationService,
        private readonly AccessControlService $access,
    ) {
    }

    /**
     * Danh sách bài nộp có thể phân công phúc khảo, ưu tiên bài có đơn phúc khảo rồi bài đã phân công rồi bài mới nhất.
     *
     * @param  array{examination_id?: string, session_id?: string, q?: string, appeal_only?: bool}  $filters
     * @return array{rows: array<int, array>, truncated: bool, activities: array<int, array>}
     */
    public function candidates(array $filters): array
    {
        $examinationId = (string) ($filters['examination_id'] ?? '');
        $sessionId = (string) ($filters['session_id'] ?? '');
        $keyword = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $appealOnly = (bool) ($filters['appeal_only'] ?? false);

        $standaloneActivities = Assignment::whereNull('examination_id')
            ->where('exam_mode', true)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'exam_mode'])
            ->map(fn (Assignment $a) => [
                'id'       => (string) $a->id,
                'title'    => (string) $a->title,
                'examMode' => (bool) $a->exam_mode,
            ])->values()->all();

        $empty = ['rows' => [], 'truncated' => false, 'activities' => $standaloneActivities];

        $subsQuery = Submission::whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
            ->whereNotNull('context')
            ->where('context', '!=', 'Practice')
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->orderByDesc('submitted_at');

        if ($sessionId !== '') {
            $subsQuery->where('context', $sessionId);
        } elseif ($examinationId !== '') {
            $examSessionIds = Assignment::where('examination_id', $examinationId)->pluck('id')->all();
            if (empty($examSessionIds)) {
                return $empty;
            }
            $subsQuery->whereIn('context', $examSessionIds);
        } else {
            $examActivityIds = Assignment::where(function ($q) {
                $q->whereNotNull('examination_id')
                    ->orWhere('exam_mode', true);
            })->pluck('id')->all();
            if (empty($examActivityIds)) {
                return $empty;
            }
            $subsQuery->whereIn('context', $examActivityIds);
        }

        $subs = $subsQuery->get();

        $appealIds = SubmissionAppeal::pluck('submission_id')->flip()->all();
        $assignedIds = SubmissionRegradeAssignment::pluck('submission_id')->flip()->all();

        // Mỗi (ca thi, sinh viên, câu) chỉ giữ lần nộp mới nhất, trừ bản đã có đơn phúc khảo hoặc đã được phân công.
        $seen = [];
        $candidates = [];
        foreach ($subs as $sub) {
            $key = "{$sub->context}:{$sub->user_id}:{$sub->problem_id}";
            $hasRecord = isset($appealIds[$sub->id]) || isset($assignedIds[$sub->id]);
            if (isset($seen[$key]) && !$hasRecord) {
                continue;
            }
            $seen[$key] = true;
            $candidates[] = $sub;
        }

        if ($appealOnly) {
            $candidates = array_values(array_filter($candidates, fn (Submission $s) => isset($appealIds[$s->id])));
        }

        usort($candidates, function (Submission $a, Submission $b) use ($appealIds, $assignedIds) {
            $pa = isset($appealIds[$a->id]) ? 0 : (isset($assignedIds[$a->id]) ? 1 : 2);
            $pb = isset($appealIds[$b->id]) ? 0 : (isset($assignedIds[$b->id]) ? 1 : 2);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return ($b->submitted_at?->getTimestamp() ?? 0) <=> ($a->submitted_at?->getTimestamp() ?? 0);
        });

        $rows = $this->serializeRows(collect($candidates));

        if ($keyword !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($keyword) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['studentName'] ?? '',
                    $row['studentCode'] ?? '',
                    $row['studentEmail'] ?? '',
                    $row['className'] ?? '',
                    $row['problemTitle'] ?? '',
                    $row['sessionTitle'] ?? '',
                ])));
                return str_contains($haystack, $keyword);
            }));
        }

        $truncated = count($rows) > self::MAX_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        return [
            'rows'       => $rows,
            'truncated'  => $truncated,
            'activities' => $standaloneActivities,
        ];
    }

    /**
     * Người có thể được chọn chấm phúc khảo; nếu biết bài nộp thì loại giảng viên thực hành đứng lớp của sinh viên.
     *
     * @return array<int, array{id: string, name: string, email: string}>
     */
    public function reviewers(?string $submissionId = null): array
    {
        $excludedIds = [];
        if ($submissionId !== null && $submissionId !== '') {
            $sub = Submission::find($submissionId);
            if ($sub) {
                $excludedIds = $this->classInstructorIdsForSubmission($sub);
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

    /** Phân công (hoặc đổi người chấm) cho một bài nộp. @return array dòng ứng viên sau khi phân công */
    public function assign(string $submissionId, string $reviewerId, ?string $note, User $assigner): array
    {
        $sub = Submission::findOrFail($submissionId);
        abort_if(empty($sub->context) || $sub->context === 'Practice', 422, 'Chỉ bài nộp trong kỳ thi hoặc cuộc thi mới được phân công chấm phúc khảo.');
        $activity = Assignment::find($sub->context);
        abort_unless($this->isRegradeEligibleActivity($activity, $sub) || SubmissionAppeal::where('submission_id', $sub->id)->exists(), 422, 'Chỉ bài thi hoặc cuộc thi mới được phân công chấm phúc khảo.');
        $this->assertNotQuestionOnly($sub);
        $existing = $this->assertNotCompleted($sub);

        // Người chấm nằm trong dữ liệu gửi lên nên chọn sai là lỗi dữ liệu (422, gắn vào reviewerId), không phải 404.
        $reviewer = $this->eligibleReviewerQuery()->where('id', $reviewerId)->first();
        if ($reviewer === null) {
            throw ValidationException::withMessages(['reviewerId' => ['Không tìm thấy giảng viên chấm phúc khảo.']]);
        }

        $excludedIds = $this->classInstructorIdsForSubmission($sub, $activity);
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

        SubmissionRegradeAssignment::updateOrCreate(
            ['submission_id' => $sub->id],
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
                'Bài nộp này đã được chuyển phân công phúc khảo cho giảng viên khác.',
                'Info',
                '/teacher/regrade',
            );
        }

        $this->notificationService->create(
            $reviewer->id,
            'Phân công chấm phúc khảo: ' . $problemTitle,
            $note ?: 'Giáo vụ khoa đã phân công một bài nộp cần chấm phúc khảo cho bạn.',
            'Review',
            "/teacher/results/review/{$sub->id}",
        );

        return array_merge($this->serializeRow($sub), ['ok' => true]);
    }

    /** Hủy phân công (chưa chấm xong). @return array dòng ứng viên sau khi hủy */
    public function unassign(string $submissionId): array
    {
        $sub = Submission::findOrFail($submissionId);
        abort_if(empty($sub->context) || $sub->context === 'Practice', 422, 'Chỉ bài nộp trong kỳ thi hoặc cuộc thi mới được phân công chấm phúc khảo.');
        $this->assertNotQuestionOnly($sub);
        $existing = $this->assertNotCompleted($sub);

        $previousReviewerId = $existing ? (string) $existing->assigned_instructor_id : null;
        SubmissionRegradeAssignment::where('submission_id', $sub->id)->delete();

        if ($previousReviewerId) {
            $this->notificationService->create(
                $previousReviewerId,
                'Hủy phân công chấm phúc khảo: ' . $this->problemTitle($sub),
                'Giáo vụ khoa đã hủy phân công chấm phúc khảo cho bài nộp này.',
                'Info',
                '/teacher/regrade',
            );
        }

        return array_merge($this->serializeRow($sub), ['ok' => true]);
    }

    /** Các bài được phân công cho $reviewer chấm, phân công mới nhất trước. @return array<int, array> */
    public function mine(User $reviewer): array
    {
        $rows = SubmissionRegradeAssignment::where('assigned_instructor_id', $reviewer->id)
            ->orderByDesc('assigned_at')
            ->get();

        $subIds = $rows->pluck('submission_id')->all();
        if (empty($subIds)) {
            return [];
        }

        $subsById = Submission::whereIn('id', $subIds)->get()->keyBy('id');
        $orderedSubs = $rows->map(fn ($r) => $subsById->get($r->submission_id))->filter()->values();

        return $this->serializeRows($orderedSubs);
    }

    // ───────────────────────── nội bộ ─────────────────────────

    /** Tài khoản Active giữ results.grade (siêu quản trị không tự có quyền này). */
    private function eligibleReviewerQuery()
    {
        return User::whereIn('id', $this->access->holdersOf('results.grade'))
            ->where('status', 'Active');
    }

    private function problemTitle(Submission $sub): string
    {
        $problem = Problem::find($sub->problem_id);

        return $problem ? $problem->title : 'Bài nộp SQL';
    }

    /** Bài nộp từng câu (workspace) không được phân công phúc khảo. */
    private function assertNotQuestionOnly(Submission $sub): void
    {
        $isQuestionOnly = WorkspaceSubmissionResult::where('submission_id', $sub->id)
            ->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL")
            ->exists();
        abort_if($isQuestionOnly, 422, 'Không thể phân công phúc khảo cho bài nộp từng câu.');
    }

    /**
     * @return SubmissionRegradeAssignment|null phân công hiện có; 409 nếu đã chấm xong. Yêu cầu hợp lệ nhưng
     *         bài nộp đang ở trạng thái không cho phép thao tác (xung đột trạng thái), nên không phải 422.
     */
    private function assertNotCompleted(Submission $sub): ?SubmissionRegradeAssignment
    {
        $existing = SubmissionRegradeAssignment::find($sub->id);
        if ($existing && $existing->completed_at !== null) {
            abort(409, 'Bài nộp này đã được chấm phúc khảo xong, không thể phân công lại hoặc hủy.');
        }

        return $existing;
    }

    /**
     * Giảng viên thực hành đứng lớp của sinh viên trong ca thi của bài nộp: không được chấm phúc khảo
     * chính sinh viên của mình.
     *
     * @return string[]
     */
    private function classInstructorIdsForSubmission(Submission $sub, ?Assignment $activity = null, ?array $activityClassIds = null): array
    {
        $activity = $activity ?? ((!empty($sub->context) && $sub->context !== 'Practice')
            ? Assignment::find($sub->context)
            : null);

        $excluded = [];
        $activityClassIds = $activityClassIds ?? ($activity
            ? $activity->classes()->pluck('classes.id')->all()
            : []);

        $studentClassIds = ClassEnrollment::where('student_id', $sub->user_id)
            ->when(!empty($activityClassIds), fn ($q) => $q->whereIn('class_id', $activityClassIds))
            ->pluck('class_id')
            ->all();

        if (empty($studentClassIds)) {
            $studentClassIds = ClassEnrollment::where('student_id', $sub->user_id)
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

    private function isRegradeEligibleActivity(?Assignment $activity, Submission $sub): bool
    {
        if (in_array($sub->source, ['ExamSession'], true)) {
            return true;
        }
        return $activity !== null && (bool) ($activity->examination_id || $activity->exam_mode);
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

        $subIds = $subs->pluck('id')->all();
        $userIds = $subs->pluck('user_id')->filter()->unique()->values()->all();
        $problemIds = $subs->pluck('problem_id')->filter()->unique()->values()->all();
        $contextIds = $subs->pluck('context')->filter(fn ($c) => $c && $c !== 'Practice')->unique()->values()->all();

        $appealsMap = SubmissionAppeal::whereIn('submission_id', $subIds)->get()->keyBy('submission_id');
        $assignmentsMap = SubmissionRegradeAssignment::whereIn('submission_id', $subIds)->get()->keyBy('submission_id');
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
            $appeal = $appealsMap->get($sub->id);
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
                'appealRequested'         => $appeal !== null,
                'appealReviewed'          => $appeal !== null && $appeal->resolved_at !== null,
                'appealStatus'            => $appeal ? (string) $appeal->status : null,
                'appealReason'            => $appeal ? (string) $appeal->reason : null,
                'appealCreatedAt'         => $appeal && $appeal->created_at ? $appeal->created_at->toISOString() : null,
                'appealResolvedAt'        => $appeal && $appeal->resolved_at ? $appeal->resolved_at->toISOString() : null,
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
