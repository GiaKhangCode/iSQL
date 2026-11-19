<?php

namespace App\Services;

use App\Models\ActivityAttempt;
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
 * Dựng các phản hồi JSON về bài nộp: một dòng trong danh sách của sinh viên, màn xem chi tiết (cho sinh viên và
 * cho giảng viên), bài làm chưa nộp và văn bản đáp án mẫu. Tên khóa là hợp đồng với frontend (studentApi, teacherService).
 *
 * Che điểm: khi hoạt động ẩn điểm (show_scores_after_submit = false) sinh viên không thấy điểm, nhận xét, trạng thái
 * chấm và kết quả từng câu; nhân sự luôn thấy.
 */
class SubmissionViewService
{
    public function __construct(
        protected readonly ExaminationService $examinationService,
        protected readonly SubmissionReviewService $reviewService,
        protected readonly StaffAccess $staff,
        protected readonly SubmissionAccessService $access,
    ) {
    }

    /** Một dòng của GET /submissions (góc nhìn sinh viên). */
    public function studentRow(
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

    /**
     * Chi tiết một bài nộp (GET /submissions/{id}, studentWork, kết quả của review). Nhân sự thấy thêm đáp án mẫu,
     * các phần chấm, lịch sử các lần nộp và sự kiện coi thi; sinh viên chỉ thấy phần của mình (đã che điểm nếu cần).
     */
    public function teacherView(Submission $sub, User $viewer): array
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

        $isStaff = $this->staff->isStaff($viewer);
        $scoresVisible = $isStaff || !$activity || (bool) $activity->show_scores_after_submit;

        $refSol = ($isStaff && $problem && $student)
            ? $this->referenceSolutionText($problem, $parts, $student, $activity, $ap)
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
                && $this->staff->canGrade($viewer)
                && $this->access->canGradeSubmission($viewer, $sub, $activity)
                && (!$regrade || $regrade->assigned_instructor_id === $viewer->id),
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
            // Kết quả từng câu (kể cả bảng kết quả mong đợi) chỉ trả cho sinh viên khi điểm đã được công bố.
            'sections'          => ($scoresVisible && $ws && is_array($ws->sections)) ? $ws->sections : [],
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

    /** Bài làm của một sinh viên chưa nộp: dựng từ bản nháp trong lượt làm bài (nếu có). */
    public function unsubmittedWork(Assignment $activity, AssignmentProblem $ap, string $studentId, string $problemId): array
    {
        $student = User::findOrFail($studentId);
        $problem = Problem::with(['testCases.scripts', 'sqlSolutions', 'stageExercises'])->findOrFail($problemId);
        $attempt = ActivityAttempt::where('assignment_id', $activity->id)->where('user_id', $studentId)->first();
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

        return [
            'id'                => "unsubmitted:{$activity->id}:{$studentId}:{$problemId}",
            'studentId'         => (string) $student->id,
            'student'           => (string) $student->name,
            'studentEmail'      => (string) $student->email,
            'problemId'         => (string) $problem->id,
            'problem'           => (string) $problem->title,
            'statement'         => (string) ($problem->description ?? ''),
            'referenceSolution' => $this->referenceSolutionText($problem, $parts, $student, $activity, $ap),
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
        ];
    }

    /** Đáp án mẫu dạng văn bản ("-- [PHẦN] tên" + mã), theo các câu mà sinh viên này được giao. */
    public function referenceSolutionText(
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
