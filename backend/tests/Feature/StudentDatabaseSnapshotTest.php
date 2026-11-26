<?php

namespace Tests\Feature;

use App\Models\Problem;
use App\Models\User;
use App\Services\SqlServer\SandboxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * POST /api/problems/{id}/workspace/current: đọc lại cấu trúc và dữ liệu đang có trong schema riêng của sinh viên
 * (nút "Hiện tại" của workspace). Cần SQL Server sandbox (sqlsrv) truy cập được.
 */
class StudentDatabaseSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private User $student;
    private Problem $problem;
    private string $schema = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = User::create([
            'name'            => 'Snap ' . uniqid(),
            'email'           => 'snap_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for('student'),
            'status'          => 'Active',
            'initials'        => 'SN',
        ]);
        $this->problem = Problem::create([
            'number' => 'SNP-' . strtoupper(substr(uniqid(), -8)), 'title' => 'Snapshot ' . uniqid(), 'difficulty' => 'Easy',
            'description' => 'Mô tả', 'requirements' => 'Yêu cầu', 'practice_listed' => true, 'creator_id' => $this->student->id,
        ]);
        $this->schema = app(SandboxService::class)->studentSchemaName($this->student->id, 'Practice', $this->problem->id);
        Sanctum::actingAs($this->student);
    }

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            app(SandboxService::class)->dropSchema($this->schema);
            app(SandboxService::class)->dropSchema($this->schema . '_x');
        }
        parent::tearDown();
    }

    private function current(array $body = ['context' => 'Practice'])
    {
        return $this->postJson("/api/problems/{$this->problem->id}/workspace/current", $body);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders([])->postJson("/api/problems/{$this->problem->id}/workspace/current", [])->assertStatus(401);
    }

    public function test_no_schema_yet_returns_an_empty_database_without_creating_one(): void
    {
        $this->current()->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('schemaTables', [])
            ->assertJsonPath('tables', []);

        $pdo = DB::connection('sqlsrv')->getPdo();
        $this->assertFalse(app(SandboxService::class)->schemaExists($pdo, $this->schema), 'đọc lại không được tạo schema');
    }

    public function test_returns_the_structure_and_rows_currently_in_the_student_schema(): void
    {
        $pdo = DB::connection('sqlsrv')->getPdo();
        $sandbox = app(SandboxService::class);
        $sandbox->ensureSchema($pdo, $this->schema);
        $pdo->exec("CREATE TABLE [{$this->schema}].[SinhVien] (id INT PRIMARY KEY, ten NVARCHAR(50) NOT NULL)");
        $pdo->exec("INSERT INTO [{$this->schema}].[SinhVien] VALUES (1, N'An'), (2, N'Bình')");

        $res = $this->current()->assertOk()->assertJsonPath('status', 'Success');
        $this->assertSame(['SinhVien'], array_column($res->json('schemaTables'), 'name'));
        $this->assertSame(['id', 'ten'], array_column($res->json('schemaTables.0.columns'), 'name'));
        $this->assertSame('SinhVien', $res->json('tables.0.name'));
        $this->assertCount(2, $res->json('tables.0.rows'));

        // Đọc lại thật: dữ liệu đổi giữa hai lần gọi thì kết quả đổi theo (không phải ảnh chụp cũ).
        $pdo->exec("INSERT INTO [{$this->schema}].[SinhVien] VALUES (3, N'Chi')");
        $this->assertCount(3, $this->current()->assertOk()->json('tables.0.rows'));
    }

    public function test_each_student_only_reads_their_own_schema(): void
    {
        $pdo = DB::connection('sqlsrv')->getPdo();
        $sandbox = app(SandboxService::class);
        $sandbox->ensureSchema($pdo, $this->schema);
        $pdo->exec("CREATE TABLE [{$this->schema}].[Rieng] (id INT)");

        $other = User::create([
            'name' => 'Snap2 ' . uniqid(), 'email' => 'snap2_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'), ...AccountFixture::for('student'), 'status' => 'Active', 'initials' => 'S2',
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other);

        $this->current()->assertOk()->assertJsonPath('schemaTables', []);
    }

    public function test_ddl_runs_accumulate_and_reset_starts_over(): void
    {
        $run = fn (string $sql) => $this->postJson("/api/problems/{$this->problem->id}/workspace/run", [
            'part' => 'DDL', 'context' => 'Practice', 'script' => $sql, 'ddl' => $sql,
        ]);
        $names = fn () => array_column($this->current()->assertOk()->json('schemaTables'), 'name');

        $run('CREATE TABLE T1 (id INT PRIMARY KEY);')->assertOk()->assertJsonPath('status', 'Success');
        $run('CREATE TABLE T2 (id INT PRIMARY KEY);')->assertOk()->assertJsonPath('status', 'Success');
        $this->assertEqualsCanonicalizing(['T1', 'T2'], $names(), 'chạy lệnh 2 không làm mất bảng của lệnh 1');

        $this->postJson("/api/problems/{$this->problem->id}/workspace/reset", ['context' => 'Practice'])
            ->assertOk()->assertJsonPath('status', 'Success')->assertJsonPath('schemaTables', []);
        $this->assertSame([], $names());

        $run('CREATE TABLE T3 (id INT PRIMARY KEY);')->assertOk()->assertJsonPath('status', 'Success');
        $this->assertSame(['T3'], $names());
    }

    public function test_previewing_the_starting_database_does_not_touch_the_student_schema(): void
    {
        $this->postJson("/api/problems/{$this->problem->id}/workspace/run", [
            'part' => 'DDL', 'context' => 'Practice', 'script' => 'CREATE TABLE Giu (id INT);', 'ddl' => 'CREATE TABLE Giu (id INT);',
        ])->assertOk();

        $this->postJson("/api/problems/{$this->problem->id}/workspace/database", ['part' => 'DDL', 'context' => 'Practice'])->assertOk();

        $this->assertSame(['Giu'], array_column($this->current()->assertOk()->json('schemaTables'), 'name'));
    }
}
