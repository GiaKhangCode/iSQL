<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ExamClassProctor;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\User;
use App\Services\ExaminationService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegradeAssignmentController extends Controller
{
    public function __construct(
        protected ExaminationService $examinationService,
        protected NotificationService $notificationService,
    ) {
    }

    protected function requireAssigner(User $user): void
    {
        $ok = $user->isAdmin() || $user->isFacultyOffice() || $user->role === User::ROLE_DEPARTMENT_HEAD;
        abort_unless($ok, 403, 'Chỉ giáo vụ khoa hoặc quản trị viên mới được phân công chấm phúc khảo.');
    }

    /**
     * Find practical instructor IDs who teach the student's class(es) for the submission's activity.
     * Practical class instructors must not be assigned to regrade their own students' exam submissions.
     */
    protected function classInstructorIdsForSubmission(Submission $sub, ?Assignment $activity = null, ?array $activityClassIds = null): array
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
            $classes = ClassModel::whereIn('id', $studentClassIds)->get(['id', 'practice_instructor_id']);
            foreach ($classes as $cls) {
                if ($cls->practice_instructor_id) {
                    $excluded[] = (string) $cls->practice_instructor_id;
                }
            }
            $pivotIds = DB::table('class_instructors')
                ->whereIn('class_id', $studentClassIds)
                ->where('assignment_type', 'practical')
                ->pluck('instructor_id')
                ->all();
            foreach ($pivotIds as $pid) {
                $excluded[] = (string) $pid;
            }
        }

        return array_values(array_unique(array_filter($excluded)));
    }

    protected function isRegradeEligibleActivity(?Assignment $activity, Submission $sub): bool
    {
        if (in_array($sub->source, ['ExamSession'], true)) {
            return true;
        }
        return $activity !== null && (bool) ($activity->examination_id || $activity->exam_mode);
    }

    protected function serializeRows(Collection $subs): array
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
            ? ClassModel::whereIn('id', $allClassIds)->get(['id', 'course', 'practice_instructor_id'])->keyBy('id')
            : collect();
        $classInstructorsMap = [];
        if (!empty($allClassIds)) {
            foreach (DB::table('class_instructors')->whereIn('class_id', $allClassIds)->where('assignment_type', 'practical')->get(['class_id', 'instructor_id']) as $ci) {
                $classInstructorsMap[(string) $ci->class_id][] = (string) $ci->instructor_id;
            }
        }

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
                    if ($cls->practice_instructor_id) {
                        $excluded[] = (string) $cls->practice_instructor_id;
                    }
                }
                foreach (($classInstructorsMap[$cid] ?? []) as $pid) {
                    $excluded[] = $pid;
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

    protected function serializeRow(Submission $sub): array
    {
        return $this->serializeRows(collect([$sub]))[0];
    }

    public function candidates(Request $request): JsonResponse
    {
        $this->requireAssigner($request->user());

        $examinationId = trim((string) ($request->query('examination_id') ?? $request->query('examinationId') ?? ''));
        $sessionId = trim((string) ($request->query('session_id') ?? $request->query('sessionId') ?? ''));
        $keyword = mb_strtolower(trim((string) ($request->query('q') ?? '')));
        $appealOnly = filter_var($request->query('appeal_only'), FILTER_VALIDATE_BOOLEAN);

        $standaloneActivities = Assignment::whereNull('examination_id')
            ->where('exam_mode', true)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'exam_mode'])
            ->map(fn (Assignment $a) => [
                'id'       => (string) $a->id,
                'title'    => (string) $a->title,
                'examMode' => (bool) $a->exam_mode,
            ])->values()->all();

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
                return response()->json(['rows' => [], 'truncated' => false, 'activities' => $standaloneActivities]);
            }
            $subsQuery->whereIn('context', $examSessionIds);
        } else {
            $examActivityIds = Assignment::where(function ($q) {
                $q->whereNotNull('examination_id')
                    ->orWhere('exam_mode', true);
            })->pluck('id')->all();
            if (empty($examActivityIds)) {
                return response()->json(['rows' => [], 'truncated' => false, 'activities' => $standaloneActivities]);
            }
            $subsQuery->whereIn('context', $examActivityIds);
        }

        $subs = $subsQuery->get();

        $appealIds = SubmissionAppeal::pluck('submission_id')->flip()->all();
        $assignedIds = SubmissionRegradeAssignment::pluck('submission_id')->flip()->all();

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

        $truncated = count($rows) > 500;
        if ($truncated) {
            $rows = array_slice($rows, 0, 500);
        }

        return response()->json([
            'rows'       => $rows,
            'truncated'  => $truncated,
            'activities' => $standaloneActivities,
        ]);
    }

    public function reviewers(Request $request): JsonResponse
    {
        $this->requireAssigner($request->user());

        $submissionId = trim((string) ($request->query('submission_id') ?? $request->query('submissionId') ?? ''));
        $excludedIds = [];
        if ($submissionId !== '') {
            $sub = Submission::find($submissionId);
            if ($sub) {
                $excludedIds = $this->classInstructorIdsForSubmission($sub);
            }
        }

        $query = User::whereIn('role', array_merge(User::INSTRUCTOR_ROLES, [User::ROLE_DEPARTMENT_HEAD]))
            ->where('status', 'Active');

        if (!empty($excludedIds)) {
            $query->whereNotIn('id', $excludedIds);
        }

        $rows = $query->orderBy('name')->orderBy('email')->get();

        return response()->json($rows->map(fn (User $u) => [
            'id'    => (string) $u->id,
            'name'  => (string) $u->name,
            'email' => (string) $u->email,
        ])->values()->all());
    }

    public function assign(Request $request, string $submissionId): JsonResponse
    {
        $user = $request->user();
        $this->requireAssigner($user);

        $sub = Submission::findOrFail($submissionId);
        abort_if(empty($sub->context) || $sub->context === 'Practice', 422, 'Chỉ bài nộp trong kỳ thi hoặc cuộc thi mới được phân công chấm phúc khảo.');
        $activity = Assignment::find($sub->context);
        abort_unless($this->isRegradeEligibleActivity($activity, $sub) || SubmissionAppeal::where('submission_id', $sub->id)->exists(), 422, 'Chỉ bài thi hoặc cuộc thi mới được phân công chấm phúc khảo.');
        $isQuestionOnly = \App\Models\WorkspaceSubmissionResult::where('submission_id', $sub->id)
            ->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL")
            ->exists();
        abort_if($isQuestionOnly, 422, 'Không thể phân công phúc khảo cho bài nộp từng câu.');
        $existing = SubmissionRegradeAssignment::find($sub->id);
        if ($existing && $existing->completed_at !== null) {
            abort(422, 'Bài nộp này đã được chấm phúc khảo xong, không thể phân công lại.');
        }
        $instructorId = (string) (
            $request->input('reviewerId')
            ?? $request->input('reviewer_id')
            ?? $request->input('instructorId')
            ?? $request->input('instructor_id')
            ?? ''
        );
        $note = trim((string) ($request->input('note') ?? '')) ?: null;

        $reviewer = User::where('id', $instructorId)
            ->whereIn('role', array_merge(User::INSTRUCTOR_ROLES, [User::ROLE_DEPARTMENT_HEAD]))
            ->where('status', 'Active')
            ->first();

        abort_unless($reviewer !== null, 404, 'Không tìm thấy giảng viên chấm phúc khảo.');

        $excludedIds = $this->classInstructorIdsForSubmission($sub, $activity);
        if (in_array((string) $reviewer->id, $excludedIds, true)) {
            abort(422, 'Không được chọn giảng viên thực hành đứng lớp của sinh viên này để chấm phúc khảo.');
        }

        $previousReviewerId = $existing && $existing->assigned_instructor_id !== $reviewer->id
            ? (string) $existing->assigned_instructor_id
            : null;
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
                'assigned_by_id'           => $user->id,
                'note'                     => $note,
                'previous_evaluated_score' => $previousEvaluatedScore,
                'previous_feedback'        => $previousFeedback,
                'assigned_at'              => now(),
                'completed_at'             => null,
            ]
        );

        $problem = Problem::find($sub->problem_id);
        $problemTitle = $problem ? $problem->title : 'Bài nộp SQL';
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

        return response()->json(array_merge($this->serializeRow($sub), ['ok' => true]));
    }

    public function unassign(Request $request, string $submissionId): JsonResponse
    {
        $this->requireAssigner($request->user());
        $sub = Submission::findOrFail($submissionId);
        abort_if(empty($sub->context) || $sub->context === 'Practice', 422, 'Chỉ bài nộp trong kỳ thi hoặc cuộc thi mới được phân công chấm phúc khảo.');
        $isQuestionOnly = \App\Models\WorkspaceSubmissionResult::where('submission_id', $sub->id)
            ->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL")
            ->exists();
        abort_if($isQuestionOnly, 422, 'Không thể phân công phúc khảo cho bài nộp từng câu.');
        $existing = SubmissionRegradeAssignment::find($sub->id);
        if ($existing && $existing->completed_at !== null) {
            abort(422, 'Bài nộp này đã được chấm phúc khảo xong, không thể phân công lại.');
        }

        $previousReviewerId = $existing ? (string) $existing->assigned_instructor_id : null;
        SubmissionRegradeAssignment::where('submission_id', $sub->id)->delete();

        if ($previousReviewerId) {
            $problem = Problem::find($sub->problem_id);
            $this->notificationService->create(
                $previousReviewerId,
                'Hủy phân công chấm phúc khảo: ' . ($problem ? $problem->title : 'Bài nộp SQL'),
                'Giáo vụ khoa đã hủy phân công chấm phúc khảo cho bài nộp này.',
                'Info',
                '/teacher/regrade',
            );
        }

        return response()->json(array_merge($this->serializeRow($sub), ['ok' => true]));
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->isFacultyOffice() || $user->isInstructor() || $user->role === User::ROLE_DEPARTMENT_HEAD,
            403,
            'Chỉ giảng viên hoặc giáo vụ khoa mới xem được danh sách chấm phúc khảo.'
        );

        $rows = SubmissionRegradeAssignment::where('assigned_instructor_id', $user->id)
            ->orderByDesc('assigned_at')
            ->get();

        $subIds = $rows->pluck('submission_id')->all();
        if (empty($subIds)) {
            return response()->json([]);
        }

        $subsById = Submission::whereIn('id', $subIds)->get()->keyBy('id');
        $orderedSubs = $rows->map(fn ($r) => $subsById->get($r->submission_id))->filter()->values();

        return response()->json($this->serializeRows($orderedSubs));
    }
}
