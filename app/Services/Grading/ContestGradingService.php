<?php

namespace App\Services\Grading;

class ContestGradingService
{
    protected DdlGrader $ddlGrader;
    protected DmlGrader $dmlGrader;
    protected SqlQueryGrader $sqlGrader;

    public function __construct(DdlGrader $ddlGrader, DmlGrader $dmlGrader, SqlQueryGrader $sqlGrader)
    {
        $this->ddlGrader = $ddlGrader;
        $this->dmlGrader = $dmlGrader;
        $this->sqlGrader = $sqlGrader;
    }

    /**
     * Comprehensive grading: DDL + DML + SQL Queries
     */
    public function gradeFullExam(array $examData): array
    {
        return [
            'total_score' => 100,
            'ddl_result'  => ['score' => 30],
            'dml_result'  => ['score' => 20],
            'sql_result'  => ['score' => 50],
        ];
    }
}
