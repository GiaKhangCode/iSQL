<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use App\Services\Grading\QuestionScoring;
use App\Services\SqlServer\SandboxService;
use Illuminate\Support\Str;

/**
 * Chấm bản nháp của một bài trong lượt làm (ActivityAttempt) và lưu thành Submission + kết quả từng phần.
 *
 * Tách từ Student/ActivityAttemptController::gradeAndStoreMemberSubmission. Mỗi phần của bài
 * (SQL, DDL, DML, TRIGGER) cho ra một "section" gồm các câu hỏi; điểm tính theo trọng số từng câu
 * (xem QuestionScoring: không có `questionPoints` thì = số câu đúng / tổng số câu * điểm tối đa).
 */
class ActivityAttemptGradingService
{
    public function __construct(
        protected readonly ExaminationService $examinationService,
        protected readonly ProblemWorkspaceService $workspaceService,
        protected readonly SandboxService $sandbox
    ) {
    }

    /**
     * @param array<string, mixed> $draft bản nháp của bài (ddl, dml, query, trigger, database, questions)
     * @param array<int, string>   $parts các phần cần chấm
     */
    public function gradeAndStore(
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
        $schemaName = $this->sandbox->studentSchemaName($user->id, $activity->id, $problem->id);

        $tc = $problem->testCases->sortBy('order_index')->where('is_hidden', false)->first()
            ?? $problem->testCases->sortBy('order_index')->first();
        $script = $tc?->scripts->first();
        $schemaSql = $script?->create_script ?? '';
        $seedSql = $script?->insert_script ?? '';

        $sections = [];
        $totalQuestions = 0;
        $passedQuestions = 0;
        $outcomes = [];
        $bestQuestionResults = $this->bestQuestionSubmissions($user, $activity, $problem);

        foreach ($parts as $part) {
            $part = strtoupper((string) $part);
            $partDraftQuestions = is_array($questionsMap[$part] ?? null) ? $questionsMap[$part] : [];

            if ($part === 'SQL') {
                $graded = $this->gradeSqlPart($user, $activity, $problem, $source, $draft, $partDraftQuestions, $schemaName, $selection);
            } elseif (in_array($part, ['DDL', 'DML', 'TRIGGER'], true)) {
                $graded = $this->gradeStagePart($part, $problem, $draft, $partDraftQuestions, $schemaName, $schemaSql, $seedSql, $selection);
            } else {
                continue;
            }
            $graded = $this->applyBestQuestionSubmissions($graded, $part, $bestQuestionResults);

            $sections[] = $graded['section'];
            $totalQuestions += $graded['total'];
            $passedQuestions += $graded['passed'];
            foreach ($graded['section']['questions'] as $question) {
                $outcomes[] = ['part' => $part, 'id' => (string) $question['id'], 'passed' => ($question['status'] ?? '') === 'Accepted'];
            }
        }

        $maxPoints = (float) ($member->points ?: 10.0);
        $overallStatus = ($totalQuestions > 0 && $passedQuestions === $totalQuestions)
            ? 'Accepted'
            : ($passedQuestions > 0 ? 'Partial' : 'Wrong Answer');
        $score = QuestionScoring::score($outcomes, $selection, $maxPoints);

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

    /**
     * Lần nộp từng câu tốt nhất của sinh viên trong kỳ thi, theo "PHẦN:mã câu": điểm cao nhất, bằng điểm thì lần mới nhất.
     * Bài tập thường không dùng (nộp từng câu ở đó không đổi điểm chính thức). Mỗi câu chỉ được tính tối đa một lần.
     *
     * @return array<string, Submission>
     */
    protected function bestQuestionSubmissions(User $user, Assignment $activity, Problem $problem): array
    {
        if (!($activity->examination_id || $activity->exam_mode)) {
            return [];
        }

        $subs = Submission::where('user_id', $user->id)
            ->where('context', $activity->id)
            ->where('problem_id', $problem->id)
            ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
            ->whereHas('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->with('workspaceResult')
            ->orderBy('submitted_at')
            ->get();

        $best = [];
        foreach ($subs as $sub) {
            $meta = $sub->workspaceResult?->sections[0]['_questionSubmission'] ?? null;
            if (!is_array($meta) || empty($meta['part']) || !isset($meta['questionId'])) {
                continue;
            }
            $key = strtoupper((string) $meta['part']) . ':' . $meta['questionId'];
            // Duyệt từ cũ đến mới nên ">=" cho lần mới nhất khi bằng điểm.
            if (!isset($best[$key]) || (float) $sub->score >= (float) $best[$key]->score) {
                $best[$key] = $sub;
            }
        }

        return $best;
    }

    /**
     * Câu mà lần nộp từng câu tốt nhất đúng (Accepted) thì tính đúng dù bài lúc nộp toàn bài chưa đúng: kết quả mỗi câu là
     * tốt hơn trong hai (bài lúc nộp toàn bài, lần nộp từng câu tốt nhất). Cập nhật cả thống kê của phần.
     *
     * @param  array{section: array, total: int, passed: int}  $graded
     * @param  array<string, Submission>  $best
     * @return array{section: array, total: int, passed: int}
     */
    protected function applyBestQuestionSubmissions(array $graded, string $part, array $best): array
    {
        if ($best === []) {
            return $graded;
        }

        $changed = false;
        foreach ($graded['section']['questions'] as $i => $question) {
            $sub = $best[$part . ':' . ($question['id'] ?? '')] ?? null;
            if (!$sub || ($question['status'] ?? '') === 'Accepted' || $sub->result !== 'Accepted') {
                continue;
            }
            $graded['section']['questions'][$i] = array_merge($question, [
                'status'                 => 'Accepted',
                'message'                => 'Passed in a question submission during the exam.',
                'passed'                 => max(1, (int) ($question['total'] ?? 1)),
                'ratio'                  => 1.0,
                'query'                  => (string) $sub->query_text,
                'fromQuestionSubmission' => $sub->id,
            ]);
            $changed = true;
        }

        if ($changed) {
            $section = &$graded['section'];
            $passed = count(array_filter($section['questions'], fn ($q) => ($q['status'] ?? '') === 'Accepted'));
            $section['passed'] = $passed;
            $section['status'] = $passed === (int) $section['total'] && $section['questions'] !== []
                ? 'Accepted'
                : ($passed > 0 ? 'Partial' : 'Wrong Answer');
            $section['message'] = "{$passed}/{$section['total']} câu đúng.";
            $graded['passed'] = $passed;
            unset($section);
        }

        return $graded;
    }

    /**
     * @return array{section: array, total: int, passed: int}
     */
    protected function gradeSqlPart(
        User $user,
        Assignment $activity,
        Problem $problem,
        string $source,
        array $draft,
        array $partDraftQuestions,
        string $schemaName,
        ?array $selection
    ): array {
        $sols = $this->workspaceService->resolveSqlSolutionsForGrading($problem, $selection);

        $solIds = $sols->pluck('id')->all();
        $extractedSql = $this->extractQuestionScripts((string) ($draft['query'] ?? ''), $solIds);

        $qResults = [];
        $partPassed = 0;
        $partTotal = max(1, $sols->count());
        $questionCount = 0;

        foreach ($sols as $sol) {
            $studentSql = trim((string) ($partDraftQuestions[$sol->id] ?? $extractedSql[$sol->id] ?? ''));
            $questionCount++;
            if ($studentSql === '') {
                $qResults[] = [
                    'id'          => $sol->id,
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
                'query'       => $studentSql,
                'solutionId'  => $sol->id,
                'source'      => $source,
                'context'     => $activity->id,
                'schemaName'  => $schemaName,
                '_skipStore'  => true,
                // Cần kết quả thật để tính điểm, kể cả khi hoạt động ẩn điểm với sinh viên.
                '_forGrading' => true,
            ], $user, true);

            $qPassed = (int) ($run['passed'] ?? 0);
            $qTotal = max(1, (int) ($run['total'] ?? 1));
            $matched = ($run['status'] ?? '') === 'Accepted' && $qPassed === $qTotal && ($run['total'] ?? 0) > 0;
            if ($matched) {
                $partPassed++;
            }
            $qResults[] = [
                'id'              => $sol->id,
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

        return [
            'section' => [
                'part'      => 'SQL',
                'status'    => $secStatus,
                'message'   => "{$partPassed}/{$partTotal} câu đúng.",
                'passed'    => $partPassed,
                'total'     => $partTotal,
                'questions' => $qResults,
            ],
            'total'   => $questionCount,
            'passed'  => $partPassed,
        ];
    }

    /**
     * Chấm một phần DDL / DML / TRIGGER: chạy bài làm và đáp án trên cùng dữ liệu rồi so trạng thái schema/dữ liệu.
     *
     * @return array{section: array, total: int, passed: int}
     */
    protected function gradeStagePart(
        string $part,
        Problem $problem,
        array $draft,
        array $partDraftQuestions,
        string $schemaName,
        string $schemaSql,
        string $seedSql,
        ?array $selection
    ): array {
        $questions = $this->workspaceService->resolveStageQuestionsForGrading($problem, $part, $schemaSql, $seedSql, $selection);

        $qResults = [];
        $partPassed = 0;
        $partTotal = max(1, count($questions));
        $questionCount = 0;
        $fallbackKey = strtolower($part);
        $stageIds = array_map(fn ($q) => (string) ($q['id'] ?? 'q1'), $questions);
        $extractedStage = $this->extractQuestionScripts((string) ($draft[$fallbackKey] ?? ''), $stageIds);

        foreach ($questions as $q) {
            $qid = (string) ($q['id'] ?? 'q1');
            $studentScript = trim((string) ($partDraftQuestions[$qid] ?? $extractedStage[$qid] ?? ''));
            $questionCount++;

            if ($studentScript === '') {
                $qResults[] = [
                    'id'          => $qid,
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

            $stageCases = $this->stageCases($problem, $startFrom, $schemaSql, $seedSql);

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
                    'isolated'    => $caseIdx === 0, // chấm trong schema tạm, không đụng schema sinh viên đang làm bài
                    'question'    => [
                        'id'        => $qid,
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
                        $expectedRun['schemaTables'] ?? [],
                        !empty($q['checkColumnOrder'])
                    );
                } elseif ($part === 'DML') {
                    $caseMatched = $this->workspaceService->dataMatchesExpected($expectedRun['tables'] ?? [], $caseRun['tables'] ?? []);
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
            }

            $qResults[] = [
                'id'              => $qid,
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

        return [
            'section' => [
                'part'      => $part,
                'status'    => $secStatus,
                'message'   => "{$partPassed}/{$partTotal} câu đúng.",
                'passed'    => $partPassed,
                'total'     => $partTotal,
                'questions' => $qResults,
            ],
            'total'   => $questionCount,
            'passed'  => $partPassed,
        ];
    }

    /**
     * Các bộ dữ liệu (schema + seed) mà một câu DDL/DML/TRIGGER phải qua:
     * "empty" = bắt đầu từ CSDL trống; "structure" = mỗi cấu trúc khác nhau một lần; còn lại = mọi test case.
     *
     * @return array<int, array{schema: string, seed: string}>
     */
    protected function stageCases(Problem $problem, string $startFrom, string $schemaSql, string $seedSql): array
    {
        $allTestCases = $problem->testCases->sortBy('order_index')->values();
        if ($startFrom === 'empty' || $allTestCases->isEmpty()) {
            return [['schema' => $startFrom === 'empty' ? '' : $schemaSql, 'seed' => $startFrom === 'empty' ? '' : $seedSql]];
        }

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

        return $stageCases;
    }

    /**
     * Tách các đoạn "-- solution:<id>" do frontend ghi (writeQuestionDrafts) thành [id => mã].
     * Không có dấu nào thì cả đoạn thuộc về câu đầu tiên.
     *
     * @param array<int, string> $questionIds
     * @return array<string, string>
     */
    public function extractQuestionScripts(string $script, array $questionIds): array
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
}
