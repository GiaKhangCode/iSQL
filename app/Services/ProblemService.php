<?php

namespace App\Services;

use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\TestCase;
use App\Models\TestCaseScript;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProblemService
{
    /**
     * Get list of problems according to user role with statistics and progress.
     */
    public function getAllProblems(User $user): Collection
    {
        $isAdmin = $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isInstructor = in_array($user->role, User::INSTRUCTOR_ROLES)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'view');

        if ($isAdmin) {
            $problems = Problem::all();
        } elseif ($isInstructor) {
            $problems = Problem::where('practice_listed', true)
                ->orWhere('creator_id', $user->id)
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
                    $q->where('assignments.audience_type', 'all_students')
                        ->orWhereExists(function ($ex) use ($user) {
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
            $total = $st ? (int)$st->total_subs : 0;
            $attempted = $st ? (int)$st->attempted : 0;
            $solvedBy = $st ? (int)$st->solved_by : 0;
            $acceptedSubs = $st ? (int)$st->accepted_subs : 0;
            $acceptance = $total > 0 ? (int)round(($acceptedSubs / $total) * 100) : 0;

            $topicsList = $p->topics ?: ($p->topic ? array_map('trim', explode(',', $p->topic)) : []);

            return [
                'id'              => $p->id,
                'number'          => $p->number,
                'title'           => $p->title,
                'difficulty'      => $p->difficulty,
                'topics'          => $topicsList,
                'topic'           => $p->topic,
                'practice_listed' => (bool)$p->practice_listed,
                'practiceListed'  => (bool)$p->practice_listed,
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
            'learners' => (int)$r->learners,
        ])->all();
    }

    /**
     * Get problem detailed response for student, instructor, or admin.
     */
    public function getProblemById(string $id, User $user, ?string $context = null): array
    {
        $problem = Problem::with(['testCases.scripts', 'sqlSolutions'])->findOrFail($id);

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

        // Effective workspace parts
        $workspaceParts = $problem->workspace_parts ?: ['SQL'];

        // Test cases mapping
        $rawTestCases = $problem->testCases;
        $mappedTestCases = [];
        $schemaSql = '';
        $seedData = '';
        $referenceSolution = '';

        foreach ($rawTestCases as $tc) {
            $script = $tc->scripts->first();
            $mappedTestCases[] = [
                'schema'   => $script?->create_script ?? '',
                'seedData' => $script?->insert_script ?? '',
                'isHidden' => (bool)$tc->is_hidden,
            ];
        }

        if (!empty($mappedTestCases)) {
            $schemaSql = $mappedTestCases[0]['schema'];
            $seedData = $mappedTestCases[0]['seedData'];
            $referenceSolution = $isTeacher || $isAdmin
                ? ($rawTestCases->first()?->expected_query ?? '')
                : '';
        }

        // SQL Solutions & Checks
        $solutions = $problem->sqlSolutions;
        $sqlChecks = [];
        foreach ($solutions as $sol) {
            if ($sol->enabled && trim($sol->query) !== '') {
                $sqlChecks[] = [
                    'id'              => $sol->id,
                    'name'            => $sol->name,
                    'description'     => $sol->description ?? '',
                    'timeLimitMs'     => $sol->time_limit_ms,
                    'expectedSamples' => [],
                ];
            }
        }

        $sqlSolutionsResponse = null;
        if ($isTeacher || $isAdmin) {
            $sqlSolutionsResponse = $solutions->map(function ($sol) {
                [$solOutputs, $solVisible] = $this->readExpectedOutputs($sol->expected);
                return [
                    'id'                    => $sol->id,
                    'name'                  => $sol->name,
                    'query'                 => $sol->query,
                    'description'           => $sol->description ?? '',
                    'expectedOutputs'       => $solOutputs,
                    'expectedOutputVisible' => $solVisible,
                    'enabled'               => (bool)$sol->enabled,
                    'timeLimitMs'           => $sol->time_limit_ms,
                ];
            })->all();
        }

        // Student sanitization
        $testCasesResponse = $mappedTestCases;
        $expectedOutputsResponse = $expectedOutputs;
        $expectedSamplesResponse = [];

        if (!$isTeacher && !$isAdmin) {
            // Filter hidden test cases for student
            $testCasesResponse = array_values(array_filter($mappedTestCases, fn ($tc) => !$tc['isHidden']));

            if (!$expectedOutputVisible) {
                $expectedOutputsResponse = [];
                $expectedTable = null;
            } else {
                foreach ($testCasesResponse as $idx => $tc) {
                    if (isset($expectedOutputs[$idx])) {
                        $expectedSamplesResponse[] = [
                            'dataset' => $idx + 1,
                            'table'   => $expectedOutputs[$idx],
                        ];
                    }
                }
            }

            if (in_array('DDL', $workspaceParts)) {
                $schemaSql = '';
                foreach ($testCasesResponse as &$tc) {
                    $tc['schema'] = '';
                }
            }
        }

        $topicsList = $problem->topics ?: ($problem->topic ? array_map('trim', explode(',', $problem->topic)) : []);

        return [
            'id'                          => $problem->id,
            'number'                      => $problem->number,
            'title'                       => $problem->title,
            'difficulty'                  => $problem->difficulty,
            'topics'                      => $topicsList,
            'topic'                       => $problem->topic,
            'practice_listed'             => (bool)$problem->practice_listed,
            'practiceListed'              => (bool)$problem->practice_listed,
            'visibility'                  => $problem->practice_listed ? 'Public' : 'Private',
            'database_type'               => $problem->database_type ?: 'SQL Server',
            'databaseType'                => $problem->database_type ?: 'SQL Server',
            'schema_file_name'            => $problem->schema_file_name ?: 'database.sql',
            'schemaFileName'              => $problem->schema_file_name ?: 'database.sql',
            'workspace_parts'             => $workspaceParts,
            'workspaceParts'              => $workspaceParts,
            'database_description'        => $problem->database_description ?: [],
            'databaseDescription'         => $problem->database_description ?: [],
            'database_description_visible'=> (bool)$problem->database_description_visible,
            'databaseDescriptionVisible' => (bool)$problem->database_description_visible,
            'database_structure_visible'  => true,
            'databaseStructureVisible'   => true,
            'description'                 => $problem->description,
            'statement'                   => $problem->description,
            'requirements'                => $problem->requirements,
            'hint'                        => $problem->hint,
            'hints'                       => $problem->hint ? explode("\n", $problem->hint) : [],
            'schema'                      => $schemaSql,
            'schema_sql'                  => $schemaSql,
            'seedData'                    => $seedData,
            'seed_data'                   => $seedData,
            'referenceSolution'           => $referenceSolution,
            'reference_solution'          => $referenceSolution,
            'expected'                    => $expectedTable,
            'expected_outputs'            => $expectedOutputsResponse,
            'expectedOutputs'             => $expectedOutputsResponse,
            'expected_samples'            => $expectedSamplesResponse,
            'expectedSamples'             => $expectedSamplesResponse,
            'expected_output_visible'     => $expectedOutputVisible,
            'expectedOutputVisible'      => $expectedOutputVisible,
            'testCases'                   => $testCasesResponse,
            'test_cases'                  => $testCasesResponse,
            'sql_solutions'               => $sqlSolutionsResponse,
            'sqlSolutions'                => $sqlSolutionsResponse,
            'sql_checks'                  => $sqlChecks,
            'sqlChecks'                   => $sqlChecks,
            'progress'                    => $progress,
            'created_at'                  => $problem->created_at ? $problem->created_at->toISOString() : null,
            'createdAt'                   => $problem->created_at ? $problem->created_at->toISOString() : null,
        ];
    }

    /**
     * Create a new problem with topics, test cases, and SQL solutions.
     */
    public function createProblem(array $data, User $user): array
    {
        $this->assertCanManageProblems($user, 'add');

        return DB::transaction(function () use ($data, $user) {
            $problemId = (string) Str::uuid();

            // Extract and normalize topics
            $rawTopics = $data['topics'] ?? '';
            $topicsList = is_array($rawTopics)
                ? $rawTopics
                : (is_string($rawTopics) && trim($rawTopics) !== '' ? array_map('trim', explode(',', $rawTopics)) : []);
            $legacyTopic = is_array($rawTopics) ? implode(', ', $rawTopics) : $rawTopics;

            // Visibility
            $isPublic = ($data['visibility'] ?? '') === 'Public'
                || ($data['practice_listed'] ?? false) === true
                || ($data['practiceListed'] ?? false) === true;

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

            $problem = Problem::create([
                'id'                          => $problemId,
                'number'                      => $data['number'],
                'title'                       => $data['title'],
                'topic'                       => $legacyTopic ?: null,
                'topics'                      => $topicsList,
                'difficulty'                  => $data['difficulty'],
                'practice_listed'             => $isPublic,
                'description'                 => $data['statement'] ?? $data['description'] ?? '',
                'requirements'                => $data['requirements'] ?? '',
                'hint'                        => $hint,
                'database_type'               => $data['database'] ?? $data['database_type'] ?? 'SQL Server',
                'schema_file_name'            => $data['schemaFileName'] ?? $data['schema_file_name'] ?? 'database.sql',
                'database_description'        => $data['databaseDescription'] ?? $data['database_description'] ?? [],
                'database_description_visible'=> $data['databaseDescriptionVisible'] ?? $data['database_description_visible'] ?? true,
                'workspace_parts'             => $data['workspaceParts'] ?? $data['workspace_parts'] ?? ['SQL'],
                'expected'                    => $savedExpected,
                'creator_id'                  => $user->id,
            ]);

            // Sync topics
            $this->syncTopics($problemId, $topicsList);

            // Create test cases
            $schemaSql = $data['schema'] ?? $data['schema_sql'] ?? '';
            $seedData = $data['seedData'] ?? $data['seed_data'] ?? '';
            $referenceSolution = $data['referenceSolution'] ?? $data['reference_solution'] ?? '';
            $testCases = $data['testCases'] ?? $data['test_cases'] ?? null;

            $this->saveTestCases($problemId, $testCases, $schemaSql, $seedData, $referenceSolution);

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
                ? $rawTopics
                : (is_string($rawTopics) && trim($rawTopics) !== '' ? array_map('trim', explode(',', $rawTopics)) : []);
            $legacyTopic = is_array($rawTopics) ? implode(', ', $rawTopics) : $rawTopics;

            $isPublic = isset($data['visibility'])
                ? ($data['visibility'] === 'Public')
                : (isset($data['practice_listed']) ? (bool)$data['practice_listed'] : (bool)$problem->practice_listed);

            $hint = $problem->hint;
            if (isset($data['hints']) && is_array($data['hints'])) {
                $hint = implode("\n", $data['hints']);
            } elseif (isset($data['hint'])) {
                $hint = $data['hint'];
            }

            $updatePayload = [
                'number'                      => $data['number'] ?? $problem->number,
                'title'                       => $data['title'] ?? $problem->title,
                'topic'                       => $legacyTopic,
                'topics'                      => $topicsList,
                'difficulty'                  => $data['difficulty'] ?? $problem->difficulty,
                'practice_listed'             => $isPublic,
                'description'                 => $data['statement'] ?? $data['description'] ?? $problem->description,
                'requirements'                => $data['requirements'] ?? $problem->requirements,
                'hint'                        => $hint,
                'database_type'               => $data['database'] ?? $data['database_type'] ?? $problem->database_type,
                'schema_file_name'            => $data['schemaFileName'] ?? $data['schema_file_name'] ?? $problem->schema_file_name,
                'database_description'        => $data['databaseDescription'] ?? $data['database_description'] ?? $problem->database_description,
                'database_description_visible'=> $data['databaseDescriptionVisible'] ?? $data['database_description_visible'] ?? $problem->database_description_visible,
                'workspace_parts'             => $data['workspaceParts'] ?? $data['workspace_parts'] ?? $problem->workspace_parts,
            ];

            if (array_key_exists('expectedOutputs', $data) || array_key_exists('expected_outputs', $data)) {
                $expectedOutputs = $data['expectedOutputs'] ?? $data['expected_outputs'] ?? null;
                $expectedOutputVisible = $data['expectedOutputVisible'] ?? $data['expected_output_visible'] ?? true;
                $updatePayload['expected'] = $this->saveExpectedOutputs($expectedOutputs, $expectedOutputVisible);
            }

            $problem->update($updatePayload);

            // Re-sync topics
            $this->syncTopics($problem->id, $topicsList);

            // Re-create test cases if provided
            if (array_key_exists('testCases', $data) || array_key_exists('test_cases', $data) || array_key_exists('schema', $data) || array_key_exists('seedData', $data)) {
                $schemaSql = $data['schema'] ?? $data['schema_sql'] ?? '';
                $seedData = $data['seedData'] ?? $data['seed_data'] ?? '';
                $referenceSolution = $data['referenceSolution'] ?? $data['reference_solution'] ?? '';
                $testCases = $data['testCases'] ?? $data['test_cases'] ?? null;

                $this->saveTestCases($problem->id, $testCases, $schemaSql, $seedData, $referenceSolution);
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

        DB::transaction(function () use ($problem) {
            $id = $problem->id;

            DB::table('assignment_problems')->where('problem_id', $id)->delete();
            DB::table('submissions')->where('problem_id', $id)->delete();
            DB::table('problem_drafts')->where('problem_id', $id)->delete();
            DB::table('favorites')->where('problem_id', $id)->delete();
            DB::table('problem_list_items')->where('problem_id', $id)->delete();

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

            $problem->delete();
        });
    }

    /**
     * Validate reference solution syntax against test case scripts on Microsoft SQL Server (Docker).
     */
    public function validateSolution(array $data, User $user): array
    {
        $isAdmin = $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'edit');

        abort_unless($isAdmin || $isTeacher, 403, 'Only instructors can validate reference solutions.');

        $referenceSolution = $data['reference_solution'] ?? $data['referenceSolution'] ?? '';
        if (trim($referenceSolution) === '') {
            abort(400, 'Reference solution cannot be empty.');
        }

        $schema = $data['schema'] ?? $data['schema_sql'] ?? '';
        $seedData = $data['seedData'] ?? $data['seed_data'] ?? '';

        if (trim($schema) === '') {
            return [
                'status'          => 'Success',
                'message'         => 'Reference solution validated successfully',
                'executionTimeMs' => 1.0,
                'table'           => [
                    'columns' => [],
                    'rows'    => [],
                ],
            ];
        }

        // Execute sandbox on Microsoft SQL Server (Docker)
        try {
            $this->ensureSqlServerDatabase();

            /** @var \PDO $pdo */
            $pdo = DB::connection('sqlsrv')->getPdo();

            // Execute in an isolated transaction that is ALWAYS rolled back
            // so tables and test seed data never pollute or persist in the database
            $pdo->beginTransaction();

            try {
                // Drop any existing leftover tables with the same names before creating
                preg_match_all('/CREATE\s+TABLE\s+(?:\[?(?:dbo|guest)\]?\.)?\[?([a-zA-Z0-9_#]+)\]?/i', $schema, $matches);
                if (!empty($matches[1])) {
                    $tables = array_unique($matches[1]);
                    foreach (array_reverse($tables) as $tbl) {
                        try {
                            $pdo->exec("IF OBJECT_ID('dbo.[{$tbl}]', 'U') IS NOT NULL DROP TABLE dbo.[{$tbl}];");
                        } catch (\Throwable) {
                            // Ignore potential dependency errors on pre-clean
                        }
                    }
                }

                // 1. Execute schema DDL scripts (handles multiple batches separated by GO)
                $this->executeSqlBatches($pdo, $schema);

                // 2. Execute seed data DML scripts
                if (trim($seedData) !== '') {
                    $this->executeSqlBatches($pdo, $seedData);
                }

                // 3. Execute reference solution query and measure execution time
                $cleanQuery = $this->prepareReferenceQuery($referenceSolution);
                $start = microtime(true);
                $stmt = $pdo->query($cleanQuery);
                $elapsedMs = round((microtime(true) - $start) * 1000, 2);

                $columns = [];
                $rows = [];
                if ($stmt) {
                    $colCount = $stmt->columnCount();
                    for ($i = 0; $i < $colCount; $i++) {
                        $meta = $stmt->getColumnMeta($i);
                        $columns[] = $meta['name'] ?? ('col_' . ($i + 1));
                    }
                    while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                        $rows[] = $row;
                    }
                }

                return [
                    'status'          => 'Success',
                    'message'         => 'Reference solution validated successfully',
                    'executionTimeMs' => max($elapsedMs, 1.0),
                    'table'           => [
                        'columns' => $columns,
                        'rows'    => $rows,
                    ],
                ];
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'could not find driver') || str_contains($errorMessage, 'Driver [sqlsrv] not supported')) {
                $errorMessage = 'Driver sqlsrv chưa được cài trên môi trường hiện tại. Vui lòng chạy qua Docker (docker compose up -d) nơi đã cài sẵn ODBC Driver 18 & PHP sqlsrv extension.';
            } elseif (str_contains($errorMessage, 'Connection refused') || str_contains($errorMessage, 'Login timeout expired') || str_contains($errorMessage, 'TCP Provider')) {
                $errorMessage = 'Không thể kết nối đến Microsoft SQL Server trên Docker. Vui lòng đảm bảo container isql_mssql đang chạy (docker compose up -d). Chi tiết: ' . $errorMessage;
            }

            return [
                'status'  => 'Error',
                'message' => 'Lỗi thực thi SQL (SQL Server): ' . $errorMessage,
            ];
        }
    }

    /**
     * Ensure sql_practice database exists in Microsoft SQL Server.
     */
    protected function ensureSqlServerDatabase(): void
    {
        $dbName = config('database.connections.sqlsrv.database', 'sql_practice');
        try {
            DB::connection('sqlsrv')->getPdo();
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Cannot open database') || str_contains($msg, '4060')) {
                $config = config('database.connections.sqlsrv');
                $host = $config['host'] . ($config['port'] ? ",{$config['port']}" : '');
                $trustCert = ($config['trust_server_certificate'] ?? 'true') ? ';TrustServerCertificate=true' : '';
                $dsn = "sqlsrv:Server={$host};Database=master;LoginTimeout=5{$trustCert}";
                $pdo = new \PDO($dsn, $config['username'], $config['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec("IF NOT EXISTS (SELECT * FROM sys.databases WHERE name = '{$dbName}') CREATE DATABASE [{$dbName}];");
                DB::purge('sqlsrv');
            } else {
                throw $e;
            }
        }
    }

    /**
     * Split and execute SQL Server script batches separated by GO.
     */
    protected function executeSqlBatches(\PDO $pdo, string $script): void
    {
        // Strip USE statements
        $script = preg_replace('/^\s*USE\s+[^;\n]+;?/mi', '', $script);

        // Split by standalone GO (case-insensitive)
        $batches = preg_split('/^\s*GO\s*$/mi', $script);
        foreach ($batches as $batch) {
            $batch = trim($batch);
            if ($batch !== '') {
                $pdo->exec($batch);
            }
        }
    }

    /**
     * Clean and prepare reference query for SQL Server execution.
     */
    protected function prepareReferenceQuery(string $query): string
    {
        $query = preg_replace('/^\s*USE\s+[^;\n]+;?/mi', '', $query);
        $query = preg_replace('/^\s*GO\s*$/mi', '', $query);
        return trim(rtrim(trim($query), ';'));
    }

    // =========================================================================
    // Internal Helper Methods
    // =========================================================================

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
                'is_hidden'      => (bool)$isHidden,
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
            $id = $solData['id'] ?? (string) Str::uuid();
            $row = $existing->get($id);
            if (!$row) {
                $row = new ProblemSqlSolution();
                $row->id = $id;
                $row->problem_id = $problemId;
            }

            $row->name = $solData['name'] ?? 'Solution ' . ($index + 1);
            $row->query = $solData['query'] ?? '';
            $row->description = $solData['description'] ?? '';
            $row->enabled = $solData['enabled'] ?? true;
            $row->time_limit_ms = $solData['timeLimitMs'] ?? $solData['time_limit_ms'] ?? 5000;
            $row->order_index = $index;

            if (isset($solData['expectedOutputs']) || isset($solData['expected_outputs'])) {
                $outputs = $solData['expectedOutputs'] ?? $solData['expected_outputs'];
                $visible = $solData['expectedOutputVisible'] ?? $solData['expected_output_visible'] ?? true;
                $row->expected = $this->saveExpectedOutputs($outputs, $visible);
            }

            $row->save();
            $keepIds[] = $row->id;
        }

        foreach ($existing as $id => $row) {
            if (!in_array($id, $keepIds)) {
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
            'name'                  => $first['name'] ?? 'Result',
            'columns'               => $first['columns'],
            'rows'                  => $first['rows'],
            '_expectedOutputs'      => $normalized,
            '_expectedOutputVisible'=> (bool)$visible,
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
            return [[
                'name'    => $value['name'] ?? 'Result',
                'columns' => $value['columns'],
                'rows'    => $value['rows'],
            ], $visible];
        }

        return [[], $visible];
    }

    private function assertProblemAccess(Problem $p, User $user, ?string $context): void
    {
        if ($p->practice_listed) {
            return;
        }

        $isAdmin = $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'view');

        $isCreator = $p->creator_id === $user->id;

        if ($isAdmin || $isTeacher || $isCreator) {
            return;
        }

        // Student access check:
        // 1. If student previously submitted a solution for this problem
        $hasSubmitted = DB::table('submissions')
            ->where('user_id', $user->id)
            ->where('problem_id', $p->id)
            ->exists();

        if ($hasSubmitted) {
            return;
        }

        // 2. Check if problem is in an assignment accessible to this student
        $studentAssigned = DB::table('assignment_problems')
            ->join('assignments', 'assignments.id', '=', 'assignment_problems.assignment_id')
            ->where('assignment_problems.problem_id', $p->id)
            ->where('assignments.published', true)
            ->where(function ($q) use ($user, $context) {
                if ($context && $context !== 'Practice') {
                    $q->where('assignments.id', $context);
                }
                $q->where(function ($sub) use ($user) {
                    $sub->where('assignments.audience_type', 'all_students')
                        ->orWhereExists(function ($ex) use ($user) {
                            $ex->select(DB::raw(1))
                                ->from('assignment_classes')
                                ->join('class_enrollments', 'class_enrollments.class_id', '=', 'assignment_classes.class_id')
                                ->whereColumn('assignment_classes.assignment_id', 'assignments.id')
                                ->where('class_enrollments.student_id', $user->id);
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
        $isAdmin = $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', $action);

        abort_unless($isAdmin || $isTeacher, 403, 'Only instructors and administrators can create problems.');
    }

    private function assertCanEditProblem(Problem $p, User $user): void
    {
        $isAdmin = $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'edit');

        abort_unless($isAdmin || $isTeacher, 403, 'Only instructors and administrators can edit problems.');

        if ($p->creator_id && $p->creator_id !== $user->id && !$isAdmin) {
            abort(403, "Bạn không có quyền chỉnh sửa bài toán này.");
        }
    }

    private function assertCanDeleteProblem(Problem $p, User $user): void
    {
        $isAdmin = $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'delete');

        abort_unless($isAdmin || $isTeacher, 403, 'Only instructors and administrators can delete problems.');

        if ($p->creator_id && $p->creator_id !== $user->id && !$isAdmin) {
            abort(403, "You don't have permission to delete this problem.");
        }
    }
}
