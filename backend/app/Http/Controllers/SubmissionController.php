<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttempt;
use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\StudentIdentity;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\TestCase;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use App\Services\ExaminationService;
use App\Services\NotificationService;
use App\Services\ProblemWorkspaceService;
use App\Services\SubmissionReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubmissionController extends Controller
{
    public function __construct(
        protected readonly ExaminationService $examinationService,
        protected readonly ProblemWorkspaceService $workspaceService,
        protected readonly SubmissionReviewService $reviewService,
        protected readonly NotificationService $notificationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $includeQuestionUpdates = filter_var($request->query('include_question_updates', false), FILTER_VALIDATE_BOOLEAN);

        $query = Submission::where('user_id', $user->id);

        if (!$includeQuestionUpdates) {
            $query->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            });
        }

        $resultFilter = trim((string) $request->query('result', ''));

        if ($sourceFilter = trim((string) $request->query('source', ''))) {
            if (in_array($sourceFilter, ['Assignments', 'Assignment'], true)) {
                $query->whereIn('source', ['Assignments', 'Assignment']);
            } elseif (in_array($sourceFilter, ['ExamSession'], true)) {
                $query->whereIn('source', ['ExamSession']);
            } else {
                $query->where('source', $sourceFilter);
            }
        }

        $subs = $query->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $problemIds = $subs->pluck('problem_id')->unique()->all();
        $problemsMap = !empty($problemIds) ? Problem::whereIn('id', $problemIds)->get()->keyBy('id') : collect([]);

        $contextIds = $subs->pluck('context')->filter(fn ($c) => $c && $c !== 'Practice')->unique()->values()->all();
        $activitiesMap = !empty($contextIds) ? Assignment::whereIn('id', $contextIds)->get()->keyBy('id') : collect([]);

        if ($search = mb_strtolower(trim((string) $request->query('search', '')))) {
            $subs = $subs->filter(function ($sub) use ($problemsMap, $activitiesMap, $search) {
                $pTitle = mb_strtolower((string) ($problemsMap->get($sub->problem_id)?->title ?? ''));
                $aTitle = mb_strtolower((string) ($activitiesMap->get($sub->context)?->title ?? ''));
                return str_contains($pTitle, $search) || str_contains($aTitle, $search);
            })->values();
        }

        $apRows = !empty($contextIds) && !empty($problemIds)
            ? AssignmentProblem::whereIn('assignment_id', $contextIds)->whereIn('problem_id', $problemIds)->get()
            : collect([]);
        $apMap = [];
        foreach ($apRows as $ap) {
            $apMap["{$ap->assignment_id}:{$ap->problem_id}"] = $ap;
        }

        $subIds = $subs->pluck('id')->all();
        $wsMap = !empty($subIds) ? WorkspaceSubmissionResult::whereIn('submission_id', $subIds)->get()->keyBy('submission_id') : collect([]);
        $appealsMap = !empty($subIds) ? SubmissionAppeal::whereIn('submission_id', $subIds)->get()->keyBy('submission_id') : collect([]);
        $regradesMap = !empty($subIds) ? SubmissionRegradeAssignment::whereIn('submission_id', $subIds)->get()->keyBy('submission_id') : collect([]);

        $out = [];
        foreach ($subs as $sub) {
            $prob = $problemsMap->get($sub->problem_id);
            $activity = ($sub->context && $sub->context !== 'Practice') ? $activitiesMap->get($sub->context) : null;
            $ap = $activity ? ($apMap["{$activity->id}:{$sub->problem_id}"] ?? null) : null;
            $ws = $wsMap->get($sub->id);
            $appeal = $appealsMap->get($sub->id);
            $regrade = $regradesMap->get($sub->id);

            $formatted = $this->formatStudentSubmission($sub, $prob, $activity, $ap, $ws, $appeal, $regrade);
            if ($resultFilter !== '' && ($formatted['result'] ?? '') !== $resultFilter) {
                continue;
            }
            $out[] = $formatted;
        }

        return response()->json($out);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $sub = Submission::findOrFail($id);
        if ($user->role === User::ROLE_STUDENT) {
            abort_unless($sub->user_id === $user->id, 403, 'Bạn không có quyền xem bài nộp này.');
        } else {
            abort_unless($this->canGradeSubmission($user, $sub, null, true), 403, 'Bạn không có quyền xem bài nộp này.');
        }
        return response()->json($this->buildTeacherSubmissionResponse($sub, $user));
    }

    public function studentWork(Request $request, string $activityId, string $studentId, string $problemId): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->isFacultyOffice() || $user->isInstructor() || $user->role === User::ROLE_DEPARTMENT_HEAD,
            403,
            'Only instructors can view student work.'
        );

        $activity = Assignment::findOrFail($activityId);
        $isPrivileged = $user->isAdmin() || $user->isFacultyOffice() || $user->role === User::ROLE_DEPARTMENT_HEAD;
        if (!$isPrivileged && $this->isExamActivity($activity)) {
            abort_unless(
                $this->isPracticalClassInstructorForStudent($user, $studentId, $activity),
                403,
                'Chỉ giảng viên thực hành đứng lớp của sinh viên mới có quyền xem bài thi này.'
            );
        } else {
            abort_unless($this->canAccessActivityForGrading($user, $activity), 403, 'Bạn không có quyền xem bài làm của hoạt động này.');
        }
        $ap = AssignmentProblem::where('assignment_id', $activityId)
            ->where('problem_id', $problemId)
            ->firstOrFail();

        $sub = Submission::where('context', $activityId)
            ->where('user_id', $studentId)
            ->where('problem_id', $problemId)
            ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->orderByDesc('submitted_at')
            ->first();

        if ($sub) {
            return response()->json($this->buildTeacherSubmissionResponse($sub, $user));
        }

        $student = User::findOrFail($studentId);
        $problem = Problem::with(['testCases.scripts', 'sqlSolutions', 'stageExercises'])->findOrFail($problemId);
        $attempt = ActivityAttempt::where('assignment_id', $activityId)->where('user_id', $studentId)->first();
        $draft = ($attempt && is_array($attempt->drafts)) ? ($attempt->drafts[$problemId] ?? []) : [];

        $parts = !empty($ap->workspace_parts) ? $ap->workspace_parts : ($problem->workspace_parts ?: ['SQL']);
        $studentQuery = !empty($draft) ? json_encode($draft, JSON_UNESCAPED_UNICODE) : '';
        $status = $attempt ? ($attempt->status === 'submitted' ? 'Submitted (empty)' : 'Draft saved') : 'Not submitted';
        $reviewSections = $this->reviewService->buildDraftReviewSections(
            is_array($draft) ? $draft : [],
            $problem,
            $ap,
            $activity,
            $student
        );
        $events = ($attempt && is_array($attempt->violations)) ? $attempt->violations : [];
        $departureCount = count(array_filter($events, fn ($e) => is_array($e) && ($e['kind'] ?? '') === 'focus_lost'));

        return response()->json([
            'id'                => "unsubmitted:{$activityId}:{$studentId}:{$problemId}",
            'studentId'         => (string) $student->id,
            'student'           => (string) $student->name,
            'studentEmail'      => (string) $student->email,
            'problemId'         => (string) $problem->id,
            'problem'           => (string) $problem->title,
            'statement'         => (string) ($problem->description ?? ''),
            'referenceSolution' => $this->buildReferenceSolutionText($problem, $parts, $student, $activity, $ap),
            'studentQuery'      => $studentQuery,
            'query'             => $studentQuery,
            'status'            => $status,
            'isSubmitted'       => false,
            'score'             => 0,
            'autoScore'         => 0,
            'maxScore'          => (float) $ap->points,
            'evaluatedScore'    => null,
            'finalScore'        => null,
            'feedback'          => null,
            'submittedAt'       => ($attempt && $attempt->submitted_at) ? $attempt->submitted_at->toISOString() : '',
            'database'          => (string) ($draft['database'] ?? $problem->database_type ?? 'SQL Server'),
            'source'            => $activity->is_exam_session ? 'ExamSession' : 'Assignments',
            'context'           => (string) $activity->id,
            'activityId'        => (string) $activity->id,
            'activityTitle'     => (string) $activity->title,
            'workspaceParts'    => $parts,
            'sections'          => $reviewSections,
            'reviewSections'    => $reviewSections,
            'attemptHistory'    => [],
            'examEvents'        => $events,
            'departureCount'    => $departureCount,
            'schemaName'        => null,
            'questionMaxScores' => (object) [],
            'appealStatus'      => null,
            'appealReason'      => null,
            'appealCreatedAt'   => null,
            'appealResolvedAt'  => null,
        ]);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $sub = Submission::findOrFail($id);
        abort_if($user->isFacultyOffice(), 403, 'Giáo vụ khoa chỉ có quyền xem điểm, không có quyền chấm bài.');
        abort_unless($this->canGradeSubmission($user, $sub), 403, 'Bạn không có quyền chấm bài nộp này.');

        $regrade = SubmissionRegradeAssignment::find($sub->id);
        if ($regrade && $regrade->assigned_instructor_id !== $user->id && !$user->isAdmin()) {
            abort(
                403,
                $regrade->completed_at === null
                    ? 'Bài nộp này đang được phân công chấm phúc khảo cho giảng viên khác.'
                    : 'Bài nộp này đã được giảng viên phúc khảo chấm xong, không thể ghi đè.'
            );
        }

        $appeal = SubmissionAppeal::find($sub->id);
        if ($appeal && !$appeal->resolved_at && !$regrade && !$user->isAdmin()) {
            abort(403, 'Đơn phúc khảo chưa được Giáo vụ khoa phân công giảng viên chấm lại.');
        }

        $evalScoreInput = $request->input(
            'finalScore',
            $request->input('final_score', $request->input('evaluatedScore', $request->input('evaluated_score')))
        );
        $feedback = $request->input('feedback');
        $questionScores = $request->input('questionScores', $request->input('question_scores'));

        $isActivity = in_array($sub->source, ['Assignments', 'ExamSession', 'Assignment'], true)
            && $sub->context && $sub->context !== 'Practice';
        $activity = $isActivity ? Assignment::find($sub->context) : null;
        $ap = ($activity && $sub->problem_id)
            ? AssignmentProblem::where('assignment_id', $activity->id)->where('problem_id', $sub->problem_id)->first()
            : null;
        $maxScore = $ap ? (float) $ap->points : ($activity ? 10.0 : 100.0);

        $ws = WorkspaceSubmissionResult::find($sub->id);
        $updatedSections = null;
        if (is_array($questionScores) && !empty($questionScores) && $ws && is_array($ws->sections)) {
            $sections = $ws->sections;
            $sum = 0.0;
            foreach ($sections as &$sec) {
                $part = $sec['part'] ?? '';
                if (!empty($sec['questions']) && is_array($sec['questions'])) {
                    foreach ($sec['questions'] as &$q) {
                        $qid = $q['id'] ?? '';
                        $key = "{$part}:{$qid}";
                        if (isset($questionScores[$key]) && is_numeric($questionScores[$key])) {
                            $q['manualScore'] = round((float) $questionScores[$key], 2);
                        }
                        if (isset($q['manualScore'])) {
                            $sum += (float) $q['manualScore'];
                        }
                    }
                    unset($q);
                }
            }
            unset($sec);
            $updatedSections = $sections;

            if ($evalScoreInput === null) {
                $evalScoreInput = round($sum, 2);
            }
        }

        $scoreVal = null;
        if ($evalScoreInput !== null) {
            if (!is_numeric($evalScoreInput)) {
                abort(422, 'Điểm số không hợp lệ.');
            }
            $scoreVal = round((float) $evalScoreInput, 2);
            if ($scoreVal < 0 || $scoreVal > $maxScore) {
                abort(422, "Điểm số không hợp lệ. Vui lòng nhập từ 0 đến {$maxScore}.");
            }
        }

        $isRegradeAction = ($regrade && ($regrade->assigned_instructor_id === $user->id || $user->isAdmin())) || ($appeal !== null);
        $prob = Problem::find($sub->problem_id);
        $scoresVisible = !$activity || (bool) $activity->show_scores_after_submit;

        DB::transaction(function () use (
            $sub,
            $ws,
            $updatedSections,
            $scoreVal,
            $maxScore,
            $feedback,
            $appeal,
            $regrade,
            $user,
            $isRegradeAction,
            $prob,
            $scoresVisible
        ) {
            $previousScore = $sub->evaluated_score !== null ? (float) $sub->evaluated_score : null;
            $previousFeedback = $sub->feedback;

            if ($ws && $updatedSections !== null) {
                $ws->sections = $updatedSections;
                $ws->save();
            }

            if ($scoreVal !== null) {
                $sub->evaluated_score = $scoreVal;
                if ($scoreVal <= 0) {
                    $sub->result = 'Rejected';
                } elseif ($scoreVal >= $maxScore) {
                    $sub->result = 'Accepted';
                } else {
                    $sub->result = 'Partial';
                }
            }
            $sub->feedback = $feedback;
            $sub->save();

            if ($appeal && !$appeal->resolved_at) {
                if ($appeal->previous_evaluated_score === null) {
                    $appeal->previous_evaluated_score = $previousScore;
                }
                $appeal->status = 'resolved';
                $appeal->resolved_at = now();
                $appeal->save();
            }

            if ($regrade && ($regrade->assigned_instructor_id === $user->id || $user->isAdmin())) {
                if ($regrade->completed_at === null && $regrade->previous_evaluated_score === null) {
                    $regrade->previous_evaluated_score = $previousScore;
                    $regrade->previous_feedback = $previousFeedback;
                }
                $regrade->completed_at = now();
                $regrade->save();
            }

            $notifTitle = ($isRegradeAction ? 'Kết quả phúc khảo: ' : 'Bài nộp đã được chấm: ') . ($prob ? $prob->title : 'SQL Problem');
            $this->notificationService->create(
                $sub->user_id,
                $notifTitle,
                $scoresVisible
                    ? ('Điểm số: ' . ($sub->evaluated_score ?? $sub->score) . " / {$maxScore}." . ($feedback ? " Phản hồi: {$feedback}" : ''))
                    : 'Bài nộp đã được chấm. Giáo viên đang ẩn điểm của hoạt động này.',
                'Review',
                "/submissions?submission={$sub->id}",
            );

            if ($regrade && $regrade->assigned_by_id && $regrade->assigned_by_id !== $user->id) {
                $assigner = User::find($regrade->assigned_by_id);
                if ($assigner) {
                    $assignerLink = $assigner->isAdmin()
                        ? "/admin/results/review/{$sub->id}"
                        : ($assigner->isFacultyOffice() ? "/office/results/review/{$sub->id}" : "/teacher/results/review/{$sub->id}");
                    $student = User::find($sub->user_id);
                    $this->notificationService->create(
                        $assigner->id,
                        'Đã hoàn tất chấm phúc khảo: ' . ($prob ? $prob->title : 'Bài nộp SQL'),
                        ($student ? $student->name : 'Sinh viên') . ': ' . ($sub->evaluated_score ?? $sub->score) . " / {$maxScore} (GV: {$user->name}).",
                        'Review',
                        $assignerLink,
                    );
                }
            }
        });

        return response()->json($this->buildTeacherSubmissionResponse($sub->refresh(), $user));
    }

    public function inspect(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $sub = Submission::findOrFail($id);
        abort_unless($this->canGradeSubmission($user, $sub, null, true), 403, 'Chỉ Giảng viên và Admin mới có quyền đối chiếu bài nộp.');

        $problem = Problem::with(['testCases.scripts', 'sqlSolutions', 'stageExercises'])->findOrFail($sub->problem_id);
        $part = strtoupper((string) ($request->input('part') ?? 'SQL'));
        $questionId = (string) ($request->input('questionId') ?? $request->input('question_id') ?? '');

        $activity = ($sub->context && $sub->context !== 'Practice') ? Assignment::find($sub->context) : null;
        $ap = $activity
            ? AssignmentProblem::where('assignment_id', $activity->id)->where('problem_id', $problem->id)->first()
            : null;
        $student = User::find($sub->user_id);
        $ws = WorkspaceSubmissionResult::find($sub->id);
        $history = Submission::where('user_id', $sub->user_id)
            ->where('problem_id', $sub->problem_id)
            ->where('source', $sub->source)
            ->where('context', $sub->context)
            ->orderBy('submitted_at')
            ->get();

        $reviewSections = $this->reviewService->buildReviewSections(
            $sub,
            $problem,
            $ap,
            ($ws && is_array($ws->sections)) ? $ws->sections : [],
            $activity,
            $student,
            $history
        );

        $targetQuestion = null;
        foreach ($reviewSections as $sec) {
            if (strtoupper((string) ($sec['part'] ?? '')) === $part) {
                foreach (($sec['questions'] ?? []) as $q) {
                    if (($q['id'] ?? '') === $questionId) {
                        $targetQuestion = $q;
                        break 2;
                    }
                }
            }
        }

        if (!$targetQuestion) {
            abort(404, 'Question not found in this submission');
        }

        return response()->json($this->reviewService->inspectQuestion($problem, $ap, $part, $targetQuestion));
    }

    public function appeal(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $sub = Submission::where('id', $id)->where('user_id', $user->id)->firstOrFail();

        $ws = WorkspaceSubmissionResult::find($sub->id);
        $wsSections = ($ws && is_array($ws->sections)) ? $ws->sections : null;
        $isQuestionOnly = is_array($wsSections) && isset($wsSections[0]['_questionSubmission']['part']);
        abort_if($isQuestionOnly, 422, 'Không thể gửi phúc khảo cho bài nộp từng câu.');

        $activity = ($sub->context && $sub->context !== 'Practice') ? Assignment::find($sub->context) : null;
        $isExamSession = in_array($sub->source, ['ExamSession'], true)
            || ($activity && ($activity->examination_id || $activity->exam_mode));
        abort_unless($isExamSession, 422, 'Chỉ bài thi mới được gửi đơn phúc khảo.');
        abort_unless($sub->evaluated_score !== null, 422, 'Bài nộp chưa được chấm chính thức, chưa thể gửi phúc khảo.');
        if ($activity && !$activity->show_scores_after_submit) {
            abort(422, 'Điểm thi chưa được công bố, chưa thể gửi phúc khảo.');
        }

        $latestOfficialId = Submission::where('user_id', $user->id)
            ->where('problem_id', $sub->problem_id)
            ->where('context', $sub->context)
            ->where('source', $sub->source)
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->value('id');
        abort_if($latestOfficialId && $latestOfficialId !== $sub->id, 422, 'Vui lòng gửi phúc khảo trên lần nộp mới nhất của bài thi.');

        $existingAppeal = SubmissionAppeal::find($sub->id);
        if ($existingAppeal) {
            abort(422, $existingAppeal->resolved_at
                ? 'Bài nộp này đã được phúc khảo xong.'
                : 'Bạn đã gửi đơn phúc khảo cho bài nộp này rồi.');
        }

        $reason = trim((string) ($request->input('reason') ?? ''));
        if (mb_strlen($reason) < 5) {
            abort(400, 'Vui lòng mô tả lý do phúc khảo rõ ràng hơn (tối thiểu 5 ký tự).');
        }

        $appeal = SubmissionAppeal::create([
            'submission_id'            => $sub->id,
            'student_id'               => $user->id,
            'reason'                   => $reason,
            'status'                   => 'pending',
            'previous_evaluated_score' => $sub->evaluated_score !== null ? (float) $sub->evaluated_score : null,
            'created_at'               => now(),
            'resolved_at'              => null,
        ]);

        $prob = Problem::find($sub->problem_id);
        $ap = $activity
            ? AssignmentProblem::where('assignment_id', $activity->id)->where('problem_id', $sub->problem_id)->first()
            : null;
        $regrade = SubmissionRegradeAssignment::find($sub->id);

        return response()->json($this->formatStudentSubmission($sub, $prob, $activity, $ap, $ws, $appeal, $regrade));
    }

    public function publishResults(Request $request, string $activityId): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->isFacultyOffice() || $user->isInstructor() || $user->role === User::ROLE_DEPARTMENT_HEAD,
            403,
            'Bạn không có quyền công bố điểm.'
        );

        $activity = Assignment::findOrFail($activityId);
        if (!$user->isAdmin() && !$user->isFacultyOffice() && $user->role !== User::ROLE_DEPARTMENT_HEAD) {
            abort_unless($activity->instructor_id === $user->id, 403, 'Bạn không có quyền công bố điểm của hoạt động này.');
        }
        $published = $request->has('published')
            ? filter_var($request->input('published'), FILTER_VALIDATE_BOOLEAN)
            : true;
        $activity->show_scores_after_submit = $published;
        $activity->save();

        ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user->id,
            'action'     => ($published ? 'results:publish:' : 'results:unpublish:') . $activity->id,
            'created_at' => now(),
        ]);

        return response()->json(['published' => $published]);
    }

    public function studentProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = $this->examinationService->studentCode($user->email, $user->id);

        $classIds = ClassEnrollment::where('student_id', $user->id)->pluck('class_id')->all();
        $classes = !empty($classIds)
            ? ClassModel::whereIn('id', $classIds)->with('instructor')->orderBy('course')->get()
            : collect([]);

        $classesPayload = $classes->map(fn (ClassModel $c) => [
            'id'           => (string) $c->id,
            'course'       => (string) $c->course,
            'courseCode'   => (string) ($c->course_code ?? ''),
            'theoryClass'  => (string) ($c->theory_class ?? $c->id),
            'practiceClass'=> (string) ($c->practice_class ?? $c->id),
            'term'         => (string) $c->term,
            'lecturer'     => (string) ($c->instructor ? $c->instructor->name : ''),
        ])->values()->all();

        $practiceSubmissions = Submission::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('source', 'Practice')->orWhere('context', 'Practice');
            })
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->count();

        $subs = Submission::where('user_id', $user->id)
            ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
            ->whereNotNull('context')
            ->where('context', '!=', 'Practice')
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->orderByDesc('submitted_at')
            ->get();

        $byActivity = [];
        foreach ($subs as $s) {
            $byActivity[$s->context][$s->problem_id] = $byActivity[$s->context][$s->problem_id] ?? $s;
        }

        $attemptIds = ActivityAttempt::where('user_id', $user->id)->pluck('assignment_id')->all();
        $activityIds = array_values(array_unique(array_merge(array_keys($byActivity), $attemptIds)));

        $activities = !empty($activityIds)
            ? Assignment::whereIn('id', $activityIds)->with('assignmentProblems')->orderByDesc('closes')->get()
            : collect([]);

        $history = [];
        foreach ($activities as $act) {
            $aps = $act->assignmentProblems;
            $maxScore = (float) $aps->sum('points');
            $earned = 0.0;
            $submittedProblems = 0;
            $latestSubAt = null;
            foreach ($aps as $ap) {
                $s = $byActivity[$act->id][$ap->problem_id] ?? null;
                if ($s) {
                    $submittedProblems++;
                    if ($s->submitted_at && (!$latestSubAt || $s->submitted_at->gt($latestSubAt))) {
                        $latestSubAt = $s->submitted_at;
                    }
                    $val = $s->evaluated_score !== null ? (float) $s->evaluated_score : (float) ($s->score ?? 0);
                    $earned += min((float) $ap->points, $val);
                }
            }
            $att = ActivityAttempt::where('assignment_id', $act->id)->where('user_id', $user->id)->first();
            $submittedAtIso = $att && $att->submitted_at
                ? $att->submitted_at->toISOString()
                : ($latestSubAt ? $latestSubAt->toISOString() : null);

            $status = 'not_submitted';
            if ($att && $att->status === 'active' && $submittedProblems === 0) {
                $status = 'in_progress';
            } elseif ($submittedProblems > 0 || ($att && $att->status === 'submitted')) {
                $status = $act->show_scores_after_submit ? 'graded' : 'unpublished';
            }

            $history[] = [
                'id'                => (string) $act->id,
                'title'             => (string) $act->title,
                'kind'              => $act->examination_id ? 'Examination' : 'Assignment',
                'examinationId'     => $act->examination_id,
                'sessionIndex'      => $act->session_index,
                'room'              => $act->room ?? '',
                'date'              => $submittedAtIso,
                'submittedAt'       => $submittedAtIso,
                'finishReason'      => $att ? $att->finish_reason : null,
                'status'            => $status,
                'score'             => $act->show_scores_after_submit ? round($earned, 2) : null,
                'maxScore'          => $maxScore,
                'scorePublished'    => (bool) $act->show_scores_after_submit,
                'submittedProblems' => $submittedProblems,
                'totalProblems'     => $aps->count(),
            ];
        }

        return response()->json([
            'id'                  => (string) $user->id,
            'name'                => (string) $user->name,
            'email'               => (string) $user->email,
            'studentCode'         => $code,
            'department'          => 'Khoa Hệ thống Thông tin',
            'practiceSubmissions' => $practiceSubmissions,
            'classes'             => $classesPayload,
            'profile'             => [
                'id'                  => (string) $user->id,
                'name'                => (string) $user->name,
                'email'               => (string) $user->email,
                'studentCode'         => $code,
                'department'          => 'Khoa Hệ thống Thông tin',
                'practiceSubmissions' => $practiceSubmissions,
                'classes'             => $classesPayload,
            ],
            'history' => $history,
        ]);
    }

    protected function formatStudentSubmission(
        Submission $sub,
        ?Problem $prob,
        ?Assignment $activity,
        ?AssignmentProblem $ap,
        ?WorkspaceSubmissionResult $ws,
        ?SubmissionAppeal $appeal,
        ?SubmissionRegradeAssignment $regrade = null
    ): array {
        $scoreVisible = !$activity || (bool) $activity->show_scores_after_submit;
        $maxScore = $ap ? (float) $ap->points : 100.0;
        $sections = ($ws && is_array($ws->sections)) ? $ws->sections : null;
        if (is_array($sections)) {
            $sections = array_map(function ($sec) {
                if (is_array($sec) && isset($sec['questions']) && is_array($sec['questions'])) {
                    $sec['questions'] = array_map(function ($q) {
                        if (is_array($q)) {
                            if (!isset($q['datasets']) || !is_array($q['datasets'])) {
                                $q['datasets'] = [];
                            }
                            if (!isset($q['ratio'])) {
                                $qTotal = (int) ($q['total'] ?? 0);
                                $qPassed = (int) ($q['passed'] ?? 0);
                                $q['ratio'] = $qTotal > 0 ? round($qPassed / $qTotal, 4) : 0.0;
                            }
                        }
                        return $q;
                    }, $sec['questions']);
                }
                return $sec;
            }, $sections);
        }
        $questionMeta = ($sections && isset($sections[0]['_questionSubmission']) && is_array($sections[0]['_questionSubmission']))
            ? $sections[0]['_questionSubmission']
            : [];
        $questionOnly = !empty($questionMeta);
        $questionPart = $questionMeta['part'] ?? null;
        $questionId = $questionMeta['questionId'] ?? null;
        $questionScore = isset($questionMeta['score']) ? (float) $questionMeta['score'] : null;

        $appealStatus = null;
        if ($appeal) {
            $appealStatus = $appeal->resolved_at ? 'Regraded' : 'Awaiting regrade';
        } elseif ($regrade && $scoreVisible) {
            $appealStatus = $regrade->completed_at ? 'Regraded' : 'Awaiting regrade';
        } elseif ($scoreVisible && $sub->evaluated_score !== null) {
            $appealStatus = 'Graded';
        }

        $isExamSession = in_array($sub->source, ['ExamSession'], true)
            || ($activity && ($activity->examination_id || $activity->exam_mode));
        $canAppeal = $isExamSession
            && !empty($sub->context)
            && $scoreVisible
            && $sub->evaluated_score !== null
            && $appealStatus === 'Graded'
            && !$questionOnly;

        return [
            'id'               => (string) $sub->id,
            'problemId'        => (string) $sub->problem_id,
            'problem'          => $prob ? (string) $prob->title : 'Unknown Problem',
            'topic'            => $prob ? (string) ($prob->topic ?? 'General') : 'General',
            'source'           => (string) ($sub->source ?: 'Practice'),
            'context'          => (string) ($sub->context ?: 'Practice'),
            'contextTitle'     => $activity ? (string) $activity->title : (string) ($sub->context ?: 'Practice'),
            'result'           => $scoreVisible ? (string) ($sub->result ?: 'Submitted') : 'Submitted',
            'time'             => $sub->submitted_at ? $sub->submitted_at->diffForHumans() : 'Just now',
            'score'            => $scoreVisible ? ($sub->score !== null ? (float) $sub->score : null) : null,
            'maxScore'         => $maxScore,
            'evaluatedScore'   => $scoreVisible ? ($sub->evaluated_score !== null ? (float) $sub->evaluated_score : null) : null,
            'feedback'         => $scoreVisible ? $sub->feedback : null,
            'submittedAt'      => $sub->submitted_at ? $sub->submitted_at->toISOString() : null,
            'query'            => (string) ($sub->query_text ?? ''),
            'database'         => (string) ($sub->database_type ?: 'SQL Server'),
            'sections'         => ($scoreVisible && $sections) ? $sections : null,
            'scoreVisible'     => $scoreVisible,
            'questionOnly'     => $questionOnly,
            'questionPart'     => $questionPart,
            'questionId'       => $questionId,
            'questionScore'    => $scoreVisible ? $questionScore : null,
            'submittedScript'  => $questionOnly ? (string) ($sub->query_text ?? '') : null,
            'canAppeal'        => $canAppeal,
            'appealStatus'     => $appealStatus,
            'appealReason'     => $appeal ? (string) $appeal->reason : null,
            'appealCreatedAt'  => $appeal && $appeal->created_at ? $appeal->created_at->toISOString() : null,
            'appealResolvedAt' => $appeal && $appeal->resolved_at ? $appeal->resolved_at->toISOString() : null,
        ];
    }

    protected function isExamActivity(Assignment $activity, ?Submission $sub = null): bool
    {
        if ($activity->examination_id || $activity->exam_mode) {
            return true;
        }
        return $sub !== null && in_array($sub->source, ['ExamSession'], true);
    }

    protected function isPracticalClassInstructorForStudent(User $viewer, string $studentId, Assignment $activity): bool
    {
        $practicalClassIds = ClassModel::where(function ($q) use ($viewer) {
            $q->where('practice_instructor_id', $viewer->id)
                ->orWhereIn('id', DB::table('class_instructors')
                    ->where('instructor_id', $viewer->id)
                    ->where('assignment_type', 'practical')
                    ->select('class_id'));
        })->pluck('id')->all();

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

        return \App\Models\ClassEnrollment::where('student_id', $studentId)
            ->whereIn('class_id', $scopedClassIds)
            ->exists();
    }

    protected function canAccessActivityForGrading(User $viewer, Assignment $activity): bool
    {
        if ($viewer->isAdmin() || $viewer->isFacultyOffice() || $viewer->role === User::ROLE_DEPARTMENT_HEAD) {
            return true;
        }
        if ($viewer->role === User::ROLE_STUDENT || $viewer->role === 'student') {
            return false;
        }
        if ($this->isExamActivity($activity)) {
            $practicalClassIds = ClassModel::where(function ($q) use ($viewer) {
                $q->where('practice_instructor_id', $viewer->id)
                    ->orWhereIn('id', DB::table('class_instructors')
                        ->where('instructor_id', $viewer->id)
                        ->where('assignment_type', 'practical')
                        ->select('class_id'));
            })->pluck('id')->all();
            if (empty($practicalClassIds)) {
                return false;
            }
            $classIds = $activity->classes()->pluck('classes.id')->all();
            return empty($classIds) || !empty(array_intersect($practicalClassIds, $classIds));
        }
        if ($activity->instructor_id === $viewer->id) {
            return true;
        }
        if (\App\Models\ExamClassProctor::where('session_id', $activity->id)->where('proctor_id', $viewer->id)->exists()) {
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

    protected function canGradeSubmission(User $viewer, Submission $sub, ?Assignment $activity = null, bool $allowSiblingAttemptRead = false): bool
    {
        if ($viewer->isAdmin() || $viewer->isFacultyOffice() || $viewer->role === User::ROLE_DEPARTMENT_HEAD) {
            return true;
        }
        if ($viewer->role === User::ROLE_STUDENT || $viewer->role === 'student') {
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

    protected function buildTeacherSubmissionResponse(Submission $sub, User $viewer): array
    {
        $student = User::find($sub->user_id);
        $problem = Problem::with(['testCases.scripts', 'sqlSolutions', 'stageExercises'])->find($sub->problem_id);
        $activity = ($sub->context && $sub->context !== 'Practice') ? Assignment::find($sub->context) : null;
        $ap = ($activity && $problem)
            ? AssignmentProblem::where('assignment_id', $activity->id)->where('problem_id', $problem->id)->first()
            : null;

        $parts = $ap && !empty($ap->workspace_parts)
            ? $ap->workspace_parts
            : ($problem && !empty($problem->workspace_parts) ? $problem->workspace_parts : ['SQL']);

        $ws = WorkspaceSubmissionResult::find($sub->id);
        $appeal = SubmissionAppeal::find($sub->id);
        $regrade = SubmissionRegradeAssignment::find($sub->id);
        $regradeReviewer = $regrade ? User::find($regrade->assigned_instructor_id) : null;

        $isStaff = $viewer->role !== User::ROLE_STUDENT && $viewer->role !== 'student';
        $scoresVisible = $isStaff || !$activity || (bool) $activity->show_scores_after_submit;

        $refSol = ($isStaff && $problem && $student)
            ? $this->buildReferenceSolutionText($problem, $parts, $student, $activity, $ap)
            : '';

        $officialRows = Submission::where('user_id', $sub->user_id)
            ->where('problem_id', $sub->problem_id)
            ->where('source', $sub->source)
            ->where('context', $sub->context)
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        $allRows = Submission::where('user_id', $sub->user_id)
            ->where('problem_id', $sub->problem_id)
            ->where('source', $sub->source)
            ->where('context', $sub->context)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        $reviewSections = ($isStaff && $problem)
            ? $this->reviewService->buildReviewSections(
                $sub,
                $problem,
                $ap,
                ($ws && is_array($ws->sections)) ? $ws->sections : [],
                $activity,
                $student,
                $allRows
            )
            : [];

        $history = $isStaff
            ? $officialRows->values()->map(fn (Submission $item, int $idx) => [
                'id'          => (string) $item->id,
                'number'      => $idx + 1,
                'submittedAt' => $item->submitted_at ? $item->submitted_at->toISOString() : null,
                'autoScore'   => (float) ($item->score ?? 0),
                'finalScore'  => $item->evaluated_score !== null ? (float) $item->evaluated_score : null,
                'status'      => (string) ($item->result ?: 'Submitted'),
            ])->all()
            : [];

        $examAttempt = ($activity && $isStaff)
            ? ActivityAttempt::where('assignment_id', $activity->id)->where('user_id', $sub->user_id)->first()
            : null;
        $events = ($examAttempt && is_array($examAttempt->violations)) ? $examAttempt->violations : [];
        $departureCount = count(array_filter($events, fn ($e) => is_array($e) && ($e['kind'] ?? '') === 'focus_lost'));

        $autoScore = $scoresVisible ? (float) ($sub->score ?? 0) : null;
        $finalScore = $scoresVisible && $sub->evaluated_score !== null ? (float) $sub->evaluated_score : null;

        return [
            'id'                => (string) $sub->id,
            'studentId'         => (string) $sub->user_id,
            'student'           => $student ? (string) $student->name : 'Unknown Student',
            'studentEmail'      => $student ? (string) $student->email : '',
            'problemId'         => (string) $sub->problem_id,
            'problem'           => $problem ? (string) $problem->title : 'Unknown Problem',
            'statement'         => $problem ? (string) ($problem->description ?? '') : '',
            'referenceSolution' => $refSol,
            'studentQuery'      => (string) ($sub->query_text ?? ''),
            'query'             => (string) ($sub->query_text ?? ''),
            'status'            => $scoresVisible ? $this->reviewService->reviewStatus($sub, $appeal, $regrade) : 'Submitted',
            'isSubmitted'       => true,
            'attempts'          => (string) $officialRows->count(),
            'score'             => $autoScore ?? 0,
            'autoScore'         => $autoScore,
            'maxScore'          => $ap ? (float) $ap->points : 100.0,
            'evaluatedScore'    => $finalScore,
            'finalScore'        => $finalScore,
            'feedback'          => $scoresVisible ? $sub->feedback : null,
            'submittedAt'       => $sub->submitted_at ? $sub->submitted_at->toISOString() : null,
            'database'          => (string) ($sub->database_type ?: 'SQL Server'),
            'source'            => (string) ($sub->source ?: 'Practice'),
            'context'           => (string) ($sub->context ?: 'Practice'),
            'activityId'        => $activity ? (string) $activity->id : null,
            'activityTitle'     => $activity ? (string) $activity->title : null,
            'isExam'            => $activity ? (bool) ($activity->examination_id || $activity->exam_mode) : ($sub->source === 'ExamSession'),
            'examinationId'     => $activity && $activity->examination_id ? (string) $activity->examination_id : null,
            'canEditReview'     => $isStaff
                && !$viewer->isFacultyOffice()
                && $this->canGradeSubmission($viewer, $sub, $activity)
                && (!$regrade || $regrade->assigned_instructor_id === $viewer->id || $viewer->isAdmin()),
            'regradeAssignment' => $regrade ? [
                'reviewerId'             => (string) $regrade->assigned_instructor_id,
                'reviewerName'           => $regradeReviewer ? (string) $regradeReviewer->name : '',
                'note'                   => $regrade->note,
                'previousEvaluatedScore' => $regrade->previous_evaluated_score !== null ? (float) $regrade->previous_evaluated_score : null,
                'previousFeedback'       => $regrade->previous_feedback,
                'assignedAt'             => $regrade->assigned_at ? $regrade->assigned_at->toISOString() : null,
                'completedAt'            => $regrade->completed_at ? $regrade->completed_at->toISOString() : null,
            ] : null,
            'workspaceParts'    => $parts,
            'sections'          => ($ws && is_array($ws->sections)) ? $ws->sections : [],
            'reviewSections'    => $reviewSections,
            'attemptHistory'    => $history,
            'examEvents'        => $events,
            'departureCount'    => $departureCount,
            'schemaName'        => $sub->schema_name,
            'questionMaxScores' => (object) [],
            'appealStatus'      => $appeal ? (string) $appeal->status : null,
            'appealReason'      => $appeal ? (string) $appeal->reason : null,
            'appealCreatedAt'   => $appeal && $appeal->created_at ? $appeal->created_at->toISOString() : null,
            'appealResolvedAt'  => $appeal && $appeal->resolved_at ? $appeal->resolved_at->toISOString() : null,
            'previousEvaluatedScore' => $regrade?->previous_evaluated_score ?? $appeal?->previous_evaluated_score,
        ];
    }

    protected function buildReferenceSolutionText(
        Problem $problem,
        array $parts,
        User $student,
        ?Assignment $activity,
        ?AssignmentProblem $ap
    ): string {
        $selection = $this->examinationService->studentSelectionForProblem($student, $activity, $ap);
        $blocks = [];

        foreach ($parts as $part) {
            $part = strtoupper((string) $part);
            if ($part === 'SQL') {
                $sols = $problem->sqlSolutions->sortBy('order_index')->filter(fn ($s) => $s->enabled && trim($s->query) !== '')->values();
                if (is_array($selection) && isset($selection['SQL']['questionIds'])) {
                    $allowed = array_flip($selection['SQL']['questionIds']);
                    $sols = $sols->filter(fn ($s) => isset($allowed[$s->id]))->values();
                }
                foreach ($sols as $sol) {
                    $blocks[] = "-- [SQL] {$sol->name}\n" . trim($sol->query);
                }
            } else {
                $ex = $problem->stageExercises->firstWhere('part', $part);
                $questions = $ex && is_array($ex->config) ? ($ex->config['questions'] ?? []) : [];
                if (is_array($selection) && isset($selection[$part]['questionIds'])) {
                    $allowed = array_flip($selection[$part]['questionIds']);
                    $questions = array_values(array_filter($questions, fn ($q) => isset($allowed[$q['id'] ?? ''])));
                }
                foreach ($questions as $q) {
                    $name = $q['name'] ?? "{$part}.sql";
                    $ans = trim((string) ($q['answer'] ?? ''));
                    if ($ans !== '') {
                        $blocks[] = "-- [{$part}] {$name}\n{$ans}";
                    }
                }
            }
        }

        if (empty($blocks)) {
            $tc = $problem->testCases->sortBy('order_index')->first();
            return (string) ($tc?->expected_query ?? '');
        }

        return implode("\n\n", $blocks);
    }
}
