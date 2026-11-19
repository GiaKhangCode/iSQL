<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\Submission;
use App\Models\TestCase;
use App\Models\TestCaseScript;
use App\Models\User;
use App\Services\Access\StaffAccess;
use App\Models\WorkspaceSubmissionResult;
use App\Services\SqlServer\QueryRewriter;
use App\Services\SqlServer\SandboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProblemWorkspaceService
{
    /**
     * Ensure sql_practice database exists in Microsoft SQL Server.
     */
    public function ensureSqlServerDatabase(): void
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
     * Clean all user objects in dbo schema inside an active transaction.
     */
    protected function sandbox(): SandboxService
    {
        return app(SandboxService::class);
    }

    protected function rewriter(): QueryRewriter
    {
        return app(QueryRewriter::class);
    }

    /**
     * Drop all triggers, foreign keys, views/routines, and tables in the target schema.
     */
    protected function cleanDboSchema(\PDO $pdo, string $schema = 'dbo'): void
    {
        $this->sandbox()->cleanSchema($pdo, $schema);
    }

    /**
     * Split and execute SQL Server script batches separated by GO.
     */
    public function executeSqlBatches(\PDO $pdo, string $script): void
    {
        if (trim($script) === '') {
            return;
        }

        // Strip USE statements at line starts
        $script = preg_replace('/^\s*USE\s+[^;\n]+;?/mi', '', $script);

        foreach ($this->splitSqlBatches($script) as $batch) {
            $batch = trim($batch);
            if ($batch !== '') {
                $pdo->exec($batch);
            }
        }
    }

    /**
     * Split SQL Server batches on standalone GO lines outside string literals and comments.
     */
    private function splitSqlBatches(string $script): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $script);
        $batches = [];
        $current = [];
        $inString = false;
        $inBlockComment = false;

        foreach ($lines as $line) {
            if (!$inString && !$inBlockComment && preg_match('/^[ \t]*GO[ \t]*(?:--.*)?$/i', $line)) {
                $batches[] = implode("\n", $current);
                $current = [];
                continue;
            }

            $current[] = $line;
            $len = strlen($line);
            $inLineComment = false;
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                $next = $i + 1 < $len ? $line[$i + 1] : '';

                if ($inLineComment) {
                    break;
                }
                if ($inBlockComment) {
                    if ($ch === '*' && $next === '/') {
                        $inBlockComment = false;
                        $i++;
                    }
                    continue;
                }
                if ($inString) {
                    if ($ch === "'" && $next === "'") {
                        $i++;
                        continue;
                    }
                    if ($ch === "'") {
                        $inString = false;
                    }
                    continue;
                }

                if ($ch === '-' && $next === '-') {
                    $inLineComment = true;
                    break;
                }
                if ($ch === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inString = true;
                }
            }
        }

        if (!empty($current)) {
            $batches[] = implode("\n", $current);
        }

        return $batches;
    }

    /**
     * Strip SQL comments outside string literals.
     */
    private function stripSqlComments(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $inString = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n" || $ch === "\r") {
                    $inLineComment = false;
                    $out .= $ch;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                    $out .= ' ';
                }
                continue;
            }
            if ($inString) {
                $out .= $ch;
                if ($ch === "'" && $next === "'") {
                    $out .= $next;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inString = true;
            }
            $out .= $ch;
        }

        return $out;
    }

    /**
     * Clean and prepare reference query for SQL Server execution.
     */
    public function prepareQuery(string $query): string
    {
        $query = preg_replace('/^\s*USE\s+[^;\n]+;?/mi', '', $query);
        $query = preg_replace('/^\s*GO\s*$/mi', '', $query);
        return trim(rtrim(trim($query), ';'));
    }

    /**
     * Execute a SELECT query on PDO and return a DataTable structure with executionTimeMs.
     */
    public function executeSelectQuery(\PDO $pdo, string $query, int $rowLimit = 1000, bool $strictRowLimit = false): array
    {
        $cleanQuery = $this->prepareQuery($query);
        if ($cleanQuery === '') {
            throw new \RuntimeException('Each SQL solution must be a SELECT query.');
        }

        // Strip comments safely and allow leading SET <option> / DECLARE @var statements before SELECT/WITH
        $strippedComments = $this->stripSqlComments($cleanQuery);
        $remaining = ltrim($strippedComments);
        $leadingStmtPattern = '/^(?:SET\s+(?:DATEFORMAT|NOCOUNT|ANSI_NULLS|QUOTED_IDENTIFIER|ARITHABORT|NUMERIC_ROUNDABORT|CONCAT_NULL_YIELDS_NULL|LANGUAGE|LOCK_TIMEOUT|TEXTSIZE)\b[^;]*;|DECLARE\s+@[A-Za-z0-9_]+\b[^;]*;)\s*/i';
        while (preg_match($leadingStmtPattern, $remaining, $m)) {
            $remaining = ltrim(substr($remaining, strlen($m[0])));
        }
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $remaining)
            || preg_match('/;\s*(?:INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE|MERGE|GRANT|REVOKE)\b/i', $remaining)) {
            throw new \RuntimeException('Each SQL solution must be a SELECT query.');
        }

        $start = microtime(true);
        $stmt = $pdo->query($cleanQuery);
        $columns = [];
        $rows = [];

        if ($stmt) {
            do {
                $colCount = $stmt->columnCount();
                if ($colCount > 0) {
                    $columns = [];
                    $rows = [];
                    for ($i = 0; $i < $colCount; $i++) {
                        $meta = $stmt->getColumnMeta($i);
                        $colName = trim((string) ($meta['name'] ?? ''));
                        $columns[] = $colName !== '' ? $colName : ('col_' . ($i + 1));
                    }
                    $count = 0;
                    while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                        $count++;
                        if ($strictRowLimit && $count > $rowLimit) {
                            throw new \RuntimeException("Query result has more than {$rowLimit} rows; it is too large to compare.");
                        }
                        if ($count <= $rowLimit) {
                            $rows[] = array_map(fn ($v) => is_numeric($v) && !is_string($v) ? $v : $v, $row);
                        }
                    }
                }
            } while ($stmt->nextRowset());
        }

        $elapsedMs = round((microtime(true) - $start) * 1000, 3);

        return [
            'name'            => 'Result',
            'columns'         => $columns,
            'rows'            => $rows,
            'executionTimeMs' => max($elapsedMs, 0.1),
        ];
    }

    /**
     * Read executed schema metadata from dbo.
     */
    public function readWorkspaceSchema(\PDO $pdo, string $schema = 'dbo'): array
    {
        $safeSchema = $this->sandbox()->sanitizeSchemaName($schema);
        $quotedSchema = $pdo->quote($safeSchema);

        $stmt = $pdo->query("
            SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE,
                   c.CHARACTER_MAXIMUM_LENGTH, c.NUMERIC_PRECISION, c.NUMERIC_SCALE
            FROM INFORMATION_SCHEMA.COLUMNS c
            JOIN sys.tables st ON st.name = c.TABLE_NAME AND SCHEMA_NAME(st.schema_id) = c.TABLE_SCHEMA
            WHERE c.TABLE_SCHEMA = {$quotedSchema} AND st.is_ms_shipped = 0
            ORDER BY st.create_date, st.object_id, c.ORDINAL_POSITION
        ");
        $columnRows = $stmt ? $stmt->fetchAll(\PDO::FETCH_NUM) : [];

        $tables = [];
        $flatColumns = [];
        foreach ($columnRows as $row) {
            [$name, $column, $dataType, $nullable, $length, $precision, $scale] = $row;
            $formattedType = $dataType;
            if ($length !== null) {
                $formattedType .= '(' . ((int) $length === -1 ? 'MAX' : $length) . ')';
            } elseif (in_array(strtolower((string) $dataType), ['decimal', 'numeric'], true) && $precision !== null) {
                $formattedType .= '(' . $precision . ',' . ($scale ?? 0) . ')';
            }
            if (!isset($tables[$name])) {
                $tables[$name] = [
                    'name'        => $name,
                    'columns'     => [],
                    'constraints' => [],
                    'primaryKeys' => [],
                    'foreignKeys' => [],
                ];
            }
            $tables[$name]['columns'][] = [
                'name'        => $column,
                'type'        => $formattedType,
                'constraints' => $nullable === 'NO' ? 'NOT NULL' : 'NULL',
            ];
            $flatColumns[] = [$name, $column, $dataType, $nullable];
        }

        // Primary Keys
        $pkStmt = $pdo->query("
            SELECT k.TABLE_NAME, k.COLUMN_NAME
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS t
            JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
              ON t.CONSTRAINT_CATALOG = k.CONSTRAINT_CATALOG
             AND t.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             AND t.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE t.TABLE_SCHEMA = {$quotedSchema} AND t.CONSTRAINT_TYPE = 'PRIMARY KEY'
            ORDER BY k.TABLE_NAME, k.ORDINAL_POSITION
        ");
        if ($pkStmt) {
            foreach ($pkStmt->fetchAll(\PDO::FETCH_NUM) as [$name, $column]) {
                if (isset($tables[$name])) {
                    $tables[$name]['primaryKeys'][] = $column;
                }
            }
        }

        // Check Constraints
        $ccStmt = $pdo->query("
            SELECT t.name, cc.definition, cc.is_disabled, cc.is_not_trusted
            FROM sys.check_constraints cc
            JOIN sys.tables t ON cc.parent_object_id = t.object_id
            WHERE SCHEMA_NAME(t.schema_id) = {$quotedSchema} AND t.is_ms_shipped = 0
            ORDER BY t.name, cc.name
        ");
        if ($ccStmt) {
            foreach ($ccStmt->fetchAll(\PDO::FETCH_NUM) as [$name, $definition, $disabled, $untrusted]) {
                if (isset($tables[$name])) {
                    $tables[$name]['constraints'][] = 'CHECK ' . ($definition ?? '') . '|disabled:' . ((int) $disabled) . '|untrusted:' . ((int) $untrusted);
                }
            }
        }

        // Foreign Keys
        $fkStmt = $pdo->query("
            SELECT t.name, c.name, rt.name, rc.name, fk.name, fk.is_disabled, fk.is_not_trusted,
                   fk.delete_referential_action_desc, fk.update_referential_action_desc
            FROM sys.foreign_key_columns fkc
            JOIN sys.foreign_keys fk ON fk.object_id = fkc.constraint_object_id
            JOIN sys.tables t ON t.object_id = fkc.parent_object_id
            JOIN sys.columns c ON c.object_id = t.object_id AND c.column_id = fkc.parent_column_id
            JOIN sys.tables rt ON rt.object_id = fkc.referenced_object_id
            JOIN sys.columns rc ON rc.object_id = rt.object_id AND rc.column_id = fkc.referenced_column_id
            WHERE SCHEMA_NAME(t.schema_id) = {$quotedSchema} AND t.is_ms_shipped = 0
            ORDER BY t.name, fk.name, fkc.constraint_column_id
        ");
        if ($fkStmt) {
            $relationships = [];
            foreach ($fkStmt->fetchAll(\PDO::FETCH_NUM) as [$name, $column, $target, $targetColumn, $constraint, $disabled, $untrusted, $onDelete, $onUpdate]) {
                if (!isset($tables[$name])) {
                    continue;
                }
                $key = $name . '|' . $constraint;
                if (!isset($relationships[$key])) {
                    $relationships[$key] = [
                        'columns'           => [],
                        'referencedTable'   => $target,
                        'referencedColumns' => [],
                        'isDisabled'        => (bool) $disabled,
                        'isNotTrusted'      => (bool) $untrusted,
                        'onDelete'          => $onDelete,
                        'onUpdate'          => $onUpdate,
                    ];
                }
                $relationships[$key]['columns'][] = $column;
                $relationships[$key]['referencedColumns'][] = $targetColumn;
            }
            foreach ($relationships as $key => $rel) {
                [$name] = explode('|', $key, 2);
                $tables[$name]['foreignKeys'][] = $rel;
            }
        }

        // Default Constraints
        $dcStmt = $pdo->query("
            SELECT t.name, c.name, dc.definition
            FROM sys.default_constraints dc
            JOIN sys.tables t ON dc.parent_object_id = t.object_id
            JOIN sys.columns c ON c.object_id = t.object_id AND c.column_id = dc.parent_column_id
            WHERE SCHEMA_NAME(t.schema_id) = {$quotedSchema} AND t.is_ms_shipped = 0
            ORDER BY t.name, c.column_id
        ");
        if ($dcStmt) {
            foreach ($dcStmt->fetchAll(\PDO::FETCH_NUM) as [$name, $column, $definition]) {
                if (isset($tables[$name])) {
                    $tables[$name]['constraints'][] = "DEFAULT [{$column}] = {$definition}";
                }
            }
        }

        // Unique Constraints
        $uqStmt = $pdo->query("
            SELECT t.name, i.index_id, c.name, i.is_disabled
            FROM sys.indexes i
            JOIN sys.tables t ON t.object_id = i.object_id
            JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE SCHEMA_NAME(t.schema_id) = {$quotedSchema} AND t.is_ms_shipped = 0 AND i.is_unique_constraint = 1 AND ic.key_ordinal > 0
            ORDER BY t.name, i.index_id, ic.key_ordinal
        ");
        if ($uqStmt) {
            $uniqueKeys = [];
            foreach ($uqStmt->fetchAll(\PDO::FETCH_NUM) as [$name, $indexId, $column, $disabled]) {
                $uKey = $name . '|' . $indexId . '|' . ((int) $disabled);
                $uniqueKeys[$uKey][] = $column;
            }
            foreach ($uniqueKeys as $uKey => $keyCols) {
                [$name, , $disabled] = explode('|', $uKey);
                if (isset($tables[$name])) {
                    usort($keyCols, 'strcasecmp');
                    $names = implode(',', array_map(fn ($col) => "[{$col}]", $keyCols));
                    $tables[$name]['constraints'][] = "UNIQUE ({$names})|disabled:{$disabled}";
                }
            }
        }

        return [array_values($tables), $flatColumns];
    }

    /**
     * Read table rows from dbo for each table in schemaTables.
     */
    public function readWorkspaceTables(\PDO $pdo, array $schemaTables, int $rowLimit = 100, bool $strict = false, string $schema = 'dbo'): array
    {
        $safeSchema = $this->sandbox()->sanitizeSchemaName($schema);
        $tables = [];
        foreach ($schemaTables as $definition) {
            $name = $definition['name'];
            $quoted = str_replace(']', ']]', $name);
            $limitPlusOne = $rowLimit + 1;
            $stmt = $pdo->query("SELECT TOP ({$limitPlusOne}) * FROM [{$safeSchema}].[{$quoted}]");
            $columns = [];
            $rows = [];
            if ($stmt) {
                $colCount = $stmt->columnCount();
                for ($i = 0; $i < $colCount; $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $colName = trim((string) ($meta['name'] ?? ''));
                    $columns[] = $colName !== '' ? $colName : ('col_' . ($i + 1));
                }
                while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                    $rows[] = $row;
                }
            }
            if ($strict && count($rows) > $rowLimit) {
                throw new \RuntimeException("Table {$name} has more than {$rowLimit} rows; it is too large to preview or compare.");
            }
            $tables[] = [
                'name'    => $name,
                'columns' => $columns,
                'rows'    => array_slice($rows, 0, $rowLimit),
            ];
        }
        return $tables;
    }

    /**
     * Compare schema before and after DDL execution.
     */
    public function computeSchemaChanges(array $before, array $after): array
    {
        $previous = [];
        foreach ($before as $t) {
            $previous[$t['name']] = $t;
        }
        $current = [];
        foreach ($after as $t) {
            $current[$t['name']] = $t;
        }

        $changes = [];
        foreach ($current as $name => $table) {
            if (!isset($previous[$name])) {
                $changes[] = ['name' => $name, 'change' => 'created', 'before' => null, 'table' => $table];
            } elseif ($table != $previous[$name]) {
                $changes[] = ['name' => $name, 'change' => 'modified', 'before' => $previous[$name], 'table' => $table];
            }
        }
        foreach ($previous as $name => $table) {
            if (!isset($current[$name])) {
                $changes[] = ['name' => $name, 'change' => 'dropped', 'before' => $table, 'table' => null];
            }
        }

        return $changes;
    }

    /**
     * Order-independent schema comparison keyed by lowercase table name.
     */
    public function schemasMatch(array $actual, array $expected): bool
    {
        $index = function (array $tables): array {
            $map = [];
            foreach ($tables as $t) {
                $key = strtolower((string) ($t['name'] ?? ''));
                $map[$key] = $t;
            }
            ksort($map);
            return $map;
        };
        return $index($actual) == $index($expected);
    }

    /**
     * Compare table data before and after DML execution.
     */
    public function computeDataChanges(array $before, array $after): array
    {
        $previous = [];
        foreach ($before as $t) {
            $previous[$t['name']] = $t;
        }
        $current = [];
        foreach ($after as $t) {
            $current[$t['name']] = $t;
        }

        $serializeRows = function (array $rows): array {
            $serialized = array_map(fn ($r) => json_encode($r), $rows);
            sort($serialized);
            return $serialized;
        };

        $changes = [];
        foreach ($current as $name => $table) {
            if (!isset($previous[$name])) {
                $changes[] = ['name' => $name, 'change' => 'created', 'before' => null, 'table' => $table];
            } elseif ($table['columns'] !== $previous[$name]['columns'] || $serializeRows($table['rows']) !== $serializeRows($previous[$name]['rows'])) {
                $changes[] = ['name' => $name, 'change' => 'modified', 'before' => $previous[$name], 'table' => $table];
            }
        }
        foreach ($previous as $name => $table) {
            if (!isset($current[$name])) {
                $changes[] = ['name' => $name, 'change' => 'dropped', 'before' => $table, 'table' => null];
            }
        }

        return $changes;
    }

    /**
     * Validate DQL reference solution or prepare starting database for preview.
     */
    public function validateSolution(array $data): array
    {
        $schemaSql = (string) ($data['schema'] ?? $data['schema_sql'] ?? '');
        $seedData = (string) ($data['seedData'] ?? $data['seed_data'] ?? '');
        $setupSql = (string) ($data['setupSql'] ?? $data['setup_sql'] ?? '');
        $prepareOnly = (bool) ($data['prepareOnly'] ?? $data['prepare_only'] ?? false);
        $referenceSolution = (string) ($data['referenceSolution'] ?? $data['reference_solution'] ?? '');

        if (!$prepareOnly && trim($referenceSolution) === '') {
            return [
                'status'  => 'Error',
                'message' => 'Reference solution cannot be empty.',
            ];
        }

        try {
            $this->ensureSqlServerDatabase();
            /** @var \PDO $pdo */
            $pdo = DB::connection('sqlsrv')->getPdo();
            $pdo->beginTransaction();

            try {
                $this->cleanDboSchema($pdo);
                $this->executeSqlBatches($pdo, $schemaSql);
                $this->executeSqlBatches($pdo, $seedData);
                if (trim($setupSql) !== '') {
                    $this->executeSqlBatches($pdo, $setupSql);
                }

                if ($prepareOnly) {
                    [$schemaTables] = $this->readWorkspaceSchema($pdo);
                    $tables = $this->readWorkspaceTables($pdo, $schemaTables, 100);
                    return [
                        'status'          => 'Success',
                        'message'         => 'SQL executed successfully.',
                        'table'           => null,
                        'tables'          => $tables,
                        'schemaTables'    => $schemaTables,
                        'executionTimeMs' => 1.0,
                    ];
                }

                $tableResult = $this->executeSelectQuery($pdo, $referenceSolution, 10000, true);

                return [
                    'status'          => 'Success',
                    'message'         => 'Validated successfully.',
                    'table'           => $tableResult,
                    'executionTimeMs' => $tableResult['executionTimeMs'],
                ];
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (\Throwable $e) {
            return [
                'status'  => 'Error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate a DDL, DML, or TRIGGER stage question (POST /api/problems/validate-stage).
     */
    public function validateStage(array $data): array
    {
        $part = strtoupper((string) ($data['part'] ?? 'DDL'));
        $schemaSql = (string) ($data['schema'] ?? $data['schema_sql'] ?? '');
        $seedData = (string) ($data['seedData'] ?? $data['seed_data'] ?? '');
        $prepareOnly = (bool) ($data['prepareOnly'] ?? $data['prepare_only'] ?? false);
        $question = is_array($data['question'] ?? null) ? $data['question'] : [];

        $answer = (string) ($question['answer'] ?? '');
        $startFrom = (string) ($question['startFrom'] ?? $question['start_from'] ?? 'provided');
        $setupDdl = (string) ($question['setupDdl'] ?? $question['setup_ddl'] ?? '');
        $setupDml = (string) ($question['setupDml'] ?? $question['setup_dml'] ?? '');
        $probes = is_array($question['probes'] ?? null) ? array_values(array_filter(array_map('trim', $question['probes']))) : [];

        if (!$prepareOnly && trim($answer) === '') {
            return [
                'status'  => 'Error',
                'message' => 'Add a reference answer before running this question.',
            ];
        }

        $schema = !empty($data['schemaName']) ? $this->sandbox()->sanitizeSchemaName((string) $data['schemaName']) : 'dbo';
        $persist = !empty($data['persist']) && $schema !== 'dbo';

        if ($part === 'TRIGGER') {
            $result = $this->runTriggerSingle($schemaSql, $seedData, $startFrom, $setupDdl, $setupDml, $prepareOnly ? '' : $answer, '', false, $schema, $persist);
            if (($result['status'] ?? '') === 'Error') {
                return $result;
            }
            if (!$prepareOnly && !empty($probes)) {
                $result['scenarios'] = $this->runTriggerScenarioPreview($schemaSql, $seedData, $startFrom, $setupDdl, $setupDml, $answer, $probes);
            }
            return $result;
        }

        try {
            $this->ensureSqlServerDatabase();
            /** @var \PDO $pdo */
            $pdo = DB::connection('sqlsrv')->getPdo();

            if ($persist) {
                $this->sandbox()->ensureSchema($pdo, $schema);
            } else {
                $pdo->beginTransaction();
            }

            try {
                $knownTables = $schema !== 'dbo'
                    ? array_values(array_unique(array_merge(
                        $this->sandbox()->listTables($pdo, $schema),
                        $this->rewriter()->extractTableNames("{$schemaSql}\n{$setupDdl}\n{$answer}")
                    )))
                    : [];

                $rewrite = fn (string $sql) => $schema !== 'dbo'
                    ? $this->rewriter()->rewrite($sql, $schema, $knownTables)
                    : $sql;

                // In persist + prepareOnly mode, if the student already has tables in their schema,
                // keep and inspect their persisted schema rather than wiping it.
                $hasExistingTables = $persist && $prepareOnly && !empty($this->sandbox()->listTables($pdo, $schema));
                if (!$hasExistingTables) {
                    $this->cleanDboSchema($pdo, $schema);

                    if (in_array($startFrom, ['structure', 'provided'], true)) {
                        $this->executeSqlBatches($pdo, $rewrite($schemaSql));
                    }
                    if ($startFrom === 'provided') {
                        $this->executeSqlBatches($pdo, $rewrite($seedData));
                    }
                    if (trim($setupDdl) !== '') {
                        $this->executeSqlBatches($pdo, $rewrite($setupDdl));
                    }
                    if (trim($setupDml) !== '') {
                        $this->executeSqlBatches($pdo, $rewrite($setupDml));
                    }
                }

                [$startingSchemaTables] = $this->readWorkspaceSchema($pdo, $schema);
                $startingDataTables = $part === 'DML' ? $this->readWorkspaceTables($pdo, $startingSchemaTables, 1000, true, $schema) : [];

                $elapsedMs = 0.0;
                if (!$prepareOnly) {
                    $start = microtime(true);
                    $this->executeSqlBatches($pdo, $rewrite($answer));
                    $elapsedMs = round((microtime(true) - $start) * 1000, 3);
                }

                [$afterSchemaTables, $flatColumns] = $this->readWorkspaceSchema($pdo, $schema);
                $afterDataTables = $this->readWorkspaceTables($pdo, $afterSchemaTables, 1000, true, $schema);

                $tables = $part === 'DDL' && !$prepareOnly && trim($answer) !== ''
                    ? [['name' => 'Table structure', 'columns' => ['Table', 'Column', 'Data type', 'Nullable'], 'rows' => $flatColumns]]
                    : $afterDataTables;

                $result = [
                    'status'          => 'Success',
                    'message'         => "{$part} executed successfully.",
                    'table'           => null,
                    'tables'          => $tables,
                    'schemaTables'    => $afterSchemaTables,
                    'schemaName'      => $schema,
                    'executionTimeMs' => $elapsedMs,
                ];

                if (!$prepareOnly && $part === 'DDL') {
                    $result['schemaChanges'] = $this->computeSchemaChanges($startingSchemaTables, $afterSchemaTables);
                }
                if (!$prepareOnly && $part === 'DML') {
                    $result['dataChanges'] = $this->computeDataChanges($startingDataTables, $afterDataTables);
                }

                return $result;
            } finally {
                if (!$persist && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (\Throwable $e) {
            $errResult = [
                'status'          => 'Error',
                'message'         => $e->getMessage(),
                'executionTimeMs' => 0,
            ];
            if ($persist && isset($pdo)) {
                try {
                    [$errSchemaTables] = $this->readWorkspaceSchema($pdo, $schema);
                    $errResult['schemaTables'] = $errSchemaTables;
                    $errResult['tables'] = $this->readWorkspaceTables($pdo, $errSchemaTables, 1000, false, $schema);
                } catch (\Throwable) {
                    // Ignore secondary read failure
                }
            }
            return $errResult;
        }
    }

    /**
     * Execute a single TRIGGER stage run inside an isolated transaction or persisted student schema.
     */
    protected function runTriggerSingle(
        string $schemaSql,
        string $seedData,
        string $startFrom,
        string $setupDdl,
        string $setupDml,
        string $triggerSql,
        string $probeSql = '',
        bool $allTables = false,
        string $schema = 'dbo',
        bool $persist = false
    ): array {
        $prepareOnly = trim($triggerSql) === '';
        $elapsedMs = 0.0;
        $safeSchema = $this->sandbox()->sanitizeSchemaName($schema);
        $persist = $persist && $safeSchema !== 'dbo';

        try {
            $this->ensureSqlServerDatabase();
            /** @var \PDO $pdo */
            $pdo = DB::connection('sqlsrv')->getPdo();

            if ($persist) {
                $this->sandbox()->ensureSchema($pdo, $safeSchema);
            } else {
                $pdo->beginTransaction();
            }

            try {
                $knownTables = $safeSchema !== 'dbo'
                    ? array_values(array_unique(array_merge(
                        $this->sandbox()->listTables($pdo, $safeSchema),
                        $this->rewriter()->extractTableNames("{$schemaSql}\n{$setupDdl}\n{$triggerSql}")
                    )))
                    : [];

                $rewrite = fn (string $sql) => $safeSchema !== 'dbo'
                    ? $this->rewriter()->rewrite($sql, $safeSchema, $knownTables)
                    : $sql;

                $this->cleanDboSchema($pdo, $safeSchema);

                if ($startFrom !== 'empty') {
                    $this->executeSqlBatches($pdo, $rewrite($schemaSql));
                }
                if ($startFrom === 'provided') {
                    $this->executeSqlBatches($pdo, $rewrite($seedData));
                }
                if (trim($setupDdl) !== '') {
                    $this->executeSqlBatches($pdo, $rewrite($setupDdl));
                }
                if (trim($setupDml) !== '') {
                    $this->executeSqlBatches($pdo, $rewrite($setupDml));
                }

                [$beforeSchema] = $this->readWorkspaceSchema($pdo, $safeSchema);
                $triggers = [];

                if (!$prepareOnly) {
                    $start = microtime(true);
                    $this->executeSqlBatches($pdo, $rewrite($triggerSql));
                    $elapsedMs = round((microtime(true) - $start) * 1000, 3);

                    if (trim($probeSql) !== '') {
                        $this->executeSqlBatches($pdo, $rewrite($probeSql));
                    }

                    $quotedSchema = $pdo->quote($safeSchema);
                    $trStmt = $pdo->query("
                        SELECT tr.name, t.name, tr.is_disabled, tr.is_instead_of_trigger
                        FROM sys.triggers tr
                        JOIN sys.tables t ON t.object_id = tr.parent_id
                        WHERE SCHEMA_NAME(t.schema_id) = {$quotedSchema} AND t.is_ms_shipped = 0
                        ORDER BY tr.name
                    ");
                    if ($trStmt) {
                        foreach ($trStmt->fetchAll(\PDO::FETCH_NUM) as [$trName, $tblName, $disabled, $insteadOf]) {
                            $triggers[] = [
                                'name'       => $trName,
                                'table'      => $tblName,
                                'isDisabled' => (bool) $disabled,
                                'insteadOf'  => (bool) $insteadOf,
                                'events'     => [],
                            ];
                        }
                    }

                    $evStmt = $pdo->query("
                        SELECT tr.name, te.type_desc
                        FROM sys.trigger_events te
                        JOIN sys.triggers tr ON tr.object_id = te.object_id
                        JOIN sys.tables t ON t.object_id = tr.parent_id
                        WHERE SCHEMA_NAME(t.schema_id) = {$quotedSchema} AND t.is_ms_shipped = 0
                    ");
                    $eventsMap = [];
                    if ($evStmt) {
                        foreach ($evStmt->fetchAll(\PDO::FETCH_NUM) as [$trName, $eventDesc]) {
                            $eventsMap[$trName][] = $eventDesc;
                        }
                    }
                    foreach ($triggers as &$trRow) {
                        $evs = $eventsMap[$trRow['name']] ?? [];
                        sort($evs);
                        $trRow['events'] = $evs;
                    }
                    unset($trRow);

                    if (empty($triggers)) {
                        throw new \RuntimeException('The SQL did not leave the requested trigger in the question database.');
                    }
                }

                $tables = $this->readWorkspaceTables($pdo, $beforeSchema, 100, false, $safeSchema);
                if (!$prepareOnly && !$allTables) {
                    $affected = array_map(fn ($r) => strtolower($r['table']), $triggers);
                    $tables = array_values(array_filter($tables, fn ($t) => in_array(strtolower($t['name']), $affected, true)));
                }

                return [
                    'status'          => 'Success',
                    'part'            => 'TRIGGER',
                    'message'         => $prepareOnly ? 'Starting database prepared.' : 'Trigger created successfully.',
                    'schemaTables'    => $beforeSchema,
                    'schemaName'      => $safeSchema,
                    'tables'          => $tables,
                    'triggers'        => $triggers,
                    'executionTimeMs' => $elapsedMs,
                ];
            } finally {
                if (!$persist && $pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (\Throwable) {
                    }
                }
            }
        } catch (\Throwable $e) {
            return [
                'status'          => 'Error',
                'part'            => 'TRIGGER',
                'message'         => $e->getMessage(),
                'executionTimeMs' => $elapsedMs,
            ];
        }
    }

    /**
     * Fire each hidden scenario against the reference trigger so the teacher sees which tables it changes.
     */
    protected function runTriggerScenarioPreview(
        string $schemaSql,
        string $seedData,
        string $startFrom,
        string $setupDdl,
        string $setupDml,
        string $triggerSql,
        array $probes
    ): array {
        $base = $this->runTriggerSingle($schemaSql, $seedData, $startFrom, $setupDdl, $setupDml, $triggerSql, '', true);
        $before = [];
        foreach ($base['tables'] ?? [] as $tbl) {
            $before[$tbl['name']] = $tbl['rows'];
        }

        $preview = [];
        foreach ($probes as $idx => $probe) {
            $scenarioNum = $idx + 1;
            $run = $this->runTriggerSingle($schemaSql, $seedData, $startFrom, $setupDdl, $setupDml, $triggerSql, $probe, true);
            if (($run['status'] ?? '') !== 'Success') {
                $preview[] = [
                    'scenario'      => $scenarioNum,
                    'ok'            => false,
                    'message'       => $run['message'] ?? 'Scenario failed.',
                    'changedTables' => [],
                ];
                continue;
            }

            $changed = [];
            foreach ($run['tables'] ?? [] as $tbl) {
                $tName = $tbl['name'];
                if (($tbl['rows'] ?? []) != ($before[$tName] ?? null)) {
                    $changed[] = $tName;
                }
            }

            $preview[] = [
                'scenario'      => $scenarioNum,
                'ok'            => !empty($changed),
                'changedTables' => $changed,
                'message'       => !empty($changed) ? '' : 'This scenario changed no table, so it cannot tell a correct trigger from a wrong one.',
            ];
        }

        return $preview;
    }

    /**
     * Compare two result tables (columns and rows).
     */
    public function compareResults(array $actual, array $expected): array
    {
        $actCols = array_map(fn ($c) => strtolower((string) $c), $actual['columns'] ?? []);
        $expCols = array_map(fn ($c) => strtolower((string) $c), $expected['columns'] ?? []);

        if (count($actCols) !== count($expCols)) {
            return [false, 'Column count mismatch: returned ' . count($actCols) . ', expected ' . count($expCols) . '.'];
        }

        if ($actCols !== $expCols) {
            return [false, 'Column names or their order do not match the expected result.'];
        }

        $actRows = $actual['rows'] ?? [];
        $expRows = $expected['rows'] ?? [];

        if (count($actRows) !== count($expRows)) {
            return [false, 'Row count mismatch: returned ' . count($actRows) . ', expected ' . count($expRows) . '.'];
        }

        $normalize = function (array $rows): array {
            $norm = array_map(function ($r) {
                return implode('|', array_map(fn ($v) => $v !== null ? (string) $v : 'NULL', (array) $r));
            }, $rows);
            sort($norm);
            return $norm;
        };

        if ($normalize($actRows) !== $normalize($expRows)) {
            return [false, 'The result values or rows do not match the expected result.'];
        }

        return [true, 'Correct!'];
    }

    /**
     * Run or submit a student DQL query on a problem.
     */
    public function runOrSubmitDql(Problem $problem, array $data, User $user, bool $isSubmit = false): array
    {
        $query = (string) ($data['query'] ?? $data['script'] ?? '');
        $solutionId = $data['solutionId'] ?? $data['solution_id'] ?? $data['questionId'] ?? $data['question_id'] ?? null;
        $skipStore = (bool) ($data['_skipStore'] ?? false);
        $source = (string) ($data['source'] ?? 'Practice');
        $context = (string) ($data['context'] ?? 'Practice');
        $databaseType = (string) ($data['database'] ?? 'SQL Server');
        $schemaName = $this->sandbox()->studentSchemaName($user->id, $context, $problem->id);

        $solutions = $problem->sqlSolutions()->orderBy('order_index')->get();
        $enabledSolutions = $solutions->filter(fn ($s) => $s->enabled && trim($s->query) !== '')->values();
        $targetSolution = $solutionId
            ? $enabledSolutions->firstWhere('id', $solutionId)
            : $enabledSolutions->first();

        $testCases = $problem->testCases()->with('scripts')->orderBy('order_index')->get();
        $casesToRun = [];
        foreach ($testCases as $idx => $tc) {
            if (!$isSubmit && $tc->is_hidden) {
                continue;
            }
            $casesToRun[] = [$idx, $tc];
            if (!$isSubmit && count($casesToRun) === 1) {
                break;
            }
        }

        if (empty($casesToRun) && $testCases->isNotEmpty()) {
            $casesToRun[] = [0, $testCases->first()];
        }

        $setupSql = '';
        $expectedOutputs = [];
        $outputsVisible = true;
        if ($targetSolution && is_array($targetSolution->expected)) {
            $setupSql = (string) ($targetSolution->expected['_setupSql'] ?? '');
            $expectedOutputs = $targetSolution->expected['_expectedOutputs'] ?? [];
            $outputsVisible = ($targetSolution->expected['_expectedOutputVisible'] ?? true) !== false;
        } elseif (is_array($problem->expected)) {
            $expectedOutputs = $problem->expected['_expectedOutputs'] ?? [$problem->expected];
            $outputsVisible = ($problem->expected['_expectedOutputVisible'] ?? true) !== false;
        }

        $passed = 0;
        $total = max(1, count($casesToRun));
        $lastTable = null;
        $comparisons = [];
        $datasets = [];
        $firstError = null;
        $elapsedMs = 0.0;

        $schemaName = !empty($data['schemaName'])
            ? $this->sandbox()->sanitizeSchemaName((string) $data['schemaName'])
            : $this->sandbox()->studentSchemaName($user->id, $context, $problem->id);

        try {
            $this->ensureSqlServerDatabase();
            /** @var \PDO $pdo */
            $pdo = DB::connection('sqlsrv')->getPdo();
            $this->sandbox()->ensureSchema($pdo, $schemaName);

            foreach ($casesToRun as [$datasetIndex, $tc]) {
                $script = $tc->scripts->first();
                $schemaSql = $script?->create_script ?? '';
                $seedSql = $script?->insert_script ?? '';
                $refQuery = $targetSolution ? $targetSolution->query : ($tc->expected_query ?? '');

                $useStudentSchema = ($datasetIndex === 0);
                $targetSchema = $useStudentSchema ? $schemaName : 'dbo';

                if ($useStudentSchema) {
                    $existingTables = $this->sandbox()->listTables($pdo, $schemaName);
                    $knownTables = array_values(array_unique(array_merge(
                        $existingTables,
                        $this->rewriter()->extractTableNames("{$schemaSql}\n{$setupSql}")
                    )));
                    if (empty($existingTables) && trim($schemaSql) !== '') {
                        $this->executeSqlBatches($pdo, $this->rewriter()->rewrite($schemaSql, $schemaName, $knownTables));
                        if (trim($seedSql) !== '') {
                            $this->executeSqlBatches($pdo, $this->rewriter()->rewrite($seedSql, $schemaName, $knownTables));
                        }
                        if (trim($setupSql) !== '') {
                            $this->executeSqlBatches($pdo, $this->rewriter()->rewrite($setupSql, $schemaName, $knownTables));
                        }
                        $knownTables = array_values(array_unique(array_merge(
                            $knownTables,
                            $this->sandbox()->listTables($pdo, $schemaName)
                        )));
                    }

                    $rewrittenRef = trim($refQuery) !== '' ? $this->rewriter()->rewrite($refQuery, $schemaName, $knownTables) : '';
                    $rewrittenStudent = $this->rewriter()->rewrite($query, $schemaName, $knownTables);

                    $pdo->beginTransaction();
                    try {
                        $expected = $expectedOutputs[$datasetIndex] ?? null;
                        if (!$expected && $rewrittenRef !== '') {
                            $expected = $this->executeSelectQuery($pdo, $rewrittenRef, 10000);
                        }
                        $actual = $this->executeSelectQuery($pdo, $rewrittenStudent, 10000);
                    } finally {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    }
                } else {
                    $pdo->beginTransaction();
                    try {
                        $this->cleanDboSchema($pdo, $targetSchema);
                        $this->executeSqlBatches($pdo, $schemaSql);
                        $this->executeSqlBatches($pdo, $seedSql);
                        if (trim($setupSql) !== '') {
                            $this->executeSqlBatches($pdo, $setupSql);
                        }

                        $expected = $expectedOutputs[$datasetIndex] ?? null;
                        if (!$expected && trim($refQuery) !== '') {
                            $expected = $this->executeSelectQuery($pdo, $refQuery, 10000);
                        }

                        $actual = $this->executeSelectQuery($pdo, $query, 10000);
                    } finally {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    }
                }

                $elapsedMs += $actual['executionTimeMs'] ?? 0;
                $publicActual = [
                    'name'     => $actual['name'] ?? 'Result',
                    'columns'  => $actual['columns'] ?? [],
                    'rows'     => array_slice($actual['rows'] ?? [], 0, 100),
                    'rowCount' => count($actual['rows'] ?? []),
                ];

                if (!$tc->is_hidden) {
                    $lastTable = $publicActual;
                }

                if ($expected) {
                    [$correct, $msg] = $this->compareResults($actual, $expected);
                    $publicExpected = [
                        'name'     => $expected['name'] ?? 'Result',
                        'columns'  => $expected['columns'] ?? [],
                        'rows'     => array_slice($expected['rows'] ?? [], 0, 100),
                        'rowCount' => count($expected['rows'] ?? []),
                    ];
                    if (!$tc->is_hidden && $outputsVisible) {
                        $comparisons[] = [
                            'dataset'       => $datasetIndex + 1,
                            'actualTable'   => $publicActual,
                            'expectedTable' => $publicExpected,
                            'matched'       => $correct,
                        ];
                    }
                    if ($correct) {
                        $passed++;
                    } elseif ($firstError === null) {
                        $firstError = $isSubmit
                            ? 'Dataset ' . ($datasetIndex + 1) . ': ' . $msg
                            : 'The query ran successfully, but its output differs from the expected result.';
                    }
                    $datasets[] = [
                        'dataset'      => $datasetIndex + 1,
                        'correct'      => $correct,
                        'runtimeError' => false,
                        'message'      => $correct ? 'Matched' : $msg,
                    ];
                } else {
                    $passed++;
                    $datasets[] = [
                        'dataset'      => $datasetIndex + 1,
                        'correct'      => true,
                        'runtimeError' => false,
                        'message'      => 'The query ran successfully.',
                    ];
                }
            }

            if (!$isSubmit) {
                return [
                    'status'          => 'Tabular result',
                    'message'         => $firstError ?? 'The query ran successfully.',
                    'table'           => $lastTable,
                    'passed'          => $passed,
                    'total'           => $total,
                    'executionTimeMs' => round($elapsedMs, 3),
                    'comparisons'     => $comparisons,
                ];
            }

            $status = $passed === $total ? 'Accepted' : ($passed > 0 ? 'Partial' : 'Wrong Answer');
            $score = $status === 'Accepted' ? 100 : ($status === 'Partial' ? (int) round(($passed / $total) * 100) : 0);
            $isQuestionSub = !empty($data['_questionSubmission']);

            $section = [
                'part'            => 'SQL',
                'status'          => $status,
                'message'         => $status === 'Accepted' ? 'Congratulations! Your solution passed all test cases.' : ($firstError ?? 'Wrong Answer'),
                'passed'          => $passed,
                'total'           => $total,
                'executionTimeMs' => round($elapsedMs, 3),
                'comparisons'     => $comparisons,
                'table'           => $lastTable,
            ];
            if ($isQuestionSub) {
                $section['questionId'] = $solutionId;
                $section['_questionSubmission'] = [
                    'part'       => 'SQL',
                    'questionId' => $solutionId,
                    'score'      => $score,
                ];
            }

            $subId = null;
            if (!$skipStore) {
                $subId = (string) Str::uuid();
                Submission::create([
                    'id'            => $subId,
                    'user_id'       => $user->id,
                    'problem_id'    => $problem->id,
                    'source'        => $source,
                    'context'       => $context,
                    'result'        => $status,
                    'score'         => $score,
                    'query_text'    => $query,
                    'database_type' => $databaseType,
                    'schema_name'   => $schemaName,
                    'submitted_at'  => now(),
                ]);

                WorkspaceSubmissionResult::updateOrCreate(
                    ['submission_id' => $subId],
                    ['sections' => [$section]]
                );
            }

            $activity = (in_array($source, ['Assignments', 'ExamSession', 'Assignment'], true) && $context && $context !== 'Practice')
                ? Assignment::find($context)
                : null;
            $scoreVisible = !$activity || (bool) $activity->show_scores_after_submit;

            return [
                'id'              => $subId,
                'questionId'      => $isQuestionSub ? $solutionId : null,
                'questionOnly'    => $isQuestionSub,
                'status'          => $scoreVisible ? $status : 'Submitted',
                'message'         => $scoreVisible ? $section['message'] : 'Your submission has been saved. Scores are hidden by your instructor.',
                'table'           => $scoreVisible ? $lastTable : null,
                'passed'          => $scoreVisible ? $passed : 0,
                'total'           => $total,
                'score'           => $scoreVisible ? $score : null,
                'maxScore'        => 100,
                'scoreVisible'    => $scoreVisible,
                'executionTimeMs' => round($elapsedMs, 3),
                'comparisons'     => $scoreVisible ? $comparisons : [],
                'datasets'        => $scoreVisible ? $datasets : [],
                'sections'        => $scoreVisible ? [$section] : [],
            ];
        } catch (\Throwable $e) {
            return [
                'status'  => 'Runtime Error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Run workspace section (DDL / DML / SQL / TRIGGER) or prepare question database.
     */
    public function runProblemWorkspace(Problem $problem, array $data, User $user, bool $prepareOnly = false): array
    {
        $part = strtoupper((string) ($data['part'] ?? 'SQL'));
        $solutionId = $data['solutionId'] ?? $data['solution_id'] ?? $data['questionId'] ?? $data['question_id'] ?? null;
        $context = (string) ($data['context'] ?? 'Practice');
        $schemaName = $this->sandbox()->studentSchemaName($user->id, $context, $problem->id);

        $tc = $problem->testCases()->with('scripts')->orderBy('order_index')->where('is_hidden', false)->first()
            ?? $problem->testCases()->with('scripts')->orderBy('order_index')->first();
        $script = $tc?->scripts->first();
        $schemaSql = $script?->create_script ?? '';
        $seedSql = $script?->insert_script ?? '';

        if ($part === 'SQL') {
            $solutions = $problem->sqlSolutions()->orderBy('order_index')->get();
            $target = $solutionId ? $solutions->firstWhere('id', $solutionId) : $solutions->first();
            $setupSql = is_array($target?->expected) ? (string) ($target->expected['_setupSql'] ?? '') : '';
            $result = $this->validateSolution([
                'schema'            => $schemaSql,
                'seedData'          => $seedSql,
                'setupSql'          => $setupSql,
                'prepareOnly'       => $prepareOnly,
                'referenceSolution' => (string) ($data['query'] ?? ''),
            ]);
            if ($target) {
                $result['questionId'] = $target->id;
            }
            return $result;
        }

        $exerciseRow = ProblemStageExercise::where('problem_id', $problem->id)->where('part', $part)->first();
        $questions = $exerciseRow && is_array($exerciseRow->config) ? ($exerciseRow->config['questions'] ?? []) : [];
        $stageQuestion = null;
        if (!empty($questions)) {
            foreach ($questions as $q) {
                if ($solutionId && ($q['id'] ?? null) === $solutionId) {
                    $stageQuestion = $q;
                    break;
                }
            }
            if (!$stageQuestion) {
                $stageQuestion = $questions[0];
            }
        }

        $answerScript = $part === 'DDL'
            ? (string) ($data['ddl'] ?? $data['script'] ?? '')
            : ($part === 'DML' ? (string) ($data['dml'] ?? $data['script'] ?? '') : (string) ($data['trigger'] ?? $data['script'] ?? ''));

        $result = $this->validateStage([
            'part'        => $part,
            'schema'      => $schemaSql,
            'seedData'    => $seedSql,
            'prepareOnly' => $prepareOnly,
            'schemaName'  => $schemaName,
            'persist'     => true,
            'question'    => [
                'id'        => $stageQuestion['id'] ?? 'q1',
                'name'      => $stageQuestion['name'] ?? "{$part}1.sql",
                'answer'    => $answerScript,
                'startFrom' => $stageQuestion['startFrom'] ?? $stageQuestion['start_from'] ?? ($part === 'DDL' ? 'empty' : 'provided'),
                'setupDdl'  => $stageQuestion['setupDdl'] ?? $stageQuestion['setup_ddl'] ?? '',
                'setupDml'  => $stageQuestion['setupDml'] ?? $stageQuestion['setup_dml'] ?? '',
                'probes'    => [],
            ],
        ]);

        if ($stageQuestion && isset($stageQuestion['id'])) {
            $result['questionId'] = $stageQuestion['id'];
        }

        return $result;
    }

    public function assertActivitySubmissionWindow(?Assignment $activity, User $user): void
    {
        if (!$activity) {
            return;
        }
        $isStudent = !app(StaffAccess::class)->isStaff($user);
        if (!$isStudent) {
            return;
        }
        if ($activity->opens && now()->lessThan($activity->opens)) {
            abort(403, 'Bài tập này chưa mở để nộp bài.');
        }
        if ($activity->closes && now()->greaterThan($activity->closes)) {
            abort(403, 'Đã quá hạn nộp bài.');
        }
    }

    public function resolveSqlSolutionsForGrading(Problem $problem, ?array $selection = null): \Illuminate\Support\Collection
    {
        $sols = $problem->sqlSolutions->sortBy('order_index')
            ->filter(fn ($s) => $s->enabled && trim((string) $s->query) !== '')
            ->values();

        if ($sols->isEmpty()) {
            $tc = $problem->testCases->sortBy('order_index')->first();
            $legacyQuery = trim((string) ($tc?->expected_query ?? ''));
            if ($legacyQuery !== '') {
                $sols = collect([(object) [
                    'id'          => '__base_sql__',
                    'name'        => 'query.sql',
                    'description' => '',
                    'query'       => $legacyQuery,
                    'expected'    => is_array($problem->expected) ? $problem->expected : null,
                ]]);
            }
        }

        if (is_array($selection) && isset($selection['SQL']['questionIds'])) {
            $allowed = array_flip($selection['SQL']['questionIds']);
            $sols = $sols->filter(fn ($s) => isset($allowed[$s->id]))->values();
        }

        return $sols;
    }

    public function resolveStageQuestionsForGrading(
        Problem $problem,
        string $part,
        string $schemaSql,
        string $seedSql,
        ?array $selection = null
    ): array {
        $part = strtoupper($part);
        $exRow = $problem->relationLoaded('stageExercises')
            ? $problem->stageExercises->firstWhere('part', $part)
            : ProblemStageExercise::where('problem_id', $problem->id)->where('part', $part)->first();
        $questions = $exRow && is_array($exRow->config) ? ($exRow->config['questions'] ?? []) : [];

        $selectedIds = (is_array($selection) && isset($selection[$part]['questionIds']))
            ? $selection[$part]['questionIds']
            : null;

        if ($part === 'DDL' && trim($schemaSql) !== '' && (empty($questions) || (is_array($selectedIds) && in_array('__base_ddl__', $selectedIds, true)))) {
            array_unshift($questions, [
                'id'        => '__base_ddl__',
                'name'      => 'create_tables.sql',
                'answer'    => $schemaSql,
                'startFrom' => 'empty',
                'setupDdl'  => '',
                'setupDml'  => '',
                'probes'    => [],
            ]);
        }

        if ($part === 'DML' && trim($schemaSql) !== '' && trim($seedSql) !== '' && (empty($questions) || (is_array($selectedIds) && in_array('__base_dml__', $selectedIds, true)))) {
            array_unshift($questions, [
                'id'        => '__base_dml__',
                'name'      => 'insert_data.sql',
                'answer'    => $seedSql,
                'startFrom' => 'structure',
                'setupDdl'  => '',
                'setupDml'  => '',
                'probes'    => [],
            ]);
        }

        if (is_array($selectedIds)) {
            $allowed = array_flip($selectedIds);
            $questions = array_values(array_filter($questions, fn ($q) => isset($allowed[$q['id'] ?? ''])));
        }

        return $questions;
    }

    /**
     * Submit an individual workspace question (POST /api/problems/{id}/workspace/questions/submit)
     * or full workspace (POST /api/problems/{id}/workspace/submit).
     */
    public function submitProblemWorkspaceQuestion(Problem $problem, array $data, User $user): array
    {
        $part = strtoupper((string) ($data['part'] ?? 'SQL'));
        $source = (string) ($data['source'] ?? 'Practice');
        $context = (string) ($data['context'] ?? 'Practice');

        $activity = (in_array($source, ['Assignments', 'ExamSession', 'Assignment'], true) && $context && $context !== 'Practice')
            ? Assignment::find($context)
            : null;
        if ($activity && $activity->exam_mode && !app(StaffAccess::class)->isStaff($user)) {
            abort(403, 'Use Submit & Exit to submit the complete exam.');
        }
        $this->assertActivitySubmissionWindow($activity, $user);
        $this->assertActivitySubmissionWindow($activity, $user);

        if ($activity && $activity->max_submissions !== null) {
            $officialCount = Submission::where('user_id', $user->id)
                ->where('context', $activity->id)
                ->where('problem_id', $problem->id)
                ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
                ->whereDoesntHave('workspaceResult', function ($q) {
                    $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
                })
                ->count();
            if ($officialCount >= (int) $activity->max_submissions) {
                abort(403, 'You have reached the submission limit for this problem.');
            }
        }

        if ($part === 'SQL') {
            $data['_questionSubmission'] = true;
            return $this->runOrSubmitDql($problem, $data, $user, true);
        }

        $solutionId = $data['solutionId'] ?? $data['solution_id'] ?? $data['questionId'] ?? $data['question_id'] ?? null;
        $databaseType = (string) ($data['database'] ?? 'SQL Server');

        $tc = $problem->testCases()->with('scripts')->orderBy('order_index')->where('is_hidden', false)->first()
            ?? $problem->testCases()->with('scripts')->orderBy('order_index')->first();
        $script = $tc?->scripts->first();
        $schemaSql = $script?->create_script ?? '';
        $seedSql = $script?->insert_script ?? '';

        $stageQuestion = null;
        if ($solutionId === '__base_ddl__' || $solutionId === '__base_dml__') {
            $resolvedBase = $this->resolveStageQuestionsForGrading($problem, $part, $schemaSql, $seedSql, [$part => ['questionIds' => [$solutionId]]]);
            $stageQuestion = $resolvedBase[0] ?? null;
        }
        if (!$stageQuestion) {
            $exerciseRow = ProblemStageExercise::where('problem_id', $problem->id)->where('part', $part)->first();
            $questions = $exerciseRow && is_array($exerciseRow->config) ? ($exerciseRow->config['questions'] ?? []) : [];
            foreach ($questions as $q) {
                if ($solutionId && ($q['id'] ?? null) === $solutionId) {
                    $stageQuestion = $q;
                    break;
                }
            }
            if (!$stageQuestion && !empty($questions)) {
                $stageQuestion = $questions[0];
            }
        }

        $studentScript = (string) ($data['script'] ?? ($part === 'DDL'
            ? ($data['ddl'] ?? $data['query'] ?? '')
            : ($part === 'DML' ? ($data['dml'] ?? $data['query'] ?? '') : ($data['trigger'] ?? $data['query'] ?? ''))));

        $expectedAnswer = $stageQuestion['answer'] ?? ($part === 'DDL' ? $schemaSql : ($part === 'DML' ? $seedSql : ''));
        $startFrom = $stageQuestion['startFrom'] ?? $stageQuestion['start_from'] ?? ($part === 'DDL' ? 'empty' : 'provided');
        $setupDdl = $stageQuestion['setupDdl'] ?? $stageQuestion['setup_ddl'] ?? '';
        $setupDml = $stageQuestion['setupDml'] ?? $stageQuestion['setup_dml'] ?? '';
        $probes = is_array($stageQuestion['probes'] ?? null) ? $stageQuestion['probes'] : [];

        $schemaName = $this->sandbox()->studentSchemaName($user->id, $context, $problem->id);

        $actualRun = $this->validateStage([
            'part'        => $part,
            'schema'      => $schemaSql,
            'seedData'    => $seedSql,
            'prepareOnly' => false,
            'schemaName'  => $schemaName,
            'persist'     => true,
            'question'    => [
                'id'        => $stageQuestion['id'] ?? 'q1',
                'name'      => $stageQuestion['name'] ?? "{$part}1.sql",
                'answer'    => $studentScript,
                'startFrom' => $startFrom,
                'setupDdl'  => $setupDdl,
                'setupDml'  => $setupDml,
                'probes'    => $probes,
            ],
        ]);

        if (($actualRun['status'] ?? '') === 'Error') {
            $status = 'Runtime Error';
            $passed = 0;
            $total = 1;
            $msg = $actualRun['message'] ?? 'Runtime Error';
        } else {
            $expectedRun = $this->validateStage([
                'part'        => $part,
                'schema'      => $schemaSql,
                'seedData'    => $seedSql,
                'prepareOnly' => false,
                'question'    => [
                    'id'        => $stageQuestion['id'] ?? 'q1',
                    'name'      => $stageQuestion['name'] ?? "{$part}1.sql",
                    'answer'    => $expectedAnswer,
                    'startFrom' => $startFrom,
                    'setupDdl'  => $setupDdl,
                    'setupDml'  => $setupDml,
                    'probes'    => $probes,
                ],
            ]);

            if ($part === 'DDL') {
                $matched = $this->schemasMatch($actualRun['schemaTables'] ?? [], $expectedRun['schemaTables'] ?? []);
            } elseif ($part === 'DML') {
                $changes = $this->computeDataChanges($expectedRun['tables'] ?? [], $actualRun['tables'] ?? []);
                $matched = empty($changes);
            } else {
                $matched = ($actualRun['tables'] ?? []) == ($expectedRun['tables'] ?? []);
            }

            $status = $matched ? 'Accepted' : 'Wrong Answer';
            $passed = $matched ? 1 : 0;
            $total = 1;
            $msg = $matched ? "{$part} passed all checks." : "{$part} result differs from the expected database state.";
        }

        $score = $status === 'Accepted' ? 100 : 0;
        $qid = $stageQuestion['id'] ?? $solutionId;
        $subId = (string) Str::uuid();
        Submission::create([
            'id'            => $subId,
            'user_id'       => $user->id,
            'problem_id'    => $problem->id,
            'source'        => $source,
            'context'       => $context,
            'result'        => $status,
            'score'         => $score,
            'query_text'    => $studentScript,
            'database_type' => $databaseType,
            'schema_name'   => $schemaName,
            'submitted_at'  => now(),
        ]);

        $section = [
            'part'                => $part,
            'questionId'          => $qid,
            'questionName'        => $stageQuestion['name'] ?? null,
            'status'              => $status,
            'message'             => $msg,
            'passed'              => $passed,
            'total'               => $total,
            'executionTimeMs'     => $actualRun['executionTimeMs'] ?? 0,
            'tables'              => $actualRun['tables'] ?? [],
            'schemaTables'        => $actualRun['schemaTables'] ?? [],
            '_questionSubmission' => [
                'part'       => $part,
                'questionId' => $qid,
                'score'      => $score,
            ],
        ];

        WorkspaceSubmissionResult::updateOrCreate(
            ['submission_id' => $subId],
            ['sections' => [$section]]
        );

        $scoreVisible = !$activity || (bool) $activity->show_scores_after_submit;

        return [
            'id'              => $subId,
            'questionId'      => $qid,
            'questionOnly'    => true,
            'status'          => $scoreVisible ? $status : 'Submitted',
            'message'         => $scoreVisible ? $msg : 'Your submission has been saved. Scores are hidden by your instructor.',
            'passed'          => $scoreVisible ? $passed : 0,
            'total'           => $total,
            'score'           => $scoreVisible ? $score : null,
            'maxScore'        => 100,
            'scoreVisible'    => $scoreVisible,
            'executionTimeMs' => $actualRun['executionTimeMs'] ?? 0,
            'sections'        => $scoreVisible ? [$section] : [],
        ];
    }

    /**
     * Submit complete multi-part workspace (DDL / DML / SQL / TRIGGER) and store official submission.
     */
    public function submitProblemWorkspace(Problem $problem, array $data, User $user): array
    {
        $source = (string) ($data['source'] ?? 'Practice');
        $context = (string) ($data['context'] ?? 'Practice');
        $databaseType = (string) ($data['database'] ?? 'SQL Server');
        $schemaName = $this->sandbox()->studentSchemaName($user->id, $context, $problem->id);

        $isActivity = in_array($source, ['Assignments', 'ExamSession', 'Assignment'], true)
            && $context !== '' && $context !== 'Practice';

        $activity = $isActivity ? Assignment::find($context) : null;
        $member = $activity
            ? AssignmentProblem::where('assignment_id', $activity->id)->where('problem_id', $problem->id)->first()
            : null;

        if ($activity && $activity->exam_mode && !app(StaffAccess::class)->isStaff($user)) {
            abort(403, 'Use Submit & Exit to submit the complete exam.');
        }
        $this->assertActivitySubmissionWindow($activity, $user);

        $used = null;
        $maximum = $member ? (float) $member->points : 100.0;
        if ($activity) {
            $officialCount = Submission::where('user_id', $user->id)
                ->where('context', $activity->id)
                ->where('problem_id', $problem->id)
                ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
                ->whereDoesntHave('workspaceResult', function ($q) {
                    $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
                })
                ->count();

            if ($activity->max_submissions !== null && $officialCount >= (int) $activity->max_submissions) {
                abort(403, 'You have reached the submission limit for this problem.');
            }
            $used = $officialCount + 1;
        }

        $parts = ($member && is_array($member->workspace_parts))
            ? $member->workspace_parts
            : ($problem->workspace_parts ?: ['SQL']);

        $selection = ($activity && $member)
            ? app(ExaminationService::class)->studentSelectionForProblem($user, $activity, $member)
            : null;

        $problem->loadMissing(['testCases.scripts', 'sqlSolutions', 'stageExercises']);

        $tc = $problem->testCases->sortBy('order_index')->where('is_hidden', false)->first()
            ?? $problem->testCases->sortBy('order_index')->first();
        $script = $tc?->scripts->first();
        $schemaSql = $script?->create_script ?? '';
        $seedSql = $script?->insert_script ?? '';

        $rawScripts = [
            'DDL'     => (string) ($data['ddl'] ?? ''),
            'DML'     => (string) ($data['dml'] ?? ''),
            'SQL'     => (string) ($data['query'] ?? ''),
            'TRIGGER' => (string) ($data['trigger'] ?? ''),
        ];

        $sections = [];
        $totalQuestions = 0;
        $passedQuestions = 0;
        $formattedParts = [];

        foreach ($parts as $part) {
            $part = strtoupper((string) $part);
            $rawPartScript = $rawScripts[$part] ?? '';
            $formattedParts[] = "-- {$part}\n{$rawPartScript}";

            if ($part === 'SQL') {
                $sols = $this->resolveSqlSolutionsForGrading($problem, $selection);

                $solIds = $sols->pluck('id')->all();
                $extractedSql = $this->extractQuestionScripts($rawPartScript, $solIds);

                $qResults = [];
                $partPassed = 0;
                $partTotal = max(1, $sols->count());

                foreach ($sols as $sol) {
                    $studentSql = trim((string) ($extractedSql[$sol->id] ?? ''));
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

                    $run = $this->runOrSubmitDql($problem, [
                        'query'      => $studentSql,
                        'solutionId' => $sol->id,
                        'source'     => $source,
                        'context'    => $context,
                        '_skipStore' => true,
                    ], $user, true);

                    $qPassed = (int) ($run['passed'] ?? 0);
                    $qTotal = max(1, (int) ($run['total'] ?? 1));
                    $matched = ($run['status'] ?? '') === 'Accepted'
                        && $qPassed === $qTotal
                        && ($run['total'] ?? 0) > 0;
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
                $questions = $this->resolveStageQuestionsForGrading($problem, $part, $schemaSql, $seedSql, $selection);

                $qResults = [];
                $partPassed = 0;
                $partTotal = max(1, count($questions));
                $stageIds = array_map(fn ($q) => (string) ($q['id'] ?? 'q1'), $questions);
                $extractedStage = $this->extractQuestionScripts($rawPartScript, $stageIds);

                foreach ($questions as $q) {
                    $qid = (string) ($q['id'] ?? 'q1');
                    $studentScript = trim((string) ($extractedStage[$qid] ?? ''));
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

                    $actualRun = $this->validateStage([
                        'part'        => $part,
                        'schema'      => $schemaSql,
                        'seedData'    => $seedSql,
                        'prepareOnly' => false,
                        'schemaName'  => $schemaName,
                        'persist'     => true,
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

                    $matched = false;
                    $msg = $actualRun['message'] ?? '';
                    if (($actualRun['status'] ?? '') !== 'Error') {
                        $expectedRun = $this->validateStage([
                            'part'        => $part,
                            'schema'      => $schemaSql,
                            'seedData'    => $seedSql,
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
                            $matched = $this->schemasMatch($actualRun['schemaTables'] ?? [], $expectedRun['schemaTables'] ?? []);
                        } elseif ($part === 'DML') {
                            $changes = $this->computeDataChanges($expectedRun['tables'] ?? [], $actualRun['tables'] ?? []);
                            $matched = empty($changes);
                        } else {
                            $matched = $this->triggerMatchesExpected($actualRun, $expectedRun, $probes);
                        }
                        $msg = $matched ? 'Passed all checks.' : "{$part} result differs from expected state.";
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

        $score = $totalQuestions > 0 ? round($maximum * ($passedQuestions / $totalQuestions), 2) : 0;
        $overallResult = ($totalQuestions > 0 && $passedQuestions === $totalQuestions)
            ? 'Accepted'
            : ($passedQuestions > 0 ? 'Partial' : 'Wrong Answer');
        $feedback = "Đúng {$passedQuestions}/{$totalQuestions} câu.";

        $subId = (string) Str::uuid();
        Submission::create([
            'id'            => $subId,
            'user_id'       => $user->id,
            'problem_id'    => $problem->id,
            'source'        => $source,
            'context'       => $context,
            'result'        => $overallResult,
            'score'         => $score,
            'feedback'      => $feedback,
            'query_text'    => implode("\n\n", $formattedParts),
            'database_type' => $databaseType,
            'schema_name'   => $schemaName,
            'submitted_at'  => now(),
        ]);

        WorkspaceSubmissionResult::updateOrCreate(
            ['submission_id' => $subId],
            ['sections' => $sections]
        );

        $scoreVisible = !$activity || (bool) $activity->show_scores_after_submit;

        return [
            'id'              => $subId,
            'sections'        => $scoreVisible ? $sections : [],
            'status'          => $scoreVisible ? $overallResult : 'Submitted',
            'message'         => $scoreVisible ? $feedback : 'Your submission has been saved. Scores are hidden by your instructor.',
            'score'           => $scoreVisible ? $score : null,
            'maxScore'        => $maximum,
            'scoreVisible'    => $scoreVisible,
            'submissionsUsed' => $used,
            'maxSubmissions'  => $activity ? $activity->max_submissions : null,
        ];
    }

    public function extractQuestionScripts(string $combined, array $questionIds): array
    {
        $combined = trim($combined);
        if ($combined === '') {
            return [];
        }

        $result = [];
        $parts = preg_split('/^--\s*solution:([^\r\n]+)\r?\n?/m', $combined, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) > 1) {
            for ($i = 1; $i < count($parts); $i += 2) {
                $qid = trim($parts[$i]);
                $code = trim($parts[$i + 1] ?? '');
                if ($qid !== '') {
                    $result[$qid] = $code;
                }
            }
            return $result;
        }

        if (count($questionIds) === 1) {
            $result[(string) $questionIds[0]] = $combined;
        } elseif (!empty($questionIds)) {
            $result[(string) $questionIds[0]] = $combined;
        }

        return $result;
    }

    /**
     * Compare TRIGGER execution results using probe scenarios when defined, falling back to table snapshots.
     */
    public function triggerMatchesExpected(array $actualRun, array $expectedRun, array $probes = []): bool
    {
        $expScenarios = $expectedRun['scenarios'] ?? [];
        $actScenarios = $actualRun['scenarios'] ?? [];
        if (!empty($probes) && !empty($expScenarios)) {
            foreach ($expScenarios as $idx => $es) {
                $as = $actScenarios[$idx] ?? [];
                if (empty($as['ok']) || empty($es['ok']) || ($as['changedTables'] ?? []) != ($es['changedTables'] ?? [])) {
                    return false;
                }
            }
            return true;
        }

        return ($actualRun['tables'] ?? []) == ($expectedRun['tables'] ?? []);
    }
}
