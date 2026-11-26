<?php

namespace Tests\Unit;

use App\Services\ProblemWorkspaceService;
use Tests\TestCase;

/**
 * Quy tắc so sánh khi chấm tự động (hàm thuần, không đụng DB hay SQL Server):
 * DDL bỏ qua tên/thứ tự ràng buộc và (mặc định) thứ tự cột; DML bỏ qua bảng thừa; SQL có tùy chọn thứ tự dòng /
 * bỏ qua tên cột và so số theo giá trị; Trigger so cả dữ liệu sau kịch bản.
 */
class GradingComparisonTest extends TestCase
{
    private ProblemWorkspaceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ProblemWorkspaceService::class);
    }

    private function col(string $name, string $type = 'int', string $null = 'NULL'): array
    {
        return ['name' => $name, 'type' => $type, 'constraints' => $null];
    }

    private function fk(string $col, string $table): array
    {
        return ['columns' => [$col], 'referencedTable' => $table, 'referencedColumns' => [$col],
            'isDisabled' => false, 'isNotTrusted' => false, 'onDelete' => 'NO_ACTION', 'onUpdate' => 'NO_ACTION'];
    }

    // ───────────────────────── DDL ─────────────────────────

    public function test_ddl_ignores_column_order_by_default_but_not_when_asked(): void
    {
        $expected = [['name' => 'SANPHAM', 'columns' => [$this->col('MASP', 'char(5)', 'NOT NULL'), $this->col('TENSP', 'nvarchar(50)', 'NOT NULL'), $this->col('GIA')], 'primaryKeys' => ['MASP'], 'foreignKeys' => [], 'constraints' => []]];
        $swapped = [['name' => 'sanpham', 'columns' => [$this->col('MASP', 'char(5)', 'NOT NULL'), $this->col('GIA'), $this->col('TENSP', 'nvarchar(50)', 'NOT NULL')], 'primaryKeys' => ['MASP'], 'foreignKeys' => [], 'constraints' => []]];

        $this->assertTrue($this->svc->schemasMatch($swapped, $expected));
        $this->assertFalse($this->svc->schemasMatch($swapped, $expected, true));
    }

    public function test_ddl_still_checks_type_length_and_nullability(): void
    {
        $expected = [['name' => 'SP', 'columns' => [$this->col('TENSP', 'nvarchar(50)', 'NOT NULL')], 'primaryKeys' => [], 'foreignKeys' => [], 'constraints' => []]];
        $longer = [['name' => 'SP', 'columns' => [$this->col('TENSP', 'nvarchar(100)', 'NOT NULL')], 'primaryKeys' => [], 'foreignKeys' => [], 'constraints' => []]];
        $nullable = [['name' => 'SP', 'columns' => [$this->col('TENSP', 'nvarchar(50)')], 'primaryKeys' => [], 'foreignKeys' => [], 'constraints' => []]];

        $this->assertFalse($this->svc->schemasMatch($longer, $expected));
        $this->assertFalse($this->svc->schemasMatch($nullable, $expected));
    }

    public function test_ddl_constraint_names_and_creation_order_do_not_matter(): void
    {
        $base = ['name' => 'CTHD', 'columns' => [$this->col('SOHD'), $this->col('MASP')], 'primaryKeys' => ['SOHD', 'MASP']];
        $expected = [$base + ['foreignKeys' => [$this->fk('SOHD', 'HOADON'), $this->fk('MASP', 'SANPHAM')], 'constraints' => ['CHECK ([SL]>(0))|disabled:0|untrusted:0', 'UNIQUE ([SOHD])|disabled:0']]];
        $otherOrder = [$base + ['foreignKeys' => [$this->fk('MASP', 'SANPHAM'), $this->fk('SOHD', 'HOADON')], 'constraints' => ['UNIQUE ([SOHD])|disabled:0', 'CHECK ([SL]>(0))|disabled:0|untrusted:0']]];

        $this->assertTrue($this->svc->schemasMatch($otherOrder, $expected));

        $missingFk = [$base + ['foreignKeys' => [$this->fk('SOHD', 'HOADON')], 'constraints' => ['CHECK ([SL]>(0))|disabled:0|untrusted:0', 'UNIQUE ([SOHD])|disabled:0']]];
        $this->assertFalse($this->svc->schemasMatch($missingFk, $expected));
    }

    // ───────────────────────── DML ─────────────────────────

    public function test_dml_ignores_extra_tables_and_row_order(): void
    {
        $expected = [['name' => 'SANPHAM', 'columns' => ['MASP', 'GIA'], 'rows' => [['SP01', '90000'], ['SP02', '50000']]]];
        $actual = [
            ['name' => 'sanpham', 'columns' => ['MASP', 'GIA'], 'rows' => [['SP02', '50000'], ['SP01', '90000']]],
            ['name' => 'SP_TAM', 'columns' => ['MASP'], 'rows' => [['SP01']]],
        ];

        $this->assertTrue($this->svc->dataMatchesExpected($expected, $actual));
    }

    public function test_dml_fails_on_missing_table_or_different_rows(): void
    {
        $expected = [['name' => 'SANPHAM', 'columns' => ['MASP', 'GIA'], 'rows' => [['SP01', '90000']]]];

        $this->assertFalse($this->svc->dataMatchesExpected($expected, []));
        $this->assertFalse($this->svc->dataMatchesExpected($expected, [['name' => 'SANPHAM', 'columns' => ['MASP', 'GIA'], 'rows' => [['SP01', '100000']]]]));
        $this->assertFalse($this->svc->dataMatchesExpected($expected, [['name' => 'SANPHAM', 'columns' => ['MASP', 'GIA'], 'rows' => [['SP01', '90000'], ['SP03', '1']]]]));
    }

    // ───────────────────────── SQL ─────────────────────────

    public function test_sql_row_order_only_counts_when_asked(): void
    {
        $expected = ['columns' => ['TENKH', 'TONG'], 'rows' => [['An', '10'], ['Binh', '20']]];
        $reversed = ['columns' => ['tenkh', 'tong'], 'rows' => [['Binh', '20'], ['An', '10']]];

        $this->assertTrue($this->svc->compareResults($reversed, $expected)[0]);
        $this->assertFalse($this->svc->compareResults($reversed, $expected, ['checkRowOrder' => true])[0]);
        $this->assertTrue($this->svc->compareResults($expected, $expected, ['checkRowOrder' => true])[0]);
    }

    public function test_sql_column_names_can_be_ignored(): void
    {
        $expected = ['columns' => ['TENKH', 'TONGTIEN'], 'rows' => [['An', '10']]];
        $renamed = ['columns' => ['TENKH', 'TONG'], 'rows' => [['An', '10']]];
        $swapped = ['columns' => ['TONGTIEN', 'TENKH'], 'rows' => [['10', 'An']]];

        $this->assertFalse($this->svc->compareResults($renamed, $expected)[0]);
        $this->assertTrue($this->svc->compareResults($renamed, $expected, ['ignoreColumnNames' => true])[0]);
        $this->assertFalse($this->svc->compareResults($swapped, $expected, ['ignoreColumnNames' => true])[0], 'vẫn so giá trị theo vị trí cột');
    }

    public function test_sql_numbers_compare_by_value(): void
    {
        $expected = ['columns' => ['GIA'], 'rows' => [['10.5'], ['7'], [null]]];
        $actual = ['columns' => ['GIA'], 'rows' => [['10.50'], ['7.00'], [null]]];

        $this->assertTrue($this->svc->compareResults($actual, $expected)[0]);
        $this->assertFalse($this->svc->compareResults(['columns' => ['GIA'], 'rows' => [['10.51'], ['7'], [null]]], $expected)[0]);
        $this->assertFalse($this->svc->compareResults(['columns' => ['MA'], 'rows' => [['007']]], ['columns' => ['MA'], 'rows' => [['7']]])[0], 'mã có số 0 đứng đầu không bị coi là số');
    }

    public function test_top_level_order_by_detection(): void
    {
        $this->assertTrue($this->svc->hasTopLevelOrderBy('SELECT * FROM SANPHAM ORDER BY GIA DESC'));
        $this->assertTrue($this->svc->hasTopLevelOrderBy("SELECT TOP 3 * FROM SANPHAM\norder   by GIA"));
        $this->assertFalse($this->svc->hasTopLevelOrderBy('SELECT MASP, RANK() OVER (ORDER BY GIA) FROM SANPHAM'));
        $this->assertFalse($this->svc->hasTopLevelOrderBy("SELECT * FROM (SELECT TOP 5 * FROM SANPHAM ORDER BY GIA) t"));
        $this->assertFalse($this->svc->hasTopLevelOrderBy("SELECT 'ORDER BY' AS x -- ORDER BY GIA\nFROM SANPHAM"));
        $this->assertFalse($this->svc->hasTopLevelOrderBy('SELECT [order by] FROM T'));
    }

    public function test_sql_compare_options_default_to_auto_row_order(): void
    {
        $this->assertTrue($this->svc->sqlCompareOptions(null, 'SELECT * FROM T ORDER BY A')['checkRowOrder']);
        $this->assertFalse($this->svc->sqlCompareOptions(['_checkRowOrder' => false], 'SELECT * FROM T ORDER BY A')['checkRowOrder']);
        $this->assertTrue($this->svc->sqlCompareOptions(['_checkRowOrder' => true], 'SELECT * FROM T')['checkRowOrder']);
        $this->assertFalse($this->svc->sqlCompareOptions([], 'SELECT * FROM T')['checkRowOrder']);
        $this->assertTrue($this->svc->sqlCompareOptions(['_ignoreColumnNames' => true], 'SELECT 1')['ignoreColumnNames']);
    }

    // ───────────────────────── Trigger ─────────────────────────

    public function test_trigger_scenario_compares_data_not_only_changed_tables(): void
    {
        $expected = ['ok' => true, 'changedTables' => ['HOADON'], 'dataHash' => 'a'];

        $this->assertTrue($this->svc->triggerScenarioMatches(['ok' => true, 'changedTables' => ['HOADON'], 'dataHash' => 'a'], $expected));
        $this->assertFalse($this->svc->triggerScenarioMatches(['ok' => true, 'changedTables' => ['HOADON'], 'dataHash' => 'b'], $expected), 'cùng bảng nhưng sai số liệu');
        $this->assertFalse($this->svc->triggerScenarioMatches(['ok' => true, 'changedTables' => ['SANPHAM'], 'dataHash' => 'a'], $expected));
        $this->assertFalse($this->svc->triggerScenarioMatches(['ok' => false, 'changedTables' => [], 'dataHash' => 'a'], $expected));
    }

    public function test_trigger_scenario_blocked_matches_only_blocked(): void
    {
        $blocked = ['ok' => true, 'blocked' => true, 'changedTables' => [], 'dataHash' => null, 'message' => 'Không đủ tồn kho'];
        $silent = ['ok' => true, 'blocked' => true, 'changedTables' => [], 'dataHash' => 'x', 'message' => ''];
        $changed = ['ok' => true, 'blocked' => false, 'changedTables' => ['HOADON'], 'dataHash' => 'a'];

        $this->assertTrue($this->svc->triggerScenarioMatches($silent, $blocked), 'chặn bằng lỗi và chặn êm đều là "bị chặn"');
        $this->assertTrue($this->svc->triggerScenarioMatches(['ok' => true, 'blocked' => true, 'message' => 'Lỗi khác'], $blocked), 'không so nội dung lỗi');
        $this->assertFalse($this->svc->triggerScenarioMatches($changed, $blocked), 'đáp án chặn mà bài làm không chặn');
        $this->assertFalse($this->svc->triggerScenarioMatches($blocked, $changed), 'đáp án không chặn mà bài làm chặn');
    }
}
