<?php

namespace Tests\Unit;

use App\Services\Grading\SqlQueryGrader;
use PHPUnit\Framework\TestCase;

class SqlQueryGraderTest extends TestCase
{
    public function test_sql_query_grader_instantiation(): void
    {
        $grader = new SqlQueryGrader();
        $this->assertInstanceOf(SqlQueryGrader::class, $grader);
    }
}
