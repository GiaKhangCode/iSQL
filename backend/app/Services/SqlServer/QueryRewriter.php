<?php

namespace App\Services\SqlServer;

class QueryRewriter
{
    private const TABLE_KEYWORDS = [
        'TABLE',
        'INTO',
        'FROM',
        'JOIN',
        'REFERENCES',
        'UPDATE',
        'USING',
        'ON',
    ];

    private const FROM_TERMINATORS = [
        'WHERE',
        'GROUP',
        'ORDER',
        'HAVING',
        'UNION',
        'INTERSECT',
        'EXCEPT',
        'SET',
        'VALUES',
        'SELECT',
        'OPTION',
        'FOR',
    ];

    /**
     * Extract table names declared via CREATE TABLE in a SQL script.
     */
    public function extractTableNames(string $sql): array
    {
        $names = [];
        $stripped = $this->stripStringsAndComments($sql);
        if (preg_match_all('/\bCREATE\s+TABLE\s+(?:(?:\[?dbo\]?|\[?[a-zA-Z_][a-zA-Z0-9_]*\]?)\s*\.\s*)?(?:\[([^\]]+)\]|([a-zA-Z_#][a-zA-Z0-9_]*))/i', $stripped, $matches)) {
            foreach ($matches[1] as $i => $bracketed) {
                $name = trim($bracketed !== '' ? $bracketed : ($matches[2][$i] ?? ''));
                if ($name !== '' && !str_starts_with($name, '#')) {
                    $names[$name] = true;
                }
            }
        }
        return array_keys($names);
    }

    /**
     * Rewrite SQL to target a specific SQL Server schema [schema_name].[table].
     */
    public function rewrite(string $query, string $schemaName, array $tables = []): string
    {
        if (trim($query) === '' || $schemaName === '' || strcasecmp($schemaName, 'dbo') === 0) {
            return $query;
        }

        $allTables = array_values(array_unique(array_filter(
            array_merge($tables, $this->extractTableNames($query)),
            fn ($t) => is_string($t) && $t !== '' && !str_starts_with($t, '#')
        )));

        $tableLookup = [];
        foreach ($allTables as $t) {
            $tableLookup[strtolower($t)] = $t;
        }

        $tokens = $this->tokenize($query);
        $count = count($tokens);
        $out = '';

        $prevSigIdx = -1;
        $prevPrevSigIdx = -1;
        $inFromList = false;

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            $type = $tok['type'];
            $val = $tok['val'];

            // Preserve whitespace and comments verbatim
            if ($type === 'ws' || $type === 'comment') {
                $out .= $val;
                continue;
            }

            // Preserve string literals, except OBJECT_ID('table')
            if ($type === 'string') {
                if ($prevSigIdx >= 0 && $tokens[$prevSigIdx]['val'] === '(' && $prevPrevSigIdx >= 0 && strcasecmp($tokens[$prevPrevSigIdx]['val'], 'OBJECT_ID') === 0) {
                    $inner = substr($val, str_starts_with(strtoupper($val), "N'") ? 2 : 1, -1);
                    $innerClean = preg_replace('/^\[?dbo\]?\./i', '', trim($inner));
                    $innerUnbracket = trim($innerClean, '[]');
                    if (isset($tableLookup[strtolower($innerUnbracket)])) {
                        $canon = $tableLookup[strtolower($innerUnbracket)];
                        $prefix = str_starts_with(strtoupper($val), "N'") ? "N'" : "'";
                        $out .= "{$prefix}[{$schemaName}].[{$canon}]'";
                        $prevPrevSigIdx = $prevSigIdx;
                        $prevSigIdx = $i;
                        continue;
                    }
                }
                $out .= $val;
                $prevPrevSigIdx = $prevSigIdx;
                $prevSigIdx = $i;
                continue;
            }

            // Find next significant token
            $nextSigIdx = $this->nextSignificantIndex($tokens, $i + 1);
            $nextVal = $nextSigIdx >= 0 ? $tokens[$nextSigIdx]['val'] : '';
            $prevVal = $prevSigIdx >= 0 ? $tokens[$prevSigIdx]['val'] : '';
            $prevPrevVal = $prevPrevSigIdx >= 0 ? $tokens[$prevPrevSigIdx]['val'] : '';
            $upper = strtoupper($val);

            // Track FROM comma-separated table lists
            if ($upper === 'FROM') {
                $inFromList = true;
            } elseif (in_array($upper, self::FROM_TERMINATORS, true) || $val === ';' || $val === ')') {
                $inFromList = false;
            }

            // 1. Replace [dbo]. or dbo. with [schemaName].
            if (($upper === 'DBO' || $upper === '[DBO]') && $nextVal === '.') {
                $out .= "[{$schemaName}]";
                $prevPrevSigIdx = $prevSigIdx;
                $prevSigIdx = $i;
                continue;
            }

            // 2. Rewrite trigger name in CREATE/ALTER/DROP TRIGGER [IF EXISTS] <trig>
            if ($type === 'ident' && $prevVal !== '.' && $nextVal !== '.') {
                $isTriggerName = (strcasecmp($prevVal, 'TRIGGER') === 0)
                    || (strcasecmp($prevVal, 'EXISTS') === 0 && strcasecmp($prevPrevVal, 'IF') === 0 && $this->hasPrecedingKeyword($tokens, $prevPrevSigIdx, 'TRIGGER'));
                if ($isTriggerName) {
                    $cleanIdent = trim($val, '[]');
                    $out .= "[{$schemaName}].[{$cleanIdent}]";
                    $prevPrevSigIdx = $prevSigIdx;
                    $prevSigIdx = $i;
                    continue;
                }
            }

            // 3. Rewrite table name in table positions
            if ($type === 'ident' && $prevVal !== '.' && $nextVal !== '.') {
                $cleanIdent = trim($val, '[]');
                $lowerIdent = strtolower($cleanIdent);

                if (isset($tableLookup[$lowerIdent])) {
                    $prevUpper = strtoupper($prevVal);
                    $allowsParen = in_array($prevUpper, ['TABLE', 'INTO', 'REFERENCES'], true);
                    if ($nextVal !== '(' || $allowsParen) {
                        $isTablePosition = in_array($prevUpper, self::TABLE_KEYWORDS, true)
                            || $prevUpper === 'DELETE'
                            || ($prevUpper === 'EXISTS' && strtoupper($prevPrevVal) === 'IF')
                            || ($prevUpper === 'FROM' || ($inFromList && $prevVal === ','));

                        // Special case: UPDATE <alias> SET ... FROM [Table] <alias>
                        // Only treat identifier after UPDATE as table if it's in $tableLookup AND not followed by '.'
                        if ($isTablePosition) {
                            $canon = $tableLookup[$lowerIdent];
                            $out .= "[{$schemaName}].[{$canon}]";
                            $prevPrevSigIdx = $prevSigIdx;
                            $prevSigIdx = $i;
                            continue;
                        }
                    }
                }
            }

            $out .= $val;
            $prevPrevSigIdx = $prevSigIdx;
            $prevSigIdx = $i;
        }

