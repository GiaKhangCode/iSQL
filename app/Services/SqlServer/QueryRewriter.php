<?php

namespace App\Services\SqlServer;

class QueryRewriter
{
    /**
     * Rewrite query to prefix table names with schema name [schema_name].[table]
     */
    public function rewrite(string $query, string $schemaName, array $tables = []): string
    {
        $rewritten = $query;
        foreach ($tables as $table) {
            $pattern = '/\b' . preg_quote($table, '/') . '\b/i';
            $rewritten = preg_replace($pattern, "[{$schemaName}].[{$table}]", $rewritten);
        }
        return $rewritten;
    }
}
