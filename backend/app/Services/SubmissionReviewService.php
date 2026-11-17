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
use Illuminate\Support\Collection;

class SubmissionReviewService
{
    public function __construct(
        protected ProblemWorkspaceService $workspaceService,
        protected ExaminationService $examinationService,
    ) {}

    public function reviewStatus(
        Submission $sub,
        ?SubmissionAppeal $appeal = null,
        ?SubmissionRegradeAssignment $regrade = null
    ): string {
        if ($appeal) {
            return $appeal->resolved_at ? 'Regraded' : 'Awaiting regrade';
        }
        if ($regrade) {
            return $regrade->completed_at ? 'Regraded' : 'Awaiting regrade';
        }
        return $sub->evaluated_score !== null ? 'Graded' : 'Needs review';
    }

    /**
     * Split a combined submission query_text (either "-- PART" markers or exam JSON payload) by part.
     */
    public function submittedParts(?string $text, string $fallback = 'SQL'): array
    {
        $raw = (string) ($text ?? '');
        $trimmed = ltrim($raw);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) && (
                array_key_exists('ddl', $decoded)
                || array_key_exists('dml', $decoded)
                || array_key_exists('query', $decoded)
                || array_key_exists('trigger', $decoded)
                || array_key_exists('questions', $decoded)
            )) {
                return $this->formatDraftPartScripts($decoded);
            }
        }

        if (!preg_match_all('/^-- (DDL|DML|SQL|TRIGGER)\r?\n/m', $raw, $matches, PREG_OFFSET_CAPTURE)) {
            return [$fallback => $raw];
        }

        $out = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $part = $matches[1][$i][0];
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($raw);
            $out[$part] = substr($raw, $start, $end - $start);
        }
        return $out;
    }

    /**
     * Convert an exam draft/submission JSON payload into per-part "-- solution:<id>" scripts.
     */
    protected function formatDraftPartScripts(array $draft): array
    {
        $keyByPart = [
            'DDL'     => 'ddl',
            'DML'     => 'dml',
            'SQL'     => 'query',
            'TRIGGER' => 'trigger',
        ];
        $questionsMap = is_array($draft['questions'] ?? null) ? $draft['questions'] : [];
        $scripts = [];

        foreach ($keyByPart as $part => $field) {
            $partQuestions = is_array($questionsMap[$part] ?? null) ? $questionsMap[$part] : [];
            $blocks = [];
            foreach ($partQuestions as $qid => $code) {
                $codeStr = (string) ($code ?? '');
                if (trim($codeStr) !== '') {
                    $blocks[] = "-- solution:{$qid}\n{$codeStr}";
                }
            }
            $scripts[$part] = !empty($blocks)
                ? implode("\n\n", $blocks)
                : (string) ($draft[$field] ?? '');
        }

        return $scripts;
    }

    /**
     * Build reviewSections matching SQL-UIT's TeacherSubmissionResponse.reviewSections.
     */
    public function buildReviewSections(
        Submission $sub,
        Problem $problem,
        ?AssignmentProblem $ap,
        array $savedSections,
        ?Assignment $activity = null,
        ?User $student = null,
        ?Collection $history = null
    ): array {
        $problem->loadMissing(['testCases.scripts', 'sqlSolutions', 'stageExercises']);

        $parts = ($ap && !empty($ap->workspace_parts))
            ? $ap->workspace_parts
            : ($problem->workspace_parts ?: ['SQL']);
        $fallbackPart = count($parts) === 1 ? strtoupper((string) $parts[0]) : 'SQL';
        $scripts = $this->submittedParts($sub->query_text, $fallbackPart);

        $selection = ($student && $activity && $ap)
            ? $this->examinationService->studentSelectionForProblem($student, $activity, $ap)
            : null;

        $sortedHistory = collect();
        $snapshots = [];
        if ($history && $history->isNotEmpty()) {
            $sortedHistory = $history
                ->filter(fn (Submission $row) => $row->id !== $sub->id && (!$sub->submitted_at || !$row->submitted_at || $row->submitted_at->lte($sub->submitted_at)))
                ->sortByDesc(fn (Submission $row) => ($row->submitted_at?->timestamp ?? 0) . '_' . $row->id)
                ->values();
            if ($sortedHistory->isNotEmpty()) {
                $snapshots = WorkspaceSubmissionResult::whereIn('submission_id', $sortedHistory->pluck('id')->all())
                    ->get()
                    ->keyBy('submission_id')
                    ->all();
            }
        }

        return $this->assembleSections(
            $problem,
            $parts,
            $scripts,
            $savedSections,
            $selection,
            $sortedHistory,
            $snapshots,
            $fallbackPart
        );
    }

    /**
     * Build reviewSections from a student's unsaved/saved draft (when not yet submitted).
     */
    public function buildDraftReviewSections(
        array $draft,
        Problem $problem,
        ?AssignmentProblem $ap,
        ?Assignment $activity = null,
        ?User $student = null
    ): array {
        $problem->loadMissing(['testCases.scripts', 'sqlSolutions', 'stageExercises']);

        $parts = ($ap && !empty($ap->workspace_parts))
            ? $ap->workspace_parts
            : ($problem->workspace_parts ?: ['SQL']);
        $scripts = $this->formatDraftPartScripts($draft);
        $selection = ($student && $activity && $ap)
            ? $this->examinationService->studentSelectionForProblem($student, $activity, $ap)
            : null;

        return $this->assembleSections($problem, $parts, $scripts, [], $selection, collect(), [], 'SQL');
    }

    protected function assembleSections(
        Problem $problem,
        array $parts,
        array $scripts,
        array $savedSections,
        ?array $selection,
        Collection $sortedHistory,
        array $snapshots,
        string $fallbackPart
    ): array {
        $savedByPart = [];
        foreach ($savedSections as $sec) {
            if (is_array($sec) && isset($sec['part'])) {
                $savedByPart[strtoupper((string) $sec['part'])] = $sec;
            }
        }

        $output = [];
        foreach (['DDL', 'DML', 'SQL', 'TRIGGER'] as $part) {
            $saved = $savedByPart[$part] ?? null;
            if (!array_key_exists($part, $scripts) && !$saved && !in_array($part, array_map('strtoupper', $parts), true)) {
                continue;
            }
            if (!array_key_exists($part, $scripts) && !$saved) {
                continue;
            }

            $questions = $this->loadPartQuestions($problem, $part, $selection);
            $qIds = array_map(fn ($q) => (string) $q['id'], $questions);
            $answers = $this->workspaceService->extractQuestionScripts((string) ($scripts[$part] ?? ''), $qIds);

            $known = array_flip($qIds);
            foreach ($answers as $aid => $aSql) {
                if (!isset($known[$aid]) && trim((string) $aSql) !== '') {
                    $questions[] = [
                        'id'          => $aid,
                        'name'        => "{$aid}.sql",
                        'description' => '',
                        'answer'      => '',
                    ];
                }
            }

            $savedQById = [];
            foreach (($saved['questions'] ?? []) as $sq) {
                if (is_array($sq) && isset($sq['id'])) {
                    $savedQById[(string) $sq['id']] = $sq;
                }
            }

            $rows = [];
            foreach ($questions as $q) {
                $qid = (string) $q['id'];
                $studentSql = (string) ($answers[$qid] ?? '');
                $result = $savedQById[$qid] ?? null;
                $sourceSubId = null;
                $sourceSubmittedAt = null;

                if (trim($studentSql) === '' && $sortedHistory->isNotEmpty()) {
                    foreach ($sortedHistory as $prev) {
                        $prevScripts = $this->submittedParts($prev->query_text, $fallbackPart);
                        $prevAnswers = $this->workspaceService->extractQuestionScripts((string) ($prevScripts[$part] ?? ''), $qIds);
                        $prevSql = (string) ($prevAnswers[$qid] ?? '');
                        if (trim($prevSql) === '') {
                            continue;
                        }
                        $studentSql = $prevSql;
                        $prevWs = $snapshots[$prev->id] ?? null;
                        if ($prevWs && is_array($prevWs->sections)) {
                            foreach ($prevWs->sections as $pSec) {
                                if (strtoupper((string) ($pSec['part'] ?? '')) === $part) {
                                    foreach (($pSec['questions'] ?? []) as $pq) {
                                        if (($pq['id'] ?? '') === $qid) {
                                            $result = $pq;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                        $sourceSubId = (string) $prev->id;
                        $sourceSubmittedAt = $prev->submitted_at ? $prev->submitted_at->toISOString() : null;
                        break;
                    }
                }

                if (!$result && count($questions) === 1 && $saved) {
                    $result = $saved;
                }

                $rows[] = [
                    'id'                => $qid,
                    'name'              => (string) ($q['name'] ?? "{$part}.sql"),
                    'description'       => (string) ($q['description'] ?? ''),
                    'startFrom'         => $q['startFrom'] ?? null,
                    'kind'              => $q['kind'] ?? null,
                    'studentSql'        => $studentSql,
                    'referenceSql'      => (string) ($q['answer'] ?? ''),
                    'sourceSubmissionId'=> $sourceSubId,
                    'sourceSubmittedAt' => $sourceSubmittedAt,
                    'setupSql'          => (string) ($q['setupSql'] ?? ''),
                    'setupDdl'          => (string) ($q['setupDdl'] ?? ''),
                    'setupDml'          => (string) ($q['setupDml'] ?? ''),
                    'probes'            => is_array($q['probes'] ?? null) ? $q['probes'] : [],
                    'timeLimitMs'       => (int) ($q['timeLimitMs'] ?? 5000),
                    'result'            => $result,
                ];
            }

            $output[] = [
                'part'      => $part,
                'status'    => $saved['status'] ?? null,
                'questions' => $rows,
            ];
        }

        return $output;
    }

    protected function loadPartQuestions(Problem $problem, string $part, ?array $selection): array
    {
        if ($part === 'SQL') {
            $sols = $problem->sqlSolutions->sortBy('order_index')
                ->filter(fn ($s) => $s->enabled && trim((string) $s->query) !== '')
                ->values();
            if (is_array($selection) && isset($selection['SQL']['questionIds'])) {
                $allowed = array_flip($selection['SQL']['questionIds']);
                $sols = $sols->filter(fn ($s) => isset($allowed[$s->id]))->values();
            }
            $list = [];
            foreach ($sols as $s) {
                $setupSql = is_array($s->expected) ? (string) ($s->expected['_setupSql'] ?? '') : '';
                $list[] = [
                    'id'          => (string) $s->id,
                    'name'        => (string) $s->name,
                    'description' => (string) ($s->description ?? ''),
                    'answer'      => (string) $s->query,
                    'timeLimitMs' => (int) ($s->time_limit_ms ?? 5000),
                    'setupSql'    => $setupSql,
                ];
            }
            if (!empty($list)) {
                return $list;
            }
            $tc = $problem->testCases->sortBy('order_index')->first();
            return [[
                'id'          => '__legacy_sql__',
                'name'        => 'sql.sql',
                'description' => (string) ($problem->description ?? ''),
                'answer'      => (string) ($tc?->expected_query ?? ''),
                'timeLimitMs' => 5000,
                'setupSql'    => '',
            ]];
        }

        $ex = $problem->stageExercises->firstWhere('part', $part);
        $questions = ($ex && is_array($ex->config)) ? ($ex->config['questions'] ?? []) : [];
        if (is_array($selection) && isset($selection[$part]['questionIds'])) {
            $allowed = array_flip($selection[$part]['questionIds']);
            $questions = array_values(array_filter($questions, fn ($q) => isset($allowed[$q['id'] ?? ''])));
        }

        $list = [];
        foreach ($questions as $q) {
            $list[] = [
                'id'          => (string) ($q['id'] ?? 'q1'),
                'name'        => (string) ($q['name'] ?? "{$part}.sql"),
                'description' => (string) ($q['description'] ?? ''),
                'answer'      => (string) ($q['answer'] ?? ''),
                'startFrom'   => $q['startFrom'] ?? $q['start_from'] ?? ($part === 'DDL' ? 'empty' : 'provided'),
                'kind'        => $q['kind'] ?? null,
                'setupDdl'    => (string) ($q['setupDdl'] ?? $q['setup_ddl'] ?? ''),
                'setupDml'    => (string) ($q['setupDml'] ?? $q['setup_dml'] ?? ''),
                'probes'      => is_array($q['probes'] ?? null) ? $q['probes'] : [],
                'timeLimitMs' => 5000,
            ];
        }

        if (!empty($list)) {
            return $list;
        }

        $tc = $problem->testCases->loadMissing('scripts')->sortBy('order_index')->first();
        $script = $tc?->scripts->first();
        return [[
            'id'          => '__legacy_' . strtolower($part) . '__',
            'name'        => strtolower($part) . '.sql',
            'description' => '',
            'answer'      => $part === 'DDL' ? (string) ($script?->create_script ?? '') : ($part === 'DML' ? (string) ($script?->insert_script ?? '') : ''),
            'startFrom'   => $part === 'DDL' ? 'empty' : 'structure',
            'timeLimitMs' => 5000,
        ]];
    }

    /**
     * Live rollback-only comparison of studentSql vs referenceSql for teacher inspection.
     */
    public function inspectQuestion(Problem $problem, ?AssignmentProblem $ap, string $part, array $question): array
    {
        $studentSql = trim((string) ($question['studentSql'] ?? ''));
        $referenceSql = trim((string) ($question['referenceSql'] ?? ''));

        if ($studentSql === '') {
            return [
                'status'   => 'Not answered',
                'message'  => 'No answer was submitted for this question.',
                'datasets' => [],
            ];
        }
        if ($referenceSql === '') {
            return [
                'status'   => 'Not available',
                'message'  => 'No reference solution provided.',
                'datasets' => [],
            ];
        }

        $problem->loadMissing(['testCases.scripts']);
        $testCases = $problem->testCases->sortBy('order_index')->values();
        if ($testCases->isEmpty()) {
            $cases = [['index' => 0, 'schema' => '', 'seed' => '', 'hidden' => false]];
        } else {
            $cases = $testCases->map(function ($tc, $idx) {
                $script = $tc->scripts->first();
                return [
                    'index'  => $idx,
                    'schema' => (string) ($script?->create_script ?? ''),
                    'seed'   => (string) ($script?->insert_script ?? ''),
                    'hidden' => (bool) $tc->is_hidden,
                ];
            })->all();
        }

        if ($part === 'SQL') {
            $datasets = [];
            $setupSql = (string) ($question['setupSql'] ?? '');
            foreach ($cases as $case) {
                $expected = $this->workspaceService->validateSolution([
                    'schema'            => $case['schema'],
                    'seedData'          => $case['seed'],
                    'setupSql'          => $setupSql,
                    'prepareOnly'       => false,
                    'referenceSolution' => $referenceSql,
                ]);
                $actual = $this->workspaceService->validateSolution([
                    'schema'            => $case['schema'],
                    'seedData'          => $case['seed'],
                    'setupSql'          => $setupSql,
                    'prepareOnly'       => false,
                    'referenceSolution' => $studentSql,
                ]);

                $row = ['dataset' => $case['index'] + 1];
                if (($expected['status'] ?? '') !== 'Success' || ($actual['status'] ?? '') !== 'Success') {
                    $row['matched'] = false;
                    $row['runtimeError'] = true;
                    $row['message'] = ($expected['status'] ?? '') !== 'Success'
                        ? ('Reference: ' . ($expected['message'] ?? 'Error'))
                        : (string) ($actual['message'] ?? 'Runtime error');
                } else {
                    [$matched, $message] = $this->workspaceService->compareResults($actual['table'] ?? [], $expected['table'] ?? []);
                    $row['matched'] = $matched;
                    $row['message'] = $message;
                    $row['actualTable'] = $actual['table'] ?? null;
                    $row['expectedTable'] = $expected['table'] ?? null;
                }
                $datasets[] = $row;
            }

            $allMatched = !empty($datasets) && collect($datasets)->every(fn ($d) => !empty($d['matched']));
            return [
                'status'   => $allMatched ? 'Accepted' : 'Wrong Answer',
                'message'  => 'Compared with the current reference; the saved score is unchanged.',
                'datasets' => $datasets,
            ];
        }

        $mode = (string) ($question['startFrom'] ?? ($part === 'DDL' ? 'empty' : 'provided'));
        if ($mode === 'empty') {
            $cases = [['index' => 0, 'schema' => '', 'seed' => '', 'hidden' => false]];
        } elseif ($mode === 'structure') {
            $seen = [];
            $unique = [];
            foreach ($cases as $c) {
                if (!isset($seen[$c['schema']])) {
                    $unique[] = $c;
                    $seen[$c['schema']] = true;
                }
            }
            $cases = $unique;
        }

        $setupDdl = (string) ($question['setupDdl'] ?? '');
        $setupDml = (string) ($question['setupDml'] ?? '');
        $probes = is_array($question['probes'] ?? null) ? $question['probes'] : [];

        $datasets = [];
        foreach ($cases as $case) {
            $runStage = function (string $sql) use ($part, $case, $mode, $setupDdl, $setupDml, $probes) {
                return $this->workspaceService->validateStage([
                    'part'        => $part,
                    'schema'      => $case['schema'],
                    'seedData'    => $mode === 'provided' ? $case['seed'] : '',
                    'prepareOnly' => false,
                    'question'    => [
                        'id'        => 'inspect',
                        'name'      => "{$part}.sql",
                        'answer'    => $sql,
                        'startFrom' => $mode,
                        'setupDdl'  => $setupDdl,
                        'setupDml'  => $setupDml,
                        'probes'    => $probes,
                    ],
                ]);
            };

            $expected = $runStage($referenceSql);
            $actual = $runStage($studentSql);
            $row = ['dataset' => $case['index'] + 1];

            if (($expected['status'] ?? '') !== 'Success' || ($actual['status'] ?? '') !== 'Success') {
                $row['matched'] = false;
                $row['runtimeError'] = true;
                $row['message'] = ($expected['status'] ?? '') !== 'Success'
                    ? ('Reference: ' . ($expected['message'] ?? 'Error'))
                    : (string) ($actual['message'] ?? 'Runtime error');
            } elseif ($part === 'TRIGGER') {
                $row['actualTriggers'] = $actual['triggers'] ?? [];
                $row['expectedTriggers'] = $expected['triggers'] ?? [];
                $expScenarios = $expected['scenarios'] ?? [];
                $actScenarios = $actual['scenarios'] ?? [];
                if (!empty($probes) && !empty($expScenarios)) {
                    $scenarios = [];
                    foreach ($expScenarios as $idx => $es) {
                        $as = $actScenarios[$idx] ?? [];
                        $ok = !empty($as['ok']) && !empty($es['ok']) && ($as['changedTables'] ?? []) == ($es['changedTables'] ?? []);
                        $scenarios[] = [
                            'scenario' => $idx + 1,
                            'matched'  => $ok,
                            'message'  => $ok ? 'Matches the reference result.' : ($as['message'] ?? 'The data after the trigger fired differs from the reference.'),
                        ];
                    }
                    $row['gradingMode'] = 'behavior';
                    $row['scenarios'] = $scenarios;
                    $row['matched'] = collect($scenarios)->every(fn ($s) => !empty($s['matched']));
                } else {
                    $row['gradingMode'] = 'exact';
                    $row['matched'] = ($actual['tables'] ?? []) == ($expected['tables'] ?? []);
                }
            } elseif ($part === 'DDL') {
                $actualSchema = $actual['schemaTables'] ?? [];
                $expectedSchema = $expected['schemaTables'] ?? [];
                $row['matched'] = $this->workspaceService->schemasMatch($actualSchema, $expectedSchema);

                $affected = array_unique(array_merge(
                    array_map(fn ($c) => strtolower((string) ($c['name'] ?? '')), $expected['schemaChanges'] ?? []),
                    array_map(fn ($c) => strtolower((string) ($c['name'] ?? '')), $actual['schemaChanges'] ?? []),
                ));
                if (!empty($affected)) {
                    $filter = fn (array $tables) => array_values(array_filter(
                        $tables,
                        fn ($t) => in_array(strtolower((string) ($t['name'] ?? '')), $affected, true)
                    ));
                    $actualSchema = $filter($actualSchema);
                    $expectedSchema = $filter($expectedSchema);
                }
                $row['actualSchema'] = $actualSchema;
                $row['expectedSchema'] = $expectedSchema;
            } else {
                $actualTables = $actual['tables'] ?? [];
                $expectedTables = $expected['tables'] ?? [];
                $changes = $this->workspaceService->computeDataChanges($expectedTables, $actualTables);
                $row['matched'] = empty($changes);

                $affected = array_unique(array_merge(
                    array_map(fn ($c) => strtolower((string) ($c['name'] ?? '')), $expected['dataChanges'] ?? []),
                    array_map(fn ($c) => strtolower((string) ($c['name'] ?? '')), $actual['dataChanges'] ?? []),
                ));
                if (!empty($affected)) {
                    $filter = fn (array $tables) => array_values(array_filter(
                        $tables,
                        fn ($t) => in_array(strtolower((string) ($t['name'] ?? '')), $affected, true)
                    ));
                    $actualTables = $filter($actualTables);
                    $expectedTables = $filter($expectedTables);
                }

                $changedNames = array_flip(array_map(fn ($c) => strtolower((string) ($c['name'] ?? '')), $changes));
                $sortChangedFirst = function (array $tables) use ($changedNames): array {
                    usort($tables, function ($a, $b) use ($changedNames) {
                        $aDiff = isset($changedNames[strtolower((string) ($a['name'] ?? ''))]) ? 0 : 1;
                        $bDiff = isset($changedNames[strtolower((string) ($b['name'] ?? ''))]) ? 0 : 1;
                        return $aDiff <=> $bDiff;
                    });
                    return $tables;
                };
                $row['actualTables'] = !empty($changedNames) ? $sortChangedFirst($actualTables) : $actualTables;
                $row['expectedTables'] = !empty($changedNames) ? $sortChangedFirst($expectedTables) : $expectedTables;
            }
            $datasets[] = $row;
        }

        $allMatched = !empty($datasets) && collect($datasets)->every(fn ($d) => !empty($d['matched']));
        return [
            'status'   => $allMatched ? 'Accepted' : 'Wrong Answer',
            'message'  => 'Compared with the current reference; the saved score is unchanged.',
            'datasets' => $datasets,
        ];
    }
}

