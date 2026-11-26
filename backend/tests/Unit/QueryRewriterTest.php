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

    public function test_select_into_creates_a_known_table_so_the_target_gets_the_schema_prefix(): void
    {
        $rewriter = new QueryRewriter();

        $this->assertSame(['SANPHAM1'], $rewriter->extractTableNames('SELECT * INTO SANPHAM1 FROM SANPHAM;'));
        $this->assertSame(['Tam Moi'], $rewriter->extractTableNames('SELECT MASP INTO [Tam Moi] FROM SANPHAM'));
        // INSERT INTO ghi vào bảng có sẵn, bảng tạm (#) không thuộc schema sinh viên, chuỗi chứa chữ INTO thì bỏ qua.
        $this->assertSame([], $rewriter->extractTableNames('INSERT INTO SANPHAM1 SELECT * FROM SANPHAM'));
        $this->assertSame([], $rewriter->extractTableNames('SELECT * INTO #tmp FROM SANPHAM'));
        $this->assertSame([], $rewriter->extractTableNames("SELECT 'a INTO b' AS x FROM SANPHAM"));

        $this->assertSame(
            'SELECT * INTO [sv_x].[SANPHAM1] FROM [sv_x].[SANPHAM];',
            $rewriter->rewrite('SELECT * INTO SANPHAM1 FROM SANPHAM;', 'sv_x', ['SANPHAM'])
        );
    }
}
