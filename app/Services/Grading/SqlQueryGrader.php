<?php

namespace App\Services\Grading;

use Illuminate\Support\Facades\DB;
use Exception;

class SqlQueryGrader
{
    protected string $connection = 'sqlsrv';

    /**
     * Run student query and compare result with expected query
     */
    public function grade(string $studentQuery, string $expectedQuery): array
    {
        try {
            $studentResult = DB::connection($this->connection)->select($studentQuery);
            $expectedResult = DB::connection($this->connection)->select($expectedQuery);

            $isMatch = ($studentResult == $expectedResult);

            return [
                'passed'   => $isMatch,
                'score'    => $isMatch ? 50 : 0,
                'feedback' => $isMatch ? 'Results match expected output.' : 'Result set does not match.',
            ];
        } catch (Exception $e) {
            return [
                'passed'   => false,
                'score'    => 0,
                'feedback' => 'SQL Execution Error: ' . $e->getMessage(),
            ];
        }
    }
}
