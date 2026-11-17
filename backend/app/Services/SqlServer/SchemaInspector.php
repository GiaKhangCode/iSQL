<?php

namespace App\Services\SqlServer;

use Illuminate\Support\Facades\DB;

class SchemaInspector
{
    protected string $connection = 'sqlsrv';

    /**
     * Inspect tables, columns, keys for schema from sys.tables, sys.columns
     */
    public function inspect(string $schemaName): array
    {
        $sql = "
            SELECT t.name AS table_name, c.name AS column_name, ty.name AS data_type, c.max_length, c.is_nullable
            FROM sys.tables t
            INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
            INNER JOIN sys.columns c ON t.object_id = c.object_id
            INNER JOIN sys.types ty ON c.user_type_id = ty.user_type_id
            WHERE s.name = ?
            ORDER BY t.name, c.column_id
        ";

        return DB::connection($this->connection)->select($sql, [$schemaName]);
    }
}
