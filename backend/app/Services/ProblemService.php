<?php

namespace App\Services;

use App\Models\ContentShare;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\TestCase;
use App\Models\TestCaseScript;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProblemService
{
    public function __construct(
        protected readonly ProblemWorkspaceService $workspaceService
    ) {
    }

    /**
     * Get list of problems according to user role with statistics and progress.
     */
    public function getAllProblems(User $user): Collection
    {
        $isAdmin = $user->isAdmin();
        $isInstructor = !$isAdmin && (
            in_array($user->role, User::INSTRUCTOR_ROLES, true)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'view')
        );

        if ($isAdmin) {
            $problems = Problem::orderByDesc('created_at')->orderByDesc('id')->get();
        } elseif ($isInstructor) {
            $sharedProblemIds = ContentShare::where('kind', 'problem')
                ->where('recipient_id', $user->id)
                ->pluck('resource_id');

            $problems = Problem::where(function ($q) use ($user, $sharedProblemIds) {
                $q->where('creator_id', $user->id);
                if ($sharedProblemIds->isNotEmpty()) {
                    $q->orWhereIn('id', $sharedProblemIds);
                }
            })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
        } else {
            // Student
            $assignedProblemIds = DB::table('assignment_problems')
                ->join('assignments', 'assignments.id', '=', 'assignment_problems.assignment_id')
                ->where('assignments.published', true)
                ->where(function ($q) {
                    $q->whereNull('assignments.opens')->orWhere('assignments.opens', '<=', now());
                })
                ->where(function ($q) use ($user) {
                    $q->whereExists(function ($ex) use ($user) {
                            $ex->select(DB::raw(1))
                                ->from('assignment_classes')
                                ->join('class_enrollments', 'class_enrollments.class_id', '=', 'assignment_classes.class_id')
                                ->whereColumn('assignment_classes.assignment_id', 'assignments.id')
                                ->where('class_enrollments.student_id', $user->id);
                        });
                })
                ->pluck('assignment_problems.problem_id');

            $submittedProblemIds = DB::table('submissions')
                ->where('user_id', $user->id)
                ->pluck('problem_id');

            $problems = Problem::where('practice_listed', true)
                ->orWhereIn('id', $assignedProblemIds)
                ->orWhereIn('id', $submittedProblemIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
        }

        // 1. Calculate submissions progress for current user
        $userSubs = DB::table('submissions')
            ->where('user_id', $user->id)
            ->select('problem_id', 'result')
            ->get();

        $statusMap = [];
        foreach ($userSubs as $sub) {
            if (($statusMap[$sub->problem_id] ?? null) === 'Solved') {
                continue;
            }
            if ($sub->result === 'Accepted') {
                $statusMap[$sub->problem_id] = 'Solved';
            } else {
                $statusMap[$sub->problem_id] = 'In progress';
            }
        }

        // 2. Global submissions statistics
        $statsRows = DB::table('submissions')
            ->select(
                'problem_id',
                DB::raw('COUNT(id) as total_subs'),
                DB::raw('COUNT(DISTINCT user_id) as attempted'),
                DB::raw("COUNT(DISTINCT CASE WHEN result = 'Accepted' THEN user_id END) as solved_by"),
                DB::raw("SUM(CASE WHEN result = 'Accepted' THEN 1 ELSE 0 END) as accepted_subs")
            )
            ->groupBy('problem_id')
            ->get()
            ->keyBy('problem_id');

        return $problems->map(function (Problem $p) use ($statusMap, $statsRows) {
            $st = $statsRows->get($p->id);
            $total = $st ? (int) $st->total_subs : 0;
            $attempted = $st ? (int) $st->attempted : 0;
            $solvedBy = $st ? (int) $st->solved_by : 0;
            $acceptedSubs = $st ? (int) $st->accepted_subs : 0;
            $acceptance = $total > 0 ? (int) round(($acceptedSubs / $total) * 100) : 0;

            $topicsList = $p->topics ?: ($p->topic ? array_map('trim', explode(',', $p->topic)) : []);

            return [
                'id'              => $p->id,
                'number'          => $p->number,
                'title'           => $p->title,
                'difficulty'      => $p->difficulty ?: 'Easy',
                'topics'          => $topicsList,
                'topic'           => $p->topic,
                'practice_listed' => (bool) $p->practice_listed,
                'practiceListed'  => (bool) $p->practice_listed,
                'visibility'      => $p->practice_listed ? 'Public' : 'Private',
                'database_type'   => $p->database_type ?: 'SQL Server',
                'databaseType'    => $p->database_type ?: 'SQL Server',
                'workspace_parts' => $p->workspace_parts ?: ['SQL'],
                'workspaceParts'  => $p->workspace_parts ?: ['SQL'],
                'created_at'      => $p->created_at ? $p->created_at->toISOString() : null,
                'createdAt'       => $p->created_at ? $p->created_at->toISOString() : null,
                'progress'        => $statusMap[$p->id] ?? 'Not started',
                'submissions'     => $total,
                'attempted'       => $attempted,
                'solved_by'       => $solvedBy,
                'solvedBy'        => $solvedBy,
                'acceptance'      => $acceptance,
            ];
        });
    }

    /**
     * Get top trending problems by active learners within the given timeframe.
     */
    public function getTrendingProblems(string $range = 'Week'): array
    {
        $startDate = $range === 'Month' ? now()->subDays(30) : now()->subDays(7);

        $results = DB::table('submissions')
            ->join('problems', 'problems.id', '=', 'submissions.problem_id')
            ->where('problems.practice_listed', true)
            ->where('submissions.submitted_at', '>=', $startDate)
            ->select(
                'submissions.problem_id as id',
                'problems.number',
                'problems.title',
                DB::raw('COUNT(DISTINCT submissions.user_id) as learners')
            )
            ->groupBy('submissions.problem_id', 'problems.number', 'problems.title')
            ->orderByDesc(DB::raw('COUNT(DISTINCT submissions.user_id)'))
            ->limit(5)
            ->get();

        return $results->map(fn ($r) => [
            'id'       => $r->id,
            'number'   => $r->number,
            'title'    => $r->title,
            'learners' => (int) $r->learners,
        ])->all();
    }

    /**
     * Get problem detailed response for student, instructor, or admin.
     */
    public function getProblemById(string $id, User $user, ?string $context = null): array
    {
        $problem = Problem::with(['testCases.scripts', 'sqlSolutions', 'stageExercises'])->findOrFail($id);

        $this->assertProblemAccess($problem, $user, $context);

        $isAdmin = $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'view');

        // Submissions progress
        $subsQuery = DB::table('submissions')
            ->where('user_id', $user->id)
            ->where('problem_id', $problem->id);

        if ($context && $context !== 'Practice') {
            $subsQuery->where('context', $context);
        }

        $subs = $subsQuery->get();
        $progress = null;
        if ($subs->isNotEmpty()) {
            $progress = $subs->contains('result', 'Accepted') ? 'Solved' : 'In progress';
        }

        // Expected outputs
        [$expectedOutputs, $expectedOutputVisible] = $this->readExpectedOutputs($problem->expected);
        $expectedTable = !empty($expectedOutputs) ? $expectedOutputs[0] : null;

        // Effective workspace parts & activity overrides
        $workspaceParts = $problem->workspace_parts ?: ['SQL'];
        $activity = null;
        $activityProblem = null;
        $dbStructVisible = true;
        $dbDescVisible = (bool) $problem->database_description_visible;

        if ($context && $context !== 'Practice') {
            $activity = \App\Models\Assignment::find($context);
            $activityProblem = \App\Models\AssignmentProblem::where('assignment_id', $context)
                ->where('problem_id', $problem->id)
                ->first();
            if ($activityProblem) {
                if (is_array($activityProblem->workspace_parts)) {
                    $workspaceParts = $activityProblem->workspace_parts;
                }
                $dbStructVisible = (bool) ($activityProblem->database_structure_visible ?? true);
                $dbDescVisible = (bool) ($activityProblem->database_description_visible ?? true);
            }
        }

        // Test cases mapping
        $rawTestCases = $problem->testCases->sortBy('order_index')->values();
        $mappedTestCases = [];
        $schemaSql = '';
        $seedData = '';
        $referenceSolution = '';

        $datasetVis = ($activityProblem && is_array($activityProblem->dataset_visibility) && !empty($activityProblem->dataset_visibility))
            ? $activityProblem->dataset_visibility
            : [];

        foreach ($rawTestCases as $idx => $tc) {
            $script = $tc->scripts->first();
            $dsKey = (string) ($idx + 1);
            $isHidden = (bool) $tc->is_hidden;
            if (!$isTeacher && !$isAdmin && array_key_exists($dsKey, $datasetVis)) {
                $isHidden = !$datasetVis[$dsKey];
            }
            $mappedTestCases[] = [
                'schema'   => $script?->create_script ?? '',
                'seedData' => $script?->insert_script ?? '',
                'isHidden' => $isHidden,
            ];
        }

        if (!empty($mappedTestCases)) {
            $schemaSql = $mappedTestCases[0]['schema'];
            $seedData = $mappedTestCases[0]['seedData'];
            $referenceSolution = $isTeacher || $isAdmin
                ? ($rawTestCases->first()?->expected_query ?? '')
                : '';
        }

        // Stage Exercises (DDL, DML, TRIGGER)
        $stageMap = $problem->stageExercises->keyBy(fn ($item) => strtoupper($item->part));
        $ddlExercise = $this->normalizeStageExercise('DDL', $stageMap->get('DDL')?->config);
        $dmlExercise = $this->normalizeStageExercise('DML', $stageMap->get('DML')?->config);
        $triggerExercise = $this->normalizeStageExercise('TRIGGER', $stageMap->get('TRIGGER')?->config);

        $ddlChecks = $this->buildStageChecks('DDL', $ddlExercise);
        $dmlChecks = $this->buildStageChecks('DML', $dmlExercise);
        $triggerChecks = $this->buildStageChecks('TRIGGER', $triggerExercise);

        // SQL Solutions & Checks
        $solutions = $problem->sqlSolutions->sortBy('order_index')->values();
        $sqlChecks = [];
        foreach ($solutions as $sol) {
            if ($sol->enabled && trim($sol->query) !== '') {
                [$solOutputs, $solVisible] = $this->readExpectedOutputs($sol->expected);
                $setupSql = is_array($sol->expected) ? (string) ($sol->expected['_setupSql'] ?? '') : '';
                $samples = [];
                if ($solVisible && !empty($solOutputs)) {
                    foreach ($mappedTestCases as $idx => $tc) {
                        if (!$tc['isHidden'] && isset($solOutputs[$idx])) {
                            $samples[] = [
                                'dataset' => $idx + 1,
                                'table'   => $solOutputs[$idx],
                            ];
                        }
                    }
                }
                $sqlChecks[] = [
                    'id'              => $sol->id,
                    'name'            => $sol->name,
                    'description'     => $sol->description ?? '',
                    'timeLimitMs'     => $sol->time_limit_ms,
                    'hasSetup'        => trim($setupSql) !== '',
                    'expectedSamples' => $samples,
                ];
            }
        }

        // Filter checks by student selection when in an assignment/examination session
        $selection = ($activity && $activityProblem && !$isTeacher && !$isAdmin)
            ? app(ExaminationService::class)->studentSelectionForProblem($user, $activity, $activityProblem)
            : null;

        if (trim($schemaSql) !== '' && (empty($ddlChecks) || (is_array($selection) && in_array('__base_ddl__', $selection['DDL']['questionIds'] ?? [], true)))) {
            array_unshift($ddlChecks, [
                'id'          => '__base_ddl__',
                'name'        => 'create_tables.sql',
                'description' => 'Create all tables and declare their primary and foreign keys.',
                'startFrom'   => 'empty',
                'hasSetup'    => false,
                'gradingMode' => null,
            ]);
        }
        if (trim($schemaSql) !== '' && trim($seedData) !== '' && (empty($dmlChecks) || (is_array($selection) && in_array('__base_dml__', $selection['DML']['questionIds'] ?? [], true)))) {
            array_unshift($dmlChecks, [
                'id'          => '__base_dml__',
                'name'        => 'insert_data.sql',
                'description' => 'Insert the original sample data into all tables.',
                'startFrom'   => 'structure',
                'hasSetup'    => false,
                'gradingMode' => null,
            ]);
        }
        if (empty($sqlChecks)) {
            $legacyQuery = $rawTestCases->first()?->expected_query ?? '';
            if (trim((string) $legacyQuery) !== '') {
                $sqlChecks[] = [
                    'id'              => '__base_sql__',
                    'name'            => 'query.sql',
                    'description'     => '',
                    'timeLimitMs'     => 5000,
                    'hasSetup'        => false,
                    'expectedSamples' => [],
                ];
            }
        }

        if ($activity && $activityProblem && !$isTeacher && !$isAdmin) {
            if (is_array($selection)) {
                if (isset($selection['DDL']['questionIds'])) {
                    $allowed = array_flip($selection['DDL']['questionIds']);
                    $ddlChecks = array_values(array_filter($ddlChecks, fn ($c) => isset($allowed[$c['id']])));
                }
                if (isset($selection['DML']['questionIds'])) {
                    $allowed = array_flip($selection['DML']['questionIds']);
                    $dmlChecks = array_values(array_filter($dmlChecks, fn ($c) => isset($allowed[$c['id']])));
                }
                if (isset($selection['TRIGGER']['questionIds'])) {
                    $allowed = array_flip($selection['TRIGGER']['questionIds']);
                    $triggerChecks = array_values(array_filter($triggerChecks, fn ($c) => isset($allowed[$c['id']])));
                }
                if (isset($selection['SQL']['questionIds'])) {
                    $allowed = array_flip($selection['SQL']['questionIds']);
                    $sqlChecks = array_values(array_filter($sqlChecks, fn ($c) => isset($allowed[$c['id']])));
                }
            }
        }

        if (!$isTeacher && !$isAdmin) {
            if (!in_array('DDL', $workspaceParts, true)) $ddlChecks = [];
            if (!in_array('DML', $workspaceParts, true)) $dmlChecks = [];
            if (!in_array('TRIGGER', $workspaceParts, true)) $triggerChecks = [];
            if (!in_array('SQL', $workspaceParts, true)) $sqlChecks = [];
        }

        $sqlSolutionsResponse = null;
        if ($isTeacher || $isAdmin) {
            $sqlSolutionsResponse = $solutions->map(function ($sol) {
                [$solOutputs, $solVisible] = $this->readExpectedOutputs($sol->expected);
                $rawExpected = is_array($sol->expected) ? $sol->expected : [];
                return [
                    'id'                    => $sol->id,
                    'name'                  => $sol->name,
                    'query'                 => $sol->query,
                    'description'           => $sol->description ?? '',
                    'setupSql'              => (string) ($rawExpected['_setupSql'] ?? ''),
                    'difficulty'            => $rawExpected['_difficulty'] ?? null,
                    'category'              => $rawExpected['_category'] ?? null,
                    'expectedOutputs'       => $solOutputs,
                    'expectedOutputVisible' => $solVisible,
                    'enabled'               => (bool) $sol->enabled,
                    'timeLimitMs'           => $sol->time_limit_ms,
                ];
            })->all();
        }

        // Question catalog (for Assignment / Exam Builder)
        $questionCatalog = $isTeacher || $isAdmin
            ? $this->buildQuestionCatalog($problem, $workspaceParts, $schemaSql, $seedData, $ddlExercise, $dmlExercise, $triggerExercise, $solutions)
            : null;

        // Student sanitization
        $testCasesResponse = $mappedTestCases;
        $expectedOutputsResponse = $expectedOutputs;
        $expectedSamplesResponse = [];

        if (!$isTeacher && !$isAdmin) {
            $testCasesResponse = array_values(array_filter($mappedTestCases, fn ($tc) => !$tc['isHidden']));

            if (!$expectedOutputVisible) {
                $expectedOutputsResponse = [];
                $expectedTable = null;
            } else {
                foreach ($mappedTestCases as $idx => $tc) {
                    if (!$tc['isHidden'] && isset($expectedOutputs[$idx])) {
                        $expectedSamplesResponse[] = [
                            'dataset' => $idx + 1,
                            'table'   => $expectedOutputs[$idx],
                        ];
                    }
                }
            }

            if (in_array('DML', $workspaceParts, true)) {
                $seedData = '';
                foreach ($testCasesResponse as &$tc) {
                    $tc['seedData'] = '';
                }
                unset($tc);
            }
        } else {
            if ($expectedOutputVisible) {
                foreach ($mappedTestCases as $idx => $tc) {
                    if (!$tc['isHidden'] && isset($expectedOutputs[$idx])) {
                        $expectedSamplesResponse[] = [
                            'dataset' => $idx + 1,
                            'table'   => $expectedOutputs[$idx],
                        ];
                    }
                }
            }
        }

        $topicsList = $problem->topics ?: ($problem->topic ? array_map('trim', explode(',', $problem->topic)) : []);

        return [
            'id'                           => $problem->id,
            'number'                       => $problem->number,
            'title'                        => $problem->title,
            'difficulty'                   => $problem->difficulty ?: 'Easy',
            'topics'                       => $topicsList,
            'topic'                        => $problem->topic,
            'practice_listed'              => (bool) $problem->practice_listed,
            'practiceListed'               => (bool) $problem->practice_listed,
            'visibility'                   => $problem->practice_listed ? 'Public' : 'Private',
            'database_type'                => $problem->database_type ?: 'SQL Server',
            'databaseType'                 => $problem->database_type ?: 'SQL Server',
            'schema_file_name'             => $problem->schema_file_name ?: 'database.sql',
            'schemaFileName'               => $problem->schema_file_name ?: 'database.sql',
            'workspace_parts'              => $workspaceParts,
            'workspaceParts'               => $workspaceParts,
            'database_description'         => (!$isTeacher && !$isAdmin && !$dbDescVisible) ? [] : ($problem->database_description ?: []),
            'databaseDescription'          => (!$isTeacher && !$isAdmin && !$dbDescVisible) ? [] : ($problem->database_description ?: []),
            'database_description_visible' => $dbDescVisible,
            'databaseDescriptionVisible'   => $dbDescVisible,
            'database_structure_visible'   => $dbStructVisible,
            'databaseStructureVisible'     => $dbStructVisible,
            'description'                  => $problem->description ?? '',
            'statement'                    => $problem->description ?? '',
            'requirements'                 => $problem->requirements ?? '',
            'hint'                         => $problem->hint,
            'hints'                        => $problem->hint ? explode("\n", $problem->hint) : [],
            'schema'                       => $schemaSql,
            'schema_sql'                   => $schemaSql,
            'seedData'                     => $seedData,
            'seed_data'                    => $seedData,
            'referenceSolution'            => $referenceSolution,
            'reference_solution'           => $referenceSolution,
            'expected'                     => $expectedTable,
            'expected_outputs'             => $expectedOutputsResponse,
            'expectedOutputs'              => $expectedOutputsResponse,
            'expected_samples'             => $expectedSamplesResponse,
            'expectedSamples'              => $expectedSamplesResponse,
            'expected_output_visible'      => $expectedOutputVisible,
            'expectedOutputVisible'       => $expectedOutputVisible,
            'testCases'                    => $testCasesResponse,
            'test_cases'                   => $testCasesResponse,
            'ddlExercise'                  => ($isTeacher || $isAdmin) ? $ddlExercise : null,
            'dmlExercise'                  => ($isTeacher || $isAdmin) ? $dmlExercise : null,
            'triggerExercise'              => ($isTeacher || $isAdmin) ? $triggerExercise : null,
            'ddlChecks'                    => $ddlChecks,
            'dmlChecks'                    => $dmlChecks,
            'triggerChecks'                => $triggerChecks,
            'sql_solutions'                => $sqlSolutionsResponse,
            'sqlSolutions'                 => $sqlSolutionsResponse,
            'sql_checks'                   => $sqlChecks,
            'sqlChecks'                    => $sqlChecks,
            'questionCatalog'              => $questionCatalog,
            'progress'                     => $progress,
            'canEdit'                      => $user ? $this->canEditProblem($problem, $user) : false,
            'canDelete'                    => $user ? $this->canDeleteProblem($problem, $user) : false,
            'created_at'                   => $problem->created_at ? $problem->created_at->toISOString() : null,
            'createdAt'                    => $problem->created_at ? $problem->created_at->toISOString() : null,
        ];
    }

    /**
     * Create a new problem with topics, test cases, stage exercises, and SQL solutions.
     */
    public function createProblem(array $data, User $user): array
    {
        $this->assertCanManageProblems($user, 'add');

        return DB::transaction(function () use ($data, $user) {
            $problemId = (string) Str::uuid();

            // Extract and normalize topics
            $rawTopics = $data['topics'] ?? '';
            $topicsList = is_array($rawTopics)
                ? array_values(array_filter(array_map('trim', $rawTopics), fn ($t) => $t !== ''))
                : (is_string($rawTopics) && trim($rawTopics) !== '' ? array_values(array_filter(array_map('trim', explode(',', $rawTopics)), fn ($t) => $t !== '')) : []);
            $legacyTopic = implode(', ', $topicsList);
            $legacyTopicCapped = $legacyTopic !== '' ? mb_substr($legacyTopic, 0, 100) : null;

            // Visibility (default to Public / true if omitted, matching SQL-UIT)
            if (array_key_exists('visibility', $data)) {
                $isPublic = $data['visibility'] === 'Public';
            } elseif (array_key_exists('practice_listed', $data)) {
                $isPublic = (bool) $data['practice_listed'];
            } elseif (array_key_exists('practiceListed', $data)) {
                $isPublic = (bool) $data['practiceListed'];
            } else {
                $isPublic = true;
            }

            // Hints
            $hint = null;
            if (isset($data['hints']) && is_array($data['hints'])) {
                $hint = implode("\n", $data['hints']);
            } elseif (isset($data['hint'])) {
                $hint = $data['hint'];
            }

            // Expected outputs
            $expectedOutputs = $data['expectedOutputs'] ?? $data['expected_outputs'] ?? null;
            $expectedOutputVisible = $data['expectedOutputVisible'] ?? $data['expected_output_visible'] ?? true;
            $savedExpected = $this->saveExpectedOutputs($expectedOutputs, $expectedOutputVisible);

            $schemaFileName = $this->normalizeSchemaFileName($data['schemaFileName'] ?? $data['schema_file_name'] ?? 'database.sql');

            Problem::create([
                'id'                           => $problemId,
                'number'                       => $data['number'],
                'title'                        => $data['title'],
                'topic'                        => $legacyTopicCapped,
                'topics'                       => $topicsList,
                'difficulty'                   => $data['difficulty'] ?? 'Easy',
                'practice_listed'              => $isPublic,
                'description'                  => $data['statement'] ?? $data['description'] ?? '',
                'requirements'                 => $data['requirements'] ?? '',
                'hint'                         => $hint,
                'database_type'                => $data['database'] ?? $data['database_type'] ?? 'SQL Server',
                'schema_file_name'             => $schemaFileName,
                'database_description'         => $data['databaseDescription'] ?? $data['database_description'] ?? [],
                'database_description_visible' => $data['databaseDescriptionVisible'] ?? $data['database_description_visible'] ?? true,
                'workspace_parts'              => $data['workspaceParts'] ?? $data['workspace_parts'] ?? ['SQL'],
                'expected'                     => $savedExpected,
                'creator_id'                   => $user->id,
            ]);

            // Sync topics
            $this->syncTopics($problemId, $topicsList);

            // Create test cases
            $schemaSql = $data['schema'] ?? $data['schema_sql'] ?? '';
            $seedData = $data['seedData'] ?? $data['seed_data'] ?? '';
            $referenceSolution = $data['referenceSolution'] ?? $data['reference_solution'] ?? '';
            $testCases = $data['testCases'] ?? $data['test_cases'] ?? null;

            $this->saveTestCases($problemId, $testCases, $schemaSql, $seedData, $referenceSolution);

            // Save stage exercises (DDL, DML, TRIGGER)
            if (array_key_exists('ddlExercise', $data) || array_key_exists('ddl_exercise', $data)) {
                $this->saveStageExercise($problemId, 'DDL', $data['ddlExercise'] ?? $data['ddl_exercise']);
            }
            if (array_key_exists('dmlExercise', $data) || array_key_exists('dml_exercise', $data)) {
                $this->saveStageExercise($problemId, 'DML', $data['dmlExercise'] ?? $data['dml_exercise']);
            }
            if (array_key_exists('triggerExercise', $data) || array_key_exists('trigger_exercise', $data)) {
                $this->saveStageExercise($problemId, 'TRIGGER', $data['triggerExercise'] ?? $data['trigger_exercise']);
            }

            // Save SQL solutions
            $sqlSolutions = $data['sqlSolutions'] ?? $data['sql_solutions'] ?? null;
            if ($sqlSolutions !== null) {
                $this->saveSqlSolutions($problemId, $sqlSolutions);
            }

            return $this->getProblemById($problemId, $user);
        });
    }

    /**
     * Update an existing problem with full relation synchronisation.
     */
    public function updateProblem(string $id, array $data, User $user): array
    {
        $problem = Problem::findOrFail($id);
        $this->assertCanEditProblem($problem, $user);

        return DB::transaction(function () use ($problem, $data, $user) {
            $rawTopics = $data['topics'] ?? $problem->topics;
            $topicsList = is_array($rawTopics)
                ? array_values(array_filter(array_map('trim', $rawTopics), fn ($t) => $t !== ''))
                : (is_string($rawTopics) && trim($rawTopics) !== '' ? array_values(array_filter(array_map('trim', explode(',', $rawTopics)), fn ($t) => $t !== '')) : []);
            $legacyTopic = implode(', ', $topicsList);
            $legacyTopicCapped = $legacyTopic !== '' ? mb_substr($legacyTopic, 0, 100) : null;

            if (array_key_exists('visibility', $data) && $data['visibility'] !== null) {
                $isPublic = $data['visibility'] === 'Public';
            } elseif (array_key_exists('practice_listed', $data) && $data['practice_listed'] !== null) {
                $isPublic = (bool) $data['practice_listed'];
            } elseif (array_key_exists('practiceListed', $data) && $data['practiceListed'] !== null) {
                $isPublic = (bool) $data['practiceListed'];
            } else {
                $isPublic = (bool) $problem->practice_listed;
            }

            $hint = $problem->hint;
            if (isset($data['hints']) && is_array($data['hints'])) {
                $hint = implode("\n", $data['hints']);
            } elseif (isset($data['hint'])) {
                $hint = $data['hint'];
            }

            $schemaFileName = isset($data['schemaFileName']) || isset($data['schema_file_name'])
                ? $this->normalizeSchemaFileName($data['schemaFileName'] ?? $data['schema_file_name'])
                : ($problem->schema_file_name ?: 'database.sql');

            $updatePayload = [
                'number'                       => $data['number'] ?? $problem->number,
                'title'                        => $data['title'] ?? $problem->title,
                'topic'                        => $legacyTopicCapped,
                'topics'                       => $topicsList,
                'difficulty'                   => $data['difficulty'] ?? $problem->difficulty ?? 'Easy',
                'practice_listed'              => $isPublic,
                'description'                  => $data['statement'] ?? $data['description'] ?? $problem->description ?? '',
                'requirements'                 => $data['requirements'] ?? $problem->requirements ?? '',
                'hint'                         => $hint,
                'database_type'                => $data['database'] ?? $data['database_type'] ?? $problem->database_type,
                'schema_file_name'             => $schemaFileName,
                'database_description'         => $data['databaseDescription'] ?? $data['database_description'] ?? $problem->database_description,
                'database_description_visible' => $data['databaseDescriptionVisible'] ?? $data['database_description_visible'] ?? $problem->database_description_visible,
                'workspace_parts'              => $data['workspaceParts'] ?? $data['workspace_parts'] ?? $problem->workspace_parts,
            ];

            $hasExpectedOutputs = array_key_exists('expectedOutputs', $data) || array_key_exists('expected_outputs', $data);
            $hasExpectedVisible = array_key_exists('expectedOutputVisible', $data) || array_key_exists('expected_output_visible', $data);
            if ($hasExpectedOutputs || $hasExpectedVisible) {
                [$existingOutputs, $existingVisible] = $this->readExpectedOutputs($problem->expected);
                $expectedOutputs = $hasExpectedOutputs
                    ? ($data['expectedOutputs'] ?? $data['expected_outputs'] ?? [])
                    : $existingOutputs;
                $expectedOutputVisible = $hasExpectedVisible
                    ? (bool) ($data['expectedOutputVisible'] ?? $data['expected_output_visible'])
                    : $existingVisible;
                $updatePayload['expected'] = $this->saveExpectedOutputs($expectedOutputs, $expectedOutputVisible)
                    ?? ['_expectedOutputs' => [], '_expectedOutputVisible' => $expectedOutputVisible];
            }

            $problem->update($updatePayload);

            // Re-sync topics
            $this->syncTopics($problem->id, $topicsList);

            // Re-create test cases if provided
            if (
                array_key_exists('testCases', $data) || array_key_exists('test_cases', $data)
                || array_key_exists('schema', $data) || array_key_exists('schema_sql', $data)
                || array_key_exists('seedData', $data) || array_key_exists('seed_data', $data)
            ) {
                $schemaSql = $data['schema'] ?? $data['schema_sql'] ?? '';
                $seedData = $data['seedData'] ?? $data['seed_data'] ?? '';
                $referenceSolution = $data['referenceSolution'] ?? $data['reference_solution'] ?? '';
                $testCases = $data['testCases'] ?? $data['test_cases'] ?? null;

                $this->saveTestCases($problem->id, $testCases, $schemaSql, $seedData, $referenceSolution);
            }

            // Re-save stage exercises if provided
            if (array_key_exists('ddlExercise', $data) || array_key_exists('ddl_exercise', $data)) {
                $this->saveStageExercise($problem->id, 'DDL', $data['ddlExercise'] ?? $data['ddl_exercise']);
            }
            if (array_key_exists('dmlExercise', $data) || array_key_exists('dml_exercise', $data)) {
                $this->saveStageExercise($problem->id, 'DML', $data['dmlExercise'] ?? $data['dml_exercise']);
            }
            if (array_key_exists('triggerExercise', $data) || array_key_exists('trigger_exercise', $data)) {
                $this->saveStageExercise($problem->id, 'TRIGGER', $data['triggerExercise'] ?? $data['trigger_exercise']);
            }

            // Re-save SQL solutions if provided
            if (array_key_exists('sqlSolutions', $data) || array_key_exists('sql_solutions', $data)) {
                $sqlSolutions = $data['sqlSolutions'] ?? $data['sql_solutions'] ?? [];
                $this->saveSqlSolutions($problem->id, $sqlSolutions);
            }

            return $this->getProblemById($problem->id, $user);
        });
    }

    /**
     * Delete a problem and its cascading dependencies.
     */
    public function deleteProblem(string $id, User $user): void
    {
        $problem = Problem::findOrFail($id);
        $this->assertCanDeleteProblem($problem, $user);

        $isUsedInPublishedOrExam = DB::table('assignment_problems')
            ->join('assignments', 'assignments.id', '=', 'assignment_problems.assignment_id')
            ->where('assignment_problems.problem_id', $id)
            ->where(function ($q) {
                $q->where('assignments.published', true)
                    ->orWhereNotNull('assignments.examination_id');
            })
            ->exists();

        if ($isUsedInPublishedOrExam) {
            abort(409, 'Không thể xóa bài toán đang được sử dụng trong bài tập đã công bố hoặc kỳ thi.');
        }

        DB::transaction(function () use ($problem) {
            $id = $problem->id;

            DB::table('assignment_problems')->where('problem_id', $id)->delete();

            $subIds = DB::table('submissions')->where('problem_id', $id)->pluck('id');
            if ($subIds->isNotEmpty()) {
                DB::table('workspace_submission_results')->whereIn('submission_id', $subIds)->delete();
                DB::table('submission_appeals')->whereIn('submission_id', $subIds)->delete();
                DB::table('submission_regrade_assignments')->whereIn('submission_id', $subIds)->delete();
            }
            DB::table('submissions')->where('problem_id', $id)->delete();
            DB::table('problem_drafts')->where('problem_id', $id)->delete();
            DB::table('favorites')->where('problem_id', $id)->delete();
            DB::table('problem_list_items')->where('problem_id', $id)->delete();
            DB::table('content_shares')->where('kind', 'problem')->where('resource_id', $id)->delete();

            // AI chat messages & sessions
            $sessionIds = DB::table('ai_chat_sessions')->where('problem_id', $id)->pluck('id');
            if ($sessionIds->isNotEmpty()) {
                DB::table('ai_chat_messages')->whereIn('session_id', $sessionIds)->delete();
                DB::table('ai_chat_sessions')->where('problem_id', $id)->delete();
            }

            DB::table('problem_topics')->where('problem_id', $id)->delete();

            // Test cases and scripts
            $tcIds = DB::table('test_cases_rel')->where('problem_id', $id)->pluck('id');
            if ($tcIds->isNotEmpty()) {
                DB::table('test_case_scripts')->whereIn('test_case_id', $tcIds)->delete();
                DB::table('test_cases_rel')->where('problem_id', $id)->delete();
            }

            DB::table('problem_sql_solutions')->where('problem_id', $id)->delete();
            DB::table('problem_stage_exercises')->where('problem_id', $id)->delete();

            $problem->delete();
        });
    }

    /**
     * Validate DQL reference solution or prepare starting database for preview.
     */
    public function validateSolution(array $data, User $user): array
    {
        $this->assertCanManageProblems($user, 'edit');
        return $this->workspaceService->validateSolution($data);
    }

    /**
     * Validate DDL, DML, or TRIGGER stage question.
     */
    public function validateStage(array $data, User $user): array
    {
        $this->assertCanManageProblems($user, 'edit');
        return $this->workspaceService->validateStage($data);
    }

    /**
     * Run a student DQL query against visible test case.
     */
    public function runQuery(string $id, array $data, User $user): array
    {
        $problem = Problem::findOrFail($id);
        $this->assertProblemAccess($problem, $user, $data['context'] ?? null);
        return $this->workspaceService->runOrSubmitDql($problem, $data, $user, false);
    }

    /**
     * Submit a student DQL query against all test cases.
     */
    public function submitQuery(string $id, array $data, User $user): array
    {
        $problem = Problem::findOrFail($id);
        $this->assertProblemAccess($problem, $user, $data['context'] ?? null);
        return $this->workspaceService->runOrSubmitDql($problem, $data, $user, true);
    }

    /**
     * Run a workspace section (DDL / DML / SQL / TRIGGER).
     */
    public function runWorkspace(string $id, array $data, User $user): array
    {
        $problem = Problem::findOrFail($id);
        $this->assertProblemAccess($problem, $user, $data['context'] ?? null);
        return $this->workspaceService->runProblemWorkspace($problem, $data, $user, false);
    }

    /**
     * Prepare and return the starting database tables and schema for a workspace question.
     */
    public function getQuestionDatabase(string $id, array $data, User $user): array
    {
        $problem = Problem::findOrFail($id);
        $this->assertProblemAccess($problem, $user, $data['context'] ?? null);
        return $this->workspaceService->runProblemWorkspace($problem, $data, $user, true);
    }

    /**
     * Submit workspace or individual workspace question.
     */
    public function submitWorkspace(string $id, array $data, User $user): array
    {
        $problem = Problem::findOrFail($id);
        $this->assertProblemAccess($problem, $user, $data['context'] ?? null);
        return $this->workspaceService->submitProblemWorkspace($problem, $data, $user);
    }

    public function submitWorkspaceQuestion(string $id, array $data, User $user): array
    {
        $problem = Problem::findOrFail($id);
        $this->assertProblemAccess($problem, $user, $data['context'] ?? null);
        return $this->workspaceService->submitProblemWorkspaceQuestion($problem, $data, $user);
    }

    // =========================================================================
    // Internal Helper Methods
    // =========================================================================

    private function normalizeSchemaFileName(?string $name): string
    {
        $cleaned = trim((string) $name);
        if ($cleaned === '') {
            return 'database.sql';
        }
        if (!str_ends_with(strtolower($cleaned), '.sql')) {
            $cleaned .= '.sql';
        }
        return $cleaned;
    }

    private function normalizeStageExercise(string $part, mixed $config): ?array
    {
        if (!is_array($config)) {
            return null;
        }
        $questions = $config['questions'] ?? [];
        if (!is_array($questions) || empty($questions)) {
            return null;
        }
        $defaultStart = $part === 'DDL' ? 'empty' : 'provided';
        $normalized = [];
        foreach ($questions as $idx => $q) {
            if (!is_array($q)) {
                continue;
            }
            $normalized[] = [
                'id'          => $q['id'] ?? ('q' . ($idx + 1)),
                'name'        => $q['name'] ?? ("{$part}" . ($idx + 1) . '.sql'),
                'description' => $q['description'] ?? '',
                'answer'      => $q['answer'] ?? '',
                'startFrom'   => $q['startFrom'] ?? $q['start_from'] ?? $defaultStart,
                'setupDdl'    => $q['setupDdl'] ?? $q['setup_ddl'] ?? '',
                'setupDml'    => $q['setupDml'] ?? $q['setup_dml'] ?? '',
                'probes'      => is_array($q['probes'] ?? null) ? array_values($q['probes']) : [],
                'difficulty'  => $q['difficulty'] ?? null,
                'category'    => $q['category'] ?? null,
            ];
        }
        return !empty($normalized) ? ['questions' => $normalized] : null;
    }

    private function buildStageChecks(string $part, ?array $exercise): array
    {
        if (!$exercise || empty($exercise['questions'])) {
            return [];
        }
        $checks = [];
        foreach ($exercise['questions'] as $idx => $q) {
            if (trim((string) ($q['answer'] ?? '')) === '') {
                continue;
            }
            $probes = array_filter(array_map('trim', (array) ($q['probes'] ?? [])));
            $checks[] = [
                'id'          => $q['id'] ?? ('q' . ($idx + 1)),
                'name'        => $q['name'] ?? ("{$part}" . ($idx + 1) . '.sql'),
                'description' => $q['description'] ?? '',
                'startFrom'   => $q['startFrom'] ?? ($part === 'DDL' ? 'empty' : 'provided'),
                'hasSetup'    => trim((string) ($q['setupDdl'] ?? '')) !== '' || trim((string) ($q['setupDml'] ?? '')) !== '',
                'gradingMode' => $part === 'TRIGGER' ? (!empty($probes) ? 'behavior' : 'exact') : null,
            ];
        }
        return $checks;
    }

    private function buildQuestionCatalog(
        Problem $problem,
        array $workspaceParts,
        string $schemaSql,
        string $seedData,
        ?array $ddlExercise,
        ?array $dmlExercise,
        ?array $triggerExercise,
        Collection $solutions
    ): array {
        $catalog = [
            'DDL'     => [],
            'DML'     => [],
            'SQL'     => [],
            'TRIGGER' => [],
        ];
        $fallbackDifficulty = $problem->difficulty ?: 'Dễ';

        // 1. DDL
        $ddlQuestions = [];
        if (trim($schemaSql) !== '') {
            $ddlQuestions[] = [
                'id'          => '__base_ddl__',
                'name'        => 'create_tables.sql',
                'kind'        => 'schema',
                'description' => 'Create all tables and declare their primary and foreign keys.',
                'startFrom'   => 'empty',
                'difficulty'  => 'Dễ',
                'category'    => 'DDL - Tạo bảng (CREATE TABLE)',
            ];
        }
        foreach ($ddlExercise['questions'] ?? [] as $q) {
            if (trim((string) ($q['answer'] ?? '')) === '') {
                continue;
            }
            $item = [
                'id'          => $q['id'],
                'name'        => $q['name'] ?? 'DDL.sql',
                'description' => $q['description'] ?? '',
                'difficulty'  => $q['difficulty'] ?? ($q['_difficulty'] ?? null),
                'category'    => $q['category'] ?? ($q['_category'] ?? null),
            ];
            if (isset($q['startFrom'])) {
                $item['startFrom'] = $q['startFrom'];
            }
            $ddlQuestions[] = $item;
        }
        $catalog['DDL'] = $ddlQuestions;

        // 2. DML
        $dmlQuestions = [];
        if (trim($schemaSql) !== '' && trim($seedData) !== '') {
            $dmlQuestions[] = [
                'id'          => '__base_dml__',
                'name'        => 'insert_data.sql',
                'kind'        => 'seed',
                'description' => 'Insert the original sample data into all tables.',
                'startFrom'   => 'structure',
                'difficulty'  => 'Dễ',
                'category'    => 'DML - Thêm dòng dữ liệu (INSERT)',
            ];
        }
        foreach ($dmlExercise['questions'] ?? [] as $q) {
            if (trim((string) ($q['answer'] ?? '')) === '') {
                continue;
            }
            $item = [
                'id'          => $q['id'],
                'name'        => $q['name'] ?? 'DML.sql',
                'description' => $q['description'] ?? '',
                'difficulty'  => $q['difficulty'] ?? ($q['_difficulty'] ?? null),
                'category'    => $q['category'] ?? ($q['_category'] ?? null),
            ];
            if (isset($q['startFrom'])) {
                $item['startFrom'] = $q['startFrom'];
            }
            $dmlQuestions[] = $item;
        }
        $catalog['DML'] = $dmlQuestions;

        // 3. TRIGGER
        $triggerQuestions = [];
        foreach ($triggerExercise['questions'] ?? [] as $q) {
            if (trim((string) ($q['answer'] ?? '')) === '') {
                continue;
            }
            $item = [
                'id'          => $q['id'],
                'name'        => $q['name'] ?? 'TRIGGER.sql',
                'description' => $q['description'] ?? '',
                'difficulty'  => $q['difficulty'] ?? ($q['_difficulty'] ?? null),
                'category'    => $q['category'] ?? ($q['_category'] ?? null),
            ];
            if (isset($q['startFrom'])) {
                $item['startFrom'] = $q['startFrom'];
            }
            $triggerQuestions[] = $item;
        }
        $catalog['TRIGGER'] = $triggerQuestions;

        // 4. SQL
        $sqlQuestions = [];
        foreach ($solutions as $idx => $sol) {
            if (!$sol->enabled || trim((string) $sol->query) === '') {
                continue;
            }
            $rawExpected = is_array($sol->expected) ? $sol->expected : [];
            $sqlQuestions[] = [
                'id'          => $sol->id,
                'name'        => $sol->name ?: ('DQL' . ($idx + 1) . '.sql'),
                'description' => $sol->description ?? '',
                'timeLimitMs' => $sol->time_limit_ms ?: 5000,
                'difficulty'  => $rawExpected['_difficulty'] ?? ($sol->difficulty ?? null),
                'category'    => $rawExpected['_category'] ?? ($sol->category ?? null),
            ];
        }
        if (empty($sqlQuestions)) {
            $legacyQuery = $problem->testCases->first()?->expected_query ?? '';
            if (trim((string) $legacyQuery) !== '') {
                $sqlQuestions[] = [
                    'id'          => '__base_sql__',
                    'name'        => 'query.sql',
                    'description' => '',
                    'timeLimitMs' => 5000,
                    'difficulty'  => $fallbackDifficulty,
                    'category'    => null,
                ];
            }
        }
        $catalog['SQL'] = $sqlQuestions;

        return $catalog;
    }

    private function saveStageExercise(string $problemId, string $part, mixed $exercise): void
    {
        $normalized = $this->normalizeStageExercise($part, $exercise);
        if (!$normalized || empty($normalized['questions'])) {
            ProblemStageExercise::where('problem_id', $problemId)->where('part', $part)->delete();
            return;
        }

        ProblemStageExercise::updateOrCreate(
            ['problem_id' => $problemId, 'part' => $part],
            ['config' => $normalized]
        );
    }

    private function syncTopics(string $problemId, array $topicsList): void
    {
        DB::table('problem_topics')->where('problem_id', $problemId)->delete();

        foreach ($topicsList as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $topic = Topic::firstOrCreate(['name' => $name], ['id' => (string) Str::uuid()]);
            DB::table('problem_topics')->updateOrInsert([
                'problem_id' => $problemId,
                'topic_id'   => $topic->id,
            ]);
        }
    }

    private function saveTestCases(string $problemId, ?array $testCases, string $defaultSchema, string $defaultSeed, string $referenceSolution): void
    {
        $oldTcIds = DB::table('test_cases_rel')->where('problem_id', $problemId)->pluck('id');
        if ($oldTcIds->isNotEmpty()) {
            DB::table('test_case_scripts')->whereIn('test_case_id', $oldTcIds)->delete();
            DB::table('test_cases_rel')->where('problem_id', $problemId)->delete();
        }

        $casesToCreate = $testCases && count($testCases) > 0 ? $testCases : [
            ['schema' => $defaultSchema, 'seedData' => $defaultSeed, 'isHidden' => false],
        ];

        foreach ($casesToCreate as $idx => $tcData) {
            $tcId = (string) Str::uuid();
            $isHidden = $tcData['isHidden'] ?? $tcData['is_hidden'] ?? false;
            $schema = $tcData['schema'] ?? $tcData['schema_sql'] ?? $defaultSchema;
            $seed = $tcData['seedData'] ?? $tcData['seed_data'] ?? $defaultSeed;

            DB::table('test_cases_rel')->insert([
                'id'             => $tcId,
                'problem_id'     => $problemId,
                'is_hidden'      => (bool) $isHidden,
                'order_index'    => $idx + 1,
                'expected_query' => $referenceSolution,
            ]);

            DB::table('test_case_scripts')->insert([
                'id'            => (string) Str::uuid(),
                'test_case_id'  => $tcId,
                'table_name'    => 'Generated',
                'create_script' => $schema,
                'insert_script' => $seed,
                'is_expected'   => false,
            ]);
        }
    }

    private function saveSqlSolutions(string $problemId, array $solutions): void
    {
        $existing = ProblemSqlSolution::where('problem_id', $problemId)->get()->keyBy('id');
        $keepIds = [];

        foreach ($solutions as $index => $solData) {
            $rawId = !empty($solData['id']) ? trim((string) $solData['id']) : '';
            $id = $rawId !== '' ? $rawId : (string) Str::uuid();
            $row = $existing->get($id);
            if (!$row) {
                if (mb_strlen($id) > 50 || ProblemSqlSolution::where('id', $id)->exists()) {
                    $id = (string) Str::uuid();
                }
                $row = new ProblemSqlSolution();
                $row->id = $id;
                $row->problem_id = $problemId;
            }

            $row->name = mb_substr((string) ($solData['name'] ?? ('DQL' . ($index + 1) . '.sql')), 0, 120);
            $row->query = $solData['query'] ?? '';
            $row->description = $solData['description'] ?? '';
            $row->enabled = $solData['enabled'] ?? true;
            $row->time_limit_ms = $solData['timeLimitMs'] ?? $solData['time_limit_ms'] ?? 5000;
            $row->order_index = $index;

            $outputs = $solData['expectedOutputs'] ?? $solData['expected_outputs'] ?? null;
            $visible = $solData['expectedOutputVisible'] ?? $solData['expected_output_visible'] ?? true;
            $setupSql = (string) ($solData['setupSql'] ?? $solData['setup_sql'] ?? '');
            $difficulty = $solData['difficulty'] ?? null;
            $category = $solData['category'] ?? null;

            $expectedPayload = $this->saveExpectedOutputs($outputs, (bool) $visible) ?? [
                '_expectedOutputs'       => [],
                '_expectedOutputVisible' => (bool) $visible,
            ];
            $expectedPayload['_setupSql'] = $setupSql;
            $expectedPayload['_difficulty'] = $difficulty;
            $expectedPayload['_category'] = $category;

            $row->expected = $expectedPayload;
            $row->save();
            $keepIds[] = $row->id;
        }

        foreach ($existing as $id => $row) {
            if (!in_array($id, $keepIds, true)) {
                $row->delete();
            }
        }
    }

    private function saveExpectedOutputs(?array $outputs, bool $visible): ?array
    {
        if (empty($outputs)) {
            return null;
        }

        $normalized = [];
        foreach ($outputs as $output) {
            if (!is_array($output)) {
                continue;
            }
            if (!isset($output['columns']) || !isset($output['rows'])) {
                continue;
            }
            $normalized[] = [
                'name'    => $output['name'] ?? 'Result',
                'columns' => $output['columns'],
                'rows'    => $output['rows'],
            ];
        }

        if (empty($normalized)) {
            return null;
        }

        $first = $normalized[0];
        return [
            'name'                   => $first['name'] ?? 'Result',
            'columns'                => $first['columns'],
            'rows'                   => $first['rows'],
            '_expectedOutputs'       => $normalized,
            '_expectedOutputVisible' => (bool) $visible,
        ];
    }

    private function readExpectedOutputs(mixed $value): array
    {
        if (!is_array($value)) {
            return [[], true];
        }

        $stored = $value['_expectedOutputs'] ?? null;
        $visible = ($value['_expectedOutputVisible'] ?? true) !== false;

        if (is_array($stored)) {
            $outputs = array_values(array_filter($stored, fn ($it) => is_array($it) && isset($it['columns']) && isset($it['rows'])));
            return [$outputs, $visible];
        }

        if (isset($value['columns']) && isset($value['rows'])) {
            return [[[
                'name'    => $value['name'] ?? 'Result',
                'columns' => $value['columns'],
                'rows'    => $value['rows'],
            ]], $visible];
        }

        return [[], $visible];
    }

    private function assertProblemAccess(Problem $p, User $user, ?string $context): void
    {
        if ($p->practice_listed) {
            return;
        }

        if ($user->isAdmin()) {
            return;
        }

        $isCreator = $p->creator_id === $user->id;
        if ($isCreator) {
            return;
        }

        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES, true)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'view');

        if ($isTeacher) {
            $isShared = ContentShare::where('kind', 'problem')
                ->where('resource_id', $p->id)
                ->where('recipient_id', $user->id)
                ->exists();
            if ($isShared) {
                return;
            }
        }

        $hasSubmitted = DB::table('submissions')
            ->where('user_id', $user->id)
            ->where('problem_id', $p->id)
            ->when($context && $context !== 'Practice', fn ($q) => $q->where('context', $context))
            ->exists();

        if ($hasSubmitted) {
            return;
        }

        $now = now();
        $studentAssigned = DB::table('assignment_problems')
            ->join('assignments', 'assignments.id', '=', 'assignment_problems.assignment_id')
            ->where('assignment_problems.problem_id', $p->id)
            ->where('assignments.published', true)
            ->where(function ($oq) use ($now) {
                $oq->whereNull('assignments.opens')
                    ->orWhere('assignments.opens', '<=', $now);
            })
            ->where(function ($q) use ($user, $context) {
                if ($context && $context !== 'Practice') {
                    $q->where('assignments.id', $context);
                }
                $q->where(function ($sub) use ($user) {
                    $sub->whereExists(function ($ex) use ($user) {
                            $ex->select(DB::raw(1))
                                ->from('assignment_classes')
                                ->join('class_enrollments', 'class_enrollments.class_id', '=', 'assignment_classes.class_id')
                                ->whereColumn('assignment_classes.assignment_id', 'assignments.id')
                                ->where('class_enrollments.student_id', $user->id);
                        })
                        ->orWhereExists(function ($ex) use ($user) {
                            $ex->select(DB::raw(1))
                                ->from('assignment_students')
                                ->whereColumn('assignment_students.assignment_id', 'assignments.id')
                                ->where('assignment_students.student_id', $user->id);
                        })
                        ->orWhereExists(function ($ex) use ($user) {
                            $ex->select(DB::raw(1))
                                ->from('exam_admissions')
                                ->whereColumn('exam_admissions.session_id', 'assignments.id')
                                ->where('exam_admissions.student_id', $user->id)
                                ->where('exam_admissions.status', '!=', 'revoked');
                        });
                });
            })
            ->exists();

        if ($studentAssigned) {
            return;
        }

        abort(403, "You don't have permission to view this problem.");
    }

    private function assertCanManageProblems(User $user, string $action): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES, true)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', $action);

        abort_unless($isTeacher, 403, 'Chỉ giảng viên và quản trị viên mới có quyền quản lý bài toán.');
    }

    private function canEditProblem(Problem $p, User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $hasEditPerm = in_array($user->role, User::INSTRUCTOR_ROLES, true)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'edit');

        $isCreator = $p->creator_id === $user->id;
        $isSharedEditor = ContentShare::where('kind', 'problem')
            ->where('resource_id', $p->id)
            ->where('recipient_id', $user->id)
            ->where('can_edit', true)
            ->exists();

        return $hasEditPerm && ($isCreator || $isSharedEditor);
    }

    private function canDeleteProblem(Problem $p, User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $hasDeletePerm = in_array($user->role, User::INSTRUCTOR_ROLES, true)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'delete');

        $isCreator = $p->creator_id === $user->id;

        return $hasDeletePerm && $isCreator;
    }

    private function assertCanEditProblem(Problem $p, User $user): void
    {
        abort_unless($this->canEditProblem($p, $user), 403, 'Bạn không có quyền chỉnh sửa bài toán này.');
    }

    private function assertCanDeleteProblem(Problem $p, User $user): void
    {
        abort_unless($this->canDeleteProblem($p, $user), 403, 'Bạn không có quyền xóa bài toán này.');
    }
}
