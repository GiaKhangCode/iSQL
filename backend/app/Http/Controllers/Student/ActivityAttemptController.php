<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use App\Services\ExamAdmissionService;
use App\Services\ExaminationService;
use App\Services\ProblemWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityAttemptController extends Controller
{
    public const DEPARTURE_REASONS = ['tab-hidden', 'window-blur', 'fullscreen-exit', 'page-leave'];

    public function __construct(
        protected readonly ExamAdmissionService $examAdmissionService,
        protected readonly ExaminationService $examinationService,
        protected readonly ProblemWorkspaceService $workspaceService
    ) {
    }

    public function show(Request $request, string $activityId): JsonResponse
    {
        $user = $request->user();
        $source = (string) $request->query('source', 'Assignments');
        $activity = $this->resolveActivity($request, $activityId, $source, $user, true);

        $attempt = $this->getOrCreateAttempt($activity->id, $user->id, false);
        if ($attempt && $attempt->status !== 'submitted' && $this->pastDeadline($activity)) {
            $this->finishAttempt($activity, $attempt, $user, $source, 'timeout');
            $attempt = $this->getAttempt($activity->id, $user->id);
        }

        return response()->json($this->attemptResponse($activity, $attempt, $user));
    }

    public function start(Request $request, string $activityId): JsonResponse
    {
        $user = $request->user();
        $source = (string) ($request->input('source') ?? 'Assignments');
        $activity = $this->resolveActivity($request, $activityId, $source, $user, false);

        if (!$activity->exam_mode) {
            abort(400, 'Activity is not in exam mode.');
        }

        $attempt = $this->getOrCreateAttempt($activity->id, $user->id, true);
        if ($attempt->status === 'submitted') {
            return response()->json($this->attemptResponse($activity, $attempt, $user));
        }

        if ($this->pastDeadline($activity)) {
            $this->finishAttempt($activity, $attempt, $user, $source, 'timeout');
            $attempt = $this->getAttempt($activity->id, $user->id);
        }

        return response()->json($this->attemptResponse($activity, $attempt, $user));
    }

    public function updateAction(Request $request, string $activityId, string $action): JsonResponse
    {
        if (!in_array($action, ['save', 'violation', 'event', 'submit', 'timeout'], true)) {
            abort(404);
        }

        $user = $request->user();
        $source = (string) ($request->input('source') ?? 'Assignments');
        $allowClosed = in_array($action, ['submit', 'timeout'], true);
        $activity = $this->resolveActivity($request, $activityId, $source, $user, $allowClosed);

        $createExam = $activity->exam_mode && in_array($action, ['submit', 'timeout'], true);
        $attempt = $this->getOrCreateAttempt($activity->id, $user->id, $createExam);

        if (!$attempt) {
            abort(409, 'Start the exam before continuing.');
        }

        if ($attempt->status === 'submitted') {
            return response()->json($this->attemptResponse($activity, $attempt, $user));
        }

        $draftsInput = $request->input('drafts');
        if (is_array($draftsInput) && !empty($draftsInput)) {
            $attempt->drafts = $this->mergeDrafts((array) ($attempt->drafts ?? []), $draftsInput);
        }

        // Save top-level single-problem snapshot sent by frontend WorkspaceExamContext (problemId, ddl, dml, query, trigger, revision)
        $this->saveSnapshot($activity, $attempt, $request->all());

        $submissions = [];
        if (in_array($action, ['violation', 'event'], true)) {
            $eventId = trim((string) ($request->input('eventId') ?? $request->input('event_id') ?? ''));
            $kind = trim((string) ($request->input('kind') ?? ''));
            $durationMs = $request->input('durationMs') ?? $request->input('duration_ms');
            $counted = ($action === 'violation') && !in_array($kind, ['focus_returned', 'clipboard_panel'], true);
            $reason = $kind !== '' ? $kind : (trim((string) ($request->input('reason') ?? '')) ?: ($counted ? 'focus-lost' : 'activity-event'));
            $detail = trim((string) ($request->input('detail') ?? ''));

            $violations = is_array($attempt->violations) ? $attempt->violations : [];
            $alreadyRecorded = false;
            if ($eventId !== '') {
                foreach ($violations as $existingEvt) {
                    if (is_array($existingEvt) && ($existingEvt['eventId'] ?? '') === $eventId) {
                        $alreadyRecorded = true;
                        break;
                    }
                }
            }

            if (!$alreadyRecorded) {
                $event = [
                    'reason'  => substr($reason, 0, 80),
                    'at'      => now()->toISOString(),
                    'counted' => $counted,
                ];
                if ($eventId !== '') {
                    $event['eventId'] = substr($eventId, 0, 64);
                }
                if ($kind !== '') {
                    $event['kind'] = substr($kind, 0, 40);
                }
                if ($durationMs !== null && is_numeric($durationMs)) {
                    $event['durationMs'] = (int) $durationMs;
                }
                if ($detail !== '') {
                    $event['detail'] = substr($detail, 0, 240);
                }
                $violations[] = $event;
                $attempt->violations = $violations;
            }

            $limit = max(1, (int) ($activity->exam_violation_limit ?? 2));
            if ($counted && $activity->exam_mode && $this->violationCount($attempt) >= $limit) {
                $submissions = $this->finishAttempt($activity, $attempt, $user, $source, 'violations');
            } else {
                $attempt->save();
            }
        } elseif (in_array($action, ['submit', 'timeout'], true)) {
            $reason = ($action === 'timeout' || $this->pastDeadline($activity)) ? 'timeout' : 'submitted';
            $submissions = $this->finishAttempt($activity, $attempt, $user, $source, $reason);
        } else {
            // save
            if ($this->pastDeadline($activity)) {
                $submissions = $this->finishAttempt($activity, $attempt, $user, $source, 'timeout');
            } else {
                $attempt->save();
            }
        }

        $attempt = $this->getAttempt($activity->id, $user->id);
        $payload = $this->attemptResponse($activity, $attempt, $user);
        if (in_array($action, ['submit', 'timeout'], true)) {
            $payload['submissions'] = $submissions;
        }

        return response()->json($payload);
    }

    protected function resolveActivity(Request $request, string $activityId, string $source, User $user, bool $allowClosed = false): Assignment
    {
        if ($user->role !== User::ROLE_STUDENT && $user->role !== 'student' && !$user->isAdmin()) {
            abort(403, 'Only students can record activity attempts.');
        }

        $activity = Assignment::findOrFail($activityId);
        if (!$activity->published) {
            abort(403, 'This activity is not available.');
        }

        if ($activity->opens && now()->lessThan($activity->opens)) {
            abort(403, 'This activity has not opened yet.');
        }

        if (!$allowClosed && $this->pastDeadline($activity)) {
            abort(403, 'The submission window for this activity has closed.');
        }

        if ($activity->admission_required && !$this->examAdmissionService->hasUnlockedAdmission($request, $user, $activity)) {
            $hasSubmittedAttempt = ActivityAttempt::where('assignment_id', $activity->id)
                ->where('user_id', $user->id)
                ->where('status', 'submitted')
                ->exists();
            if (!$hasSubmittedAttempt || !$allowClosed) {
                abort(403, 'Cần đăng nhập tài khoản ca thi trước khi truy cập.');
            }
        }

        $accessState = ExamAccessState::where('session_id', $activity->id)
            ->where('student_id', $user->id)
            ->first();
        if ($accessState && $accessState->locked && !$allowClosed) {
            abort(403, 'Tài khoản ca thi đang tạm khóa. Vui lòng báo giám thị phòng thi.');
        }

        $classIds = ClassEnrollment::where('student_id', $user->id)->pluck('class_id')->all();
        $inClass = !empty($classIds) && DB::table('assignment_classes')
            ->where('assignment_id', $activity->id)
            ->whereIn('class_id', $classIds)
            ->exists();
        $isSelected = DB::table('assignment_students')
            ->where('assignment_id', $activity->id)
            ->where('student_id', $user->id)
            ->exists();
        $hasAdmission = ExamAdmission::where('session_id', $activity->id)
            ->where('student_id', $user->id)
            ->where('status', '!=', 'revoked')
            ->exists();

        if (!$inClass && !$isSelected && !$hasAdmission) {
            abort(403, 'You are not assigned to this activity.');
        }

        return $activity;
    }

    protected function getAttempt(string $activityId, string $userId): ?ActivityAttempt
    {
        return ActivityAttempt::where('assignment_id', $activityId)
            ->where('user_id', $userId)
            ->first();
    }

    protected function getOrCreateAttempt(string $activityId, string $userId, bool $createForExam = true): ?ActivityAttempt
    {
        $attempt = $this->getAttempt($activityId, $userId);
        if ($attempt) {
            return $attempt;
        }

        $activity = Assignment::find($activityId);
        if ($activity && $activity->exam_mode && !$createForExam) {
            return null;
        }

        return ActivityAttempt::create([
            'assignment_id' => $activityId,
            'user_id'       => $userId,
            'status'        => 'active',
            'drafts'        => [],
            'violations'    => [],
            'started_at'    => now(),
        ]);
    }

    protected function pastDeadline(Assignment $activity): bool
    {
        return $activity->closes !== null && now()->greaterThanOrEqualTo($activity->closes);
    }

    protected function violationCount(?ActivityAttempt $attempt): int
    {
        if (!$attempt || !is_array($attempt->violations)) {
            return 0;
        }
        return collect($attempt->violations)->filter(fn ($e) => is_array($e) && ($e['counted'] ?? true) === true)->count();
    }

    protected function departureCount(?ActivityAttempt $attempt): int
    {
        if (!$attempt || !is_array($attempt->violations)) {
            return 0;
        }
        return collect($attempt->violations)->filter(function ($e) {
            if (!is_array($e)) {
                return false;
            }
            return ($e['counted'] ?? true) === true || in_array($e['reason'] ?? '', self::DEPARTURE_REASONS, true);
        })->count();
    }

    protected function mergeDrafts(array $current, array $incoming): array
    {
        $merged = $current;
        foreach ($incoming as $problemId => $draft) {
            if (!is_array($draft)) {
                continue;
            }
            $prev = is_array($merged[$problemId] ?? null) ? $merged[$problemId] : [];
            $nextQuestions = is_array($prev['questions'] ?? null) ? $prev['questions'] : [];
            if (is_array($draft['questions'] ?? null)) {
                foreach ($draft['questions'] as $part => $values) {
                    if (is_array($values)) {
                        $prevPart = is_array($nextQuestions[$part] ?? null) ? $nextQuestions[$part] : [];
                        foreach ($values as $qid => $code) {
                            $prevPart[(string) $qid] = (string) ($code ?? '');
                        }
                        $nextQuestions[(string) $part] = $prevPart;
                    }
                }
            }

            $entry = [
                'ddl'       => (string) ($draft['ddl'] ?? $prev['ddl'] ?? ''),
                'dml'       => (string) ($draft['dml'] ?? $prev['dml'] ?? ''),
                'query'     => (string) ($draft['query'] ?? $prev['query'] ?? ''),
                'trigger'   => (string) ($draft['trigger'] ?? $prev['trigger'] ?? ''),
                'database'  => (string) ($draft['database'] ?? $prev['database'] ?? 'SQL Server'),
                'updatedAt' => now()->toISOString(),
            ];
            if (!empty($nextQuestions)) {
                $entry['questions'] = $nextQuestions;
            }
            $merged[(string) $problemId] = $entry;
        }

        return $merged;
    }

    protected function saveSnapshot(Assignment $activity, ActivityAttempt $attempt, array $input): void
    {
        $problemId = (string) ($input['problemId'] ?? $input['problem_id'] ?? '');
        if ($problemId === '') {
            return;
        }

        $isMember = AssignmentProblem::where('assignment_id', $activity->id)
            ->where('problem_id', $problemId)
            ->exists();
        if (!$isMember) {
            return;
        }

        $hasAnyField = array_key_exists('ddl', $input)
            || array_key_exists('dml', $input)
            || array_key_exists('query', $input)
            || array_key_exists('trigger', $input);
        if (!$hasAnyField) {
            return;
        }

        $drafts = is_array($attempt->drafts) ? $attempt->drafts : [];
        $prev = is_array($drafts[$problemId] ?? null) ? $drafts[$problemId] : [];
        $incomingRev = (int) ($input['revision'] ?? 0);
        $prevRev = (int) ($prev['revision'] ?? 0);

        if ($incomingRev >= $prevRev) {
            $drafts[$problemId] = [
                'ddl'       => (string) ($input['ddl'] ?? $prev['ddl'] ?? ''),
                'dml'       => (string) ($input['dml'] ?? $prev['dml'] ?? ''),
                'query'     => (string) ($input['query'] ?? $prev['query'] ?? ''),
                'trigger'   => (string) ($input['trigger'] ?? $prev['trigger'] ?? ''),
                'database'  => (string) ($input['database'] ?? $prev['database'] ?? 'SQL Server'),
                'revision'  => $incomingRev,
                'updatedAt' => now()->toISOString(),
            ];
            if (isset($prev['questions']) && is_array($prev['questions'])) {
                $drafts[$problemId]['questions'] = $prev['questions'];
            }
            $attempt->drafts = $drafts;
        }
    }

    /**
     * Parse "-- solution:<id>" markers written by frontend writeQuestionDrafts()
     */
    protected function extractQuestionScripts(string $script, array $questionIds): array
    {
        if (trim($script) === '') {
            return [];
        }
        if (preg_match_all('/^-- solution:([^\r\n]+)(?:\r?\n|$)/m', $script, $matches, PREG_OFFSET_CAPTURE)) {
            $result = [];
            $count = count($matches[0]);
            for ($i = 0; $i < $count; $i++) {
                $qid = trim($matches[1][$i][0]);
                $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
                $end = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($script);
                $result[$qid] = trim(substr($script, $start, $end - $start));
            }
            return $result;
        }

        return !empty($questionIds) ? [(string) $questionIds[0] => trim($script)] : [];
    }

    protected function finishAttempt(Assignment $activity, ActivityAttempt $attempt, User $user, string $source, string $reason): array
    {
        if ($attempt->status === 'submitted') {
            return [];
        }

        $members = AssignmentProblem::where('assignment_id', $activity->id)
            ->orderBy('order_index')
            ->get();

        $drafts = is_array($attempt->drafts) ? $attempt->drafts : [];
        $results = [];

        foreach ($members as $member) {
            $problem = Problem::with(['testCases.scripts', 'sqlSolutions', 'stageExercises'])->find($member->problem_id);
            if (!$problem) {
                continue;
            }

            $draft = is_array($drafts[$problem->id] ?? null) ? $drafts[$problem->id] : [];
            $parts = !empty($member->workspace_parts) ? $member->workspace_parts : ($problem->workspace_parts ?: ['SQL']);

            $sub = $this->gradeAndStoreMemberSubmission($user, $activity, $member, $problem, $source, $draft, $parts);
            $results[] = [
                'problemId'    => (string) $problem->id,
                'submissionId' => (string) $sub->id,
                'status'       => $activity->show_scores_after_submit ? $sub->result : 'Submitted',
                'score'        => $activity->show_scores_after_submit ? (float) $sub->score : null,
                'maxScore'     => (float) $member->points,
                'scoreVisible' => (bool) $activity->show_scores_after_submit,
            ];
        }

        $attempt->status = 'submitted';
        $attempt->submitted_at = now();
        $attempt->finish_reason = $reason;
        $attempt->save();

        return $results;
    }

    protected function gradeAndStoreMemberSubmission(
        User $user,
        Assignment $activity,
        AssignmentProblem $member,
        Problem $problem,
        string $source,
        array $draft,
        array $parts
    ): Submission {
        $selection = $this->examinationService->studentSelectionForProblem($user, $activity, $member);
        $questionsMap = is_array($draft['questions'] ?? null) ? $draft['questions'] : [];
        $schemaName = app(\App\Services\SqlServer\SandboxService::class)->studentSchemaName($user->id, $activity->id, $problem->id);

        $tc = $problem->testCases->sortBy('order_index')->where('is_hidden', false)->first()
            ?? $problem->testCases->sortBy('order_index')->first();
        $script = $tc?->scripts->first();
        $schemaSql = $script?->create_script ?? '';
        $seedSql = $script?->insert_script ?? '';

        $sections = [];
        $totalQuestions = 0;
        $passedQuestions = 0;

        foreach ($parts as $part) {
            $part = strtoupper((string) $part);
            $partDraftQuestions = is_array($questionsMap[$part] ?? null) ? $questionsMap[$part] : [];

            if ($part === 'SQL') {
                $sols = $this->workspaceService->resolveSqlSolutionsForGrading($problem, $selection);

                $solIds = $sols->pluck('id')->all();
                $extractedSql = $this->extractQuestionScripts((string) ($draft['query'] ?? ''), $solIds);

                $qResults = [];
                $partPassed = 0;
                $partTotal = max(1, $sols->count());

                foreach ($sols as $sol) {
                    $studentSql = trim((string) ($partDraftQuestions[$sol->id] ?? $extractedSql[$sol->id] ?? ''));
                    $totalQuestions++;
                    if ($studentSql === '') {
                        $qResults[] = [
                            'id'          => $sol->id,
                            'name'        => $sol->name,
                            'description' => $sol->description ?? '',
                            'status'      => 'Not Answered',
                            'message'     => 'No query submitted for this question.',
                            'passed'      => 0,
                            'total'       => 1,
                            'ratio'       => 0.0,
                            'datasets'    => [],
                            'query'       => '',
                        ];
                        continue;
                    }

                    $run = $this->workspaceService->runOrSubmitDql($problem, [
                        'query'      => $studentSql,
                        'solutionId' => $sol->id,
                        'source'     => $source,
                        'context'    => $activity->id,
                        'schemaName' => $schemaName,
                        '_skipStore' => true,
                    ], $user, true);

                    $qPassed = (int) ($run['passed'] ?? 0);
                    $qTotal = max(1, (int) ($run['total'] ?? 1));
                    $matched = ($run['status'] ?? '') === 'Accepted' && $qPassed === $qTotal && ($run['total'] ?? 0) > 0;
                    if ($matched) {
                        $partPassed++;
                        $passedQuestions++;
                    }
                    $qResults[] = [
                        'id'              => $sol->id,
                        'name'            => $sol->name,
                        'description'     => $sol->description ?? '',
                        'status'          => $matched ? 'Accepted' : (($run['status'] ?? '') === 'Runtime Error' ? 'Runtime Error' : 'Wrong Answer'),
                        'message'         => $matched ? 'Passed all checks.' : ($run['message'] ?? 'Wrong Answer'),
                        'passed'          => $qPassed,
                        'total'           => $qTotal,
                        'ratio'           => round($qPassed / $qTotal, 4),
                        'datasets'        => is_array($run['datasets'] ?? null) ? $run['datasets'] : [],
                        'executionTimeMs' => $run['executionTimeMs'] ?? 0,
                        'query'           => $studentSql,
                        'table'           => $run['table'] ?? null,
                        'comparisons'     => $run['comparisons'] ?? [],
                    ];
                }

                $secStatus = $partPassed === $partTotal && $sols->isNotEmpty()
                    ? 'Accepted'
                    : ($partPassed > 0 ? 'Partial' : 'Wrong Answer');
                $sections[] = [
                    'part'      => 'SQL',
                    'status'    => $secStatus,
                    'message'   => "{$partPassed}/{$partTotal} câu đúng.",
                    'passed'    => $partPassed,
                    'total'     => $partTotal,
                    'questions' => $qResults,
                ];
            } elseif (in_array($part, ['DDL', 'DML', 'TRIGGER'], true)) {
                $questions = $this->workspaceService->resolveStageQuestionsForGrading($problem, $part, $schemaSql, $seedSql, $selection);

                $qResults = [];
                $partPassed = 0;
                $partTotal = max(1, count($questions));
                $fallbackKey = strtolower($part);
                $stageIds = array_map(fn ($q) => (string) ($q['id'] ?? 'q1'), $questions);
                $extractedStage = $this->extractQuestionScripts((string) ($draft[$fallbackKey] ?? ''), $stageIds);

                foreach ($questions as $q) {
                    $qid = (string) ($q['id'] ?? 'q1');
                    $studentScript = trim((string) ($partDraftQuestions[$qid] ?? $extractedStage[$qid] ?? ''));
                    $totalQuestions++;

                    if ($studentScript === '') {
                        $qResults[] = [
                            'id'          => $qid,
                            'name'        => $q['name'] ?? "{$part}.sql",
                            'description' => $q['description'] ?? '',
                            'status'      => 'Not Answered',
                            'message'     => 'No SQL submitted for this question.',
                            'passed'      => 0,
                            'total'       => 1,
                            'ratio'       => 0.0,
                            'datasets'    => [],
                            'query'       => '',
                        ];
                        continue;
                    }

                    $startFrom = $q['startFrom'] ?? $q['start_from'] ?? ($part === 'DDL' ? 'empty' : 'provided');
                    $setupDdl = $q['setupDdl'] ?? $q['setup_ddl'] ?? '';
                    $setupDml = $q['setupDml'] ?? $q['setup_dml'] ?? '';
                    $probes = is_array($q['probes'] ?? null) ? $q['probes'] : [];
                    $expectedAnswer = $q['answer'] ?? '';

                    $allTestCases = $problem->testCases->sortBy('order_index')->values();
                    if ($startFrom === 'empty' || $allTestCases->isEmpty()) {
                        $stageCases = [['schema' => $startFrom === 'empty' ? '' : $schemaSql, 'seed' => $startFrom === 'empty' ? '' : $seedSql]];
                    } else {
                        $stageCases = [];
                        $seenSchemas = [];
                        foreach ($allTestCases as $tcItem) {
                            $tcScript = $tcItem->scripts->first();
                            $cSchema = (string) ($tcScript?->create_script ?? '');
                            $cSeed = (string) ($tcScript?->insert_script ?? '');
                            if ($startFrom === 'structure') {
                                if (isset($seenSchemas[$cSchema])) {
                                    continue;
                                }
                                $seenSchemas[$cSchema] = true;
                            }
                            $stageCases[] = ['schema' => $cSchema, 'seed' => $cSeed];
                        }
                    }

                    $actualRun = null;
                    $matched = true;
                    $msg = 'Passed all checks.';

                    foreach ($stageCases as $caseIdx => $stageCase) {
                        $caseRun = $this->workspaceService->validateStage([
                            'part'        => $part,
                            'schema'      => $stageCase['schema'],
                            'seedData'    => $stageCase['seed'],
                            'prepareOnly' => false,
                            'schemaName'  => $schemaName,
                            'persist'     => $caseIdx === 0,
                            'question'    => [
                                'id'        => $qid,
                                'name'      => $q['name'] ?? "{$part}.sql",
                                'answer'    => $studentScript,
                                'startFrom' => $startFrom,
                                'setupDdl'  => $setupDdl,
                                'setupDml'  => $setupDml,
                                'probes'    => $probes,
                            ],
                        ]);
                        if ($caseIdx === 0) {
                            $actualRun = $caseRun;
                        }
                        if (($caseRun['status'] ?? '') === 'Error') {
                            $matched = false;
                            $msg = $caseRun['message'] ?? 'Runtime error.';
                            if ($caseIdx > 0) {
                                $actualRun = $caseRun;
                            }
                            break;
                        }
                        $expectedRun = $this->workspaceService->validateStage([
                            'part'        => $part,
                            'schema'      => $stageCase['schema'],
                            'seedData'    => $stageCase['seed'],
                            'prepareOnly' => false,
                            'question'    => [
                                'id'        => $qid,
                                'name'      => $q['name'] ?? "{$part}.sql",
                                'answer'    => $expectedAnswer,
                                'startFrom' => $startFrom,
                                'setupDdl'  => $setupDdl,
                                'setupDml'  => $setupDml,
                                'probes'    => $probes,
                            ],
                        ]);
                        if ($part === 'DDL') {
                            $caseMatched = $this->workspaceService->schemasMatch(
                                $caseRun['schemaTables'] ?? [],
                                $expectedRun['schemaTables'] ?? []
                            );
                        } elseif ($part === 'DML') {
                            $changes = $this->workspaceService->computeDataChanges($expectedRun['tables'] ?? [], $caseRun['tables'] ?? []);
                            $caseMatched = empty($changes);
                        } else {
                            $caseMatched = $this->workspaceService->triggerMatchesExpected($caseRun, $expectedRun, $probes);
                        }
                        if (!$caseMatched) {
                            $matched = false;
                            $msg = "{$part} result differs from expected state.";
                            break;
                        }
                    }

                    if ($matched) {
                        $partPassed++;
                        $passedQuestions++;
                    }

                    $qResults[] = [
                        'id'              => $qid,
                        'name'            => $q['name'] ?? "{$part}.sql",
                        'description'     => $q['description'] ?? '',
                        'status'          => $matched ? 'Accepted' : (($actualRun['status'] ?? '') === 'Error' ? 'Runtime Error' : 'Wrong Answer'),
                        'message'         => $msg,
                        'passed'          => $matched ? 1 : 0,
                        'total'           => 1,
                        'ratio'           => $matched ? 1.0 : 0.0,
                        'datasets'        => [],
                        'executionTimeMs' => $actualRun['executionTimeMs'] ?? 0,
                        'query'           => $studentScript,
                        'tables'          => $actualRun['tables'] ?? [],
                        'schemaTables'    => $actualRun['schemaTables'] ?? [],
                    ];
                }

                $secStatus = $partPassed === $partTotal && !empty($questions)
                    ? 'Accepted'
                    : ($partPassed > 0 ? 'Partial' : 'Wrong Answer');
                $sections[] = [
                    'part'      => $part,
                    'status'    => $secStatus,
                    'message'   => "{$partPassed}/{$partTotal} câu đúng.",
                    'passed'    => $partPassed,
                    'total'     => $partTotal,
                    'questions' => $qResults,
                ];
            }
        }

        $maxPoints = (float) ($member->points ?: 10.0);
        $overallStatus = ($totalQuestions > 0 && $passedQuestions === $totalQuestions)
            ? 'Accepted'
            : ($passedQuestions > 0 ? 'Partial' : 'Wrong Answer');
        $score = $totalQuestions > 0
            ? round(($passedQuestions / $totalQuestions) * $maxPoints, 2)
            : 0;

        $queryPayload = json_encode([
            'ddl'       => (string) ($draft['ddl'] ?? ''),
            'dml'       => (string) ($draft['dml'] ?? ''),
            'query'     => (string) ($draft['query'] ?? ''),
            'trigger'   => (string) ($draft['trigger'] ?? ''),
            'questions' => $questionsMap,
        ], JSON_UNESCAPED_UNICODE);

        $subId = (string) Str::uuid();
        $sub = Submission::create([
            'id'            => $subId,
            'user_id'       => $user->id,
            'problem_id'    => $problem->id,
            'source'        => $source,
            'context'       => $activity->id,
            'result'        => $overallStatus,
            'score'         => $score,
            'query_text'    => $queryPayload,
            'database_type' => (string) ($draft['database'] ?? 'SQL Server'),
            'schema_name'   => $schemaName,
            'submitted_at'  => now(),
        ]);

        WorkspaceSubmissionResult::updateOrCreate(
            ['submission_id' => $subId],
            ['sections' => $sections]
        );

        return $sub;
    }

    protected function attemptResponse(Assignment $activity, ?ActivityAttempt $attempt, User $user): array
    {
        $problemIds = AssignmentProblem::where('assignment_id', $activity->id)
            ->orderBy('order_index')
            ->pluck('problem_id')
            ->all();

        $countsRows = Submission::where('user_id', $user->id)
            ->where('context', $activity->id)
            ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->select('problem_id', DB::raw('COUNT(id) as cnt'))
            ->groupBy('problem_id')
            ->pluck('cnt', 'problem_id')
            ->all();

        $submissionCounts = [];
        foreach ($problemIds as $pid) {
            $submissionCounts[(string) $pid] = (int) ($countsRows[$pid] ?? 0);
        }

        $resolvedStatus = $attempt
            ? ($attempt->status === 'in_progress' ? 'active' : $attempt->status)
            : ($this->pastDeadline($activity) ? 'closed' : 'not_started');

        return [
            'activityId'            => (string) $activity->id,
            'examMode'              => (bool) $activity->exam_mode,
            'examViolationLimit'    => max(1, (int) ($activity->exam_violation_limit ?? 2)),
            'problemIds'            => array_values($problemIds),
            'closesAt'              => $activity->closes ? $activity->closes->toISOString() : null,
            'serverNow'             => now()->toISOString(),
            'showScoresAfterSubmit' => (bool) $activity->show_scores_after_submit,
            'maxSubmissions'        => $activity->max_submissions,
            'submissionCounts'      => (object) $submissionCounts,
            'status'                => $resolvedStatus,
            'violationCount'        => $this->violationCount($attempt),
            'departureCount'        => $this->departureCount($attempt),
            'drafts'                => $attempt && is_array($attempt->drafts) ? (object) $attempt->drafts : (object) [],
            'startedAt'             => $attempt && $attempt->started_at ? $attempt->started_at->toISOString() : null,
            'submittedAt'           => $attempt && $attempt->submitted_at ? $attempt->submitted_at->toISOString() : null,
            'finishReason'          => $attempt ? $attempt->finish_reason : null,
        ];
    }
}

