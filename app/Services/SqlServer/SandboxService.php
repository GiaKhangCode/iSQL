<?php

namespace App\Services\SqlServer;

use Exception;
use Illuminate\Support\Facades\DB;

class SandboxService
{
    protected string $connection = 'sqlsrv';

    /**
     * Generate a deterministic, SQL-Server-safe schema name for a student's work
     * on a given problem within a context (Assignment, Contest, or Practice).
     */
    public function studentSchemaName(string $userId, string $context, string $problemId): string
    {
        $ctx = $context !== '' ? $context : 'Practice';
        return 'sv_' . substr(md5("{$userId}:{$ctx}:{$problemId}"), 0, 24);
    }

    public function sanitizeSchemaName(string $schemaName): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $schemaName) ?? '';
        if ($clean === '') {
            throw new \InvalidArgumentException('Invalid schema name.');
        }
        return substr($clean, 0, 128);
    }

    /**
     * Ensure the given schema exists in SQL Server.
     */
    public function ensureSchema(\PDO $pdo, string $schemaName): void
    {
        $safe = $this->sanitizeSchemaName($schemaName);
        $stmt = $pdo->prepare('SELECT 1 FROM sys.schemas WHERE name = ?');
        $stmt->execute([$safe]);
        if (!$stmt->fetchColumn()) {
            $pdo->exec("CREATE SCHEMA [{$safe}]");
        }
    }

    /**
     * Drop all triggers, foreign keys, views/routines, and tables inside a schema
     * without dropping the schema itself.
     */
    public function cleanSchema(\PDO $pdo, string $schemaName): void
    {
        $safe = $this->sanitizeSchemaName($schemaName);
        $stmt = $pdo->prepare('SELECT schema_id FROM sys.schemas WHERE name = ?');
        $stmt->execute([$safe]);
        if (!$stmt->fetchColumn()) {
            return;
        }

        // 1. Drop all triggers on tables in this schema
        $trStmt = $pdo->prepare(
            "SELECT tr.name FROM sys.triggers tr
             JOIN sys.tables t ON t.object_id = tr.parent_id
             WHERE SCHEMA_NAME(t.schema_id) = ? AND t.is_ms_shipped = 0"
        );
        $trStmt->execute([$safe]);
        foreach ($trStmt->fetchAll(\PDO::FETCH_COLUMN) as $trig) {
            $qTrig = str_replace(']', ']]', $trig);
            $pdo->exec("DROP TRIGGER IF EXISTS [{$safe}].[{$qTrig}]");
        }

        // 2. Drop all foreign keys on tables in this schema (or referencing tables in this schema)
        $fkStmt = $pdo->prepare(
            "SELECT SCHEMA_NAME(t.schema_id) AS schema_name, t.name AS table_name, fk.name AS fk_name
             FROM sys.foreign_keys fk
             JOIN sys.tables t ON t.object_id = fk.parent_object_id
             JOIN sys.tables rt ON rt.object_id = fk.referenced_object_id
             WHERE (SCHEMA_NAME(t.schema_id) = ? OR SCHEMA_NAME(rt.schema_id) = ?)
               AND t.is_ms_shipped = 0"
        );
        $fkStmt->execute([$safe, $safe]);
        foreach ($fkStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sName = str_replace(']', ']]', $row['schema_name']);
            $tName = str_replace(']', ']]', $row['table_name']);
            $fkName = str_replace(']', ']]', $row['fk_name']);
            $pdo->exec("ALTER TABLE [{$sName}].[{$tName}] DROP CONSTRAINT IF EXISTS [{$fkName}]");
        }

        // 3. Drop views, procedures, functions in this schema
        $objStmt = $pdo->prepare(
            "SELECT name, type FROM sys.objects
             WHERE SCHEMA_NAME(schema_id) = ? AND type IN ('V', 'P', 'FN', 'IF', 'TF') AND is_ms_shipped = 0"
        );
        $objStmt->execute([$safe]);
        foreach ($objStmt->fetchAll(\PDO::FETCH_ASSOC) as $obj) {
            $qName = str_replace(']', ']]', $obj['name']);
            $type = trim((string) $obj['type']);
            if ($type === 'V') {
                $pdo->exec("DROP VIEW IF EXISTS [{$safe}].[{$qName}]");
            } elseif ($type === 'P') {
                $pdo->exec("DROP PROCEDURE IF EXISTS [{$safe}].[{$qName}]");
            } else {
                $pdo->exec("DROP FUNCTION IF EXISTS [{$safe}].[{$qName}]");
            }
        }

        // 4. Drop all user tables in this schema
        $tblStmt = $pdo->prepare(
            "SELECT name FROM sys.tables WHERE SCHEMA_NAME(schema_id) = ? AND is_ms_shipped = 0"
        );
        $tblStmt->execute([$safe]);
        foreach ($tblStmt->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $qTable = str_replace(']', ']]', $table);
            $pdo->exec("DROP TABLE IF EXISTS [{$safe}].[{$qTable}]");
        }
    }

    /**
     * List user table names currently existing in the given schema.
     */
    public function listTables(\PDO $pdo, string $schemaName): array
    {
        $safe = $this->sanitizeSchemaName($schemaName);
        $stmt = $pdo->prepare(
            "SELECT name FROM sys.tables WHERE SCHEMA_NAME(schema_id) = ? AND is_ms_shipped = 0 ORDER BY name"
        );
        $stmt->execute([$safe]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Create isolated schema for student sandbox.
     */
    public function createSchema(string $schemaName): bool
    {
        try {
            /** @var \PDO $pdo */
            $pdo = DB::connection($this->connection)->getPdo();
            $this->ensureSchema($pdo, $schemaName);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Drop isolated schema and all its objects.
     */
    public function dropSchema(string $schemaName): bool
    {
        try {
            /** @var \PDO $pdo */
            $pdo = DB::connection($this->connection)->getPdo();
            $safe = $this->sanitizeSchemaName($schemaName);
            $this->cleanSchema($pdo, $safe);
            $pdo->exec("DROP SCHEMA IF EXISTS [{$safe}]");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Run DDL in specific schema.
     */
    public function executeDdl(string $schemaName, string $script): void
    {
        DB::connection($this->connection)->unprepared($script);
    }
}
