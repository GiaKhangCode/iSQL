<?php

namespace Tests\Unit;

use App\Services\SqlServer\QueryRewriter;
use PHPUnit\Framework\TestCase;

class QueryRewriterTest extends TestCase
{
    public function test_rewrite_prepends_schema_to_tables(): void
    {
        $rewriter = new QueryRewriter();
        $query = "SELECT * FROM SinhVien WHERE id = 1";
        $result = $rewriter->rewrite($query, "student_01", ["SinhVien"]);

        $this->assertEquals("SELECT * FROM [student_01].[SinhVien] WHERE id = 1", $result);
    }
}
