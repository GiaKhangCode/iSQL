<?php

namespace App\Services\SqlServer;

use Illuminate\Support\Facades\DB;
use Exception;

class SandboxService
{
    protected string $connection = 'sqlsrv';

    /**
     * Create isolated schema for student contest sandbox
     */
    public function createSchema(string $schemaName): bool
    {
        try {
            DB::connection($this->connection)->statement("CREATE SCHEMA [{$schemaName}]");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Drop isolated schema and tables for student contest sandbox
     */
    public function dropSchema(string $schemaName): bool
    {
        try {
            DB::connection($this->connection)->statement("DROP SCHEMA IF EXISTS [{$schemaName}]");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Run DDL in specific schema
     */
    public function executeDdl(string $schemaName, string $script): void
    {
        DB::connection($this->connection)->unprepared($script);
    }
}