        return $out;
    }

    private function nextSignificantIndex(array $tokens, int $start): int
    {
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            if ($tokens[$i]['type'] !== 'ws' && $tokens[$i]['type'] !== 'comment') {
                return $i;
            }
        }
        return -1;
    }

    private function hasPrecedingKeyword(array $tokens, int $idx, string $keyword): bool
    {
        for ($i = $idx - 1; $i >= 0; $i--) {
            if ($tokens[$i]['type'] === 'ws' || $tokens[$i]['type'] === 'comment') {
                continue;
            }
            return strcasecmp($tokens[$i]['val'], $keyword) === 0;
        }
        return false;
    }

    private function stripStringsAndComments(string $sql): string
    {
        $tokens = $this->tokenize($sql);
        $out = '';
        foreach ($tokens as $tok) {
            if ($tok['type'] === 'string' || $tok['type'] === 'comment') {
                $out .= ' ';
            } else {
                $out .= $tok['val'];
            }
        }
        return $out;
    }

    private function tokenize(string $sql): array
    {
        $tokens = [];
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            // Whitespace
            if (ctype_space($ch)) {
                $start = $i;
                while ($i < $len && ctype_space($sql[$i])) {
                    $i++;
                }
                $tokens[] = ['type' => 'ws', 'val' => substr($sql, $start, $i - $start)];
                continue;
            }

            // Line comment --
            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                $start = $i;
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl + 1;
                $tokens[] = ['type' => 'comment', 'val' => substr($sql, $start, $i - $start)];
                continue;
            }

            // Block comment /* ... */
            if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $start = $i;
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $tokens[] = ['type' => 'comment', 'val' => substr($sql, $start, $i - $start)];
                continue;
            }

            // String literal N'...' or '...'
            if ($ch === "'" || (($ch === 'N' || $ch === 'n') && $i + 1 < $len && $sql[$i + 1] === "'")) {
                $start = $i;
                $i += ($ch === "'" ? 1 : 2);
                while ($i < $len) {
                    if ($sql[$i] === "'") {
                        if ($i + 1 < $len && $sql[$i + 1] === "'") {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $tokens[] = ['type' => 'string', 'val' => substr($sql, $start, $i - $start)];
                continue;
            }

            // Bracketed identifier [...]
            if ($ch === '[') {
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === ']') {
                        if ($i + 1 < $len && $sql[$i + 1] === ']') {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $tokens[] = ['type' => 'ident', 'val' => substr($sql, $start, $i - $start)];
                continue;
            }

            // Unbracketed identifier / keyword
            if (ctype_alpha($ch) || $ch === '_' || $ch === '#' || $ch === '@') {
                $start = $i;
                $i++;
                while ($i < $len && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$')) {
                    $i++;
                }
                $tokens[] = ['type' => 'ident', 'val' => substr($sql, $start, $i - $start)];
                continue;
            }

            // Numbers
            if (ctype_digit($ch)) {
                $start = $i;
                while ($i < $len && (ctype_digit($sql[$i]) || $sql[$i] === '.')) {
                    $i++;
                }
                $tokens[] = ['type' => 'num', 'val' => substr($sql, $start, $i - $start)];
                continue;
            }

            // Punctuation / operators
            $tokens[] = ['type' => 'sym', 'val' => $ch];
            $i++;
        }

        return $tokens;
    }
}
