<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use App\Services\Access\StaffAccess;

/**
 * Đọc bài nộp: danh sách của sinh viên, chi tiết một bài, bài làm của sinh viên trong hoạt động, đối chiếu một câu.
 * Việc dựng JSON nằm ở SubmissionViewService; quyền xem ở SubmissionAccessService.
 */
class SubmissionQueryService
{
    public function __construct(
        protected readonly SubmissionViewService $views,
        protected readonly SubmissionAccessService $access,
        protected readonly SubmissionReviewService $reviewService,
        protected readonly StaffAccess $staff,
    ) {
    }

    /**
     * GET /submissions: bài nộp của chính $user, mới nhất trước.
     *
     * @param array{includeQuestionUpdates?: bool, result?: string, source?: string, search?: string} $filters
     *        source: Assignments/Assignment gộp chung; result lọc theo kết quả ĐANG HIỂN THỊ (đã che điểm)
     * @return array<int, array<string, mixed>>
     */
    public function listForStudent(User $user, array $filters): array
    {
        $includeQuestionUpdates = (bool) ($filters['includeQuestionUpdates'] ?? false);

        $query = Submission::where('user_id', $user->id);

        if (!$includeQuestionUpdates) {
            $query->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            });
        }

        $resultFilter = trim((string) ($filters['result'] ?? ''));

        if ($sourceFilter = trim((string) ($filters['source'] ?? ''))) {
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

        if ($search = mb_strtolower(trim((string) ($filters['search'] ?? '')))) {
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

            $formatted = $this->views->studentRow(
                $sub,
                $prob,
                $activity,
                $ap,
                $wsMap->get($sub->id),
                $appealsMap->get($sub->id),
                $regradesMap->get($sub->id)
            );
            if ($resultFilter !== '' && ($formatted['result'] ?? '') !== $resultFilter) {
                continue;
            }
            $out[] = $formatted;
        }

        return $out;
    }

    /** GET /submissions/{id}. */
    public function show(User $user, string $id): array
    {
        $sub = Submission::findOrFail($id);
        $this->access->assertCanView($user, $sub);

        return $this->views->teacherView($sub, $user);
    }

    /**
     * Bài làm của một sinh viên cho một bài của hoạt động: lần nộp chính thức mới nhất, hoặc bản nháp nếu chưa nộp.
     */
    public function studentWork(User $user, string $activityId, string $studentId, string $problemId): array
    {
        abort_unless($this->staff->isStaff($user), 403, 'Only instructors can view student work.');

        $activity = Assignment::findOrFail($activityId);
        $this->access->assertCanViewStudentWork($user, $activity, $studentId);

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
            return $this->views->teacherView($sub, $user);
        }

        return $this->views->unsubmittedWork($activity, $ap, $studentId, $problemId);
    }

    /**
     * POST /submissions/{id}/inspect: chạy lại bài làm của một câu so với đáp án mẫu hiện tại (không đổi điểm đã lưu).
     */
    public function inspect(User $user, string $id, ?string $part, string $questionId): array
    {
        $sub = Submission::findOrFail($id);
        abort_unless($this->access->canGradeSubmission($user, $sub, null, true), 403, 'Chỉ Giảng viên và Admin mới có quyền đối chiếu bài nộp.');

        $problem = Problem::with(['testCases.scripts', 'sqlSolutions', 'stageExercises'])->findOrFail($sub->problem_id);
        $part = strtoupper((string) ($part ?? 'SQL'));

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

        return $this->reviewService->inspectQuestion($problem, $ap, $part, $targetQuestion);
    }
}
