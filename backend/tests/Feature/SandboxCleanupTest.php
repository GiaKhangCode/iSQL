<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\SqlServer\SandboxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Dọn schema bài làm trên SQL Server. SandboxService được GIẢ LẬP (chỉ studentSchemaName chạy thật): test không bao
 * giờ đụng tới schema thật trên máy dev.
 */
class SandboxCleanupTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private User $admin;
    private User $student;
    private Problem $problem;
    private ClassModel $class;
    private Assignment $assignment;
    /** @var array<string, int> schema "trên SQL Server" => số đối tượng */
    private array $schemas = [];
    /** @var string[] */
    private array $removed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        $this->admin = $this->makeUser('visitor', ['sandbox.cleanup']);
        $this->student = $this->makeUser('student');
        $this->problem = Problem::create([
            'number' => 'SB-' . strtoupper(substr(uniqid(), -8)), 'title' => 'SB problem', 'difficulty' => 'Easy',
            'description' => 'd', 'requirements' => 'r', 'practice_listed' => false, 'creator_id' => $this->admin->id,
        ]);
        $this->class = ClassModel::create([
            'id' => 'SB_' . strtoupper(uniqid()), 'course' => 'SB course', 'term' => 'SB term', 'status' => 'Active',
        ]);
        $this->class->students()->attach($this->student->id, ['role' => 'Member', 'joined_at' => now()]);
        // Đóng mọi hoạt động đang mở của database dev trong phạm vi giao dịch của test.
        Assignment::where('published', true)->update(['published' => false]);
        $this->assignment = Assignment::create([
            'title' => 'SB assignment', 'instructor_id' => $this->admin->id, 'published' => true,
            'opens' => now()->subDays(2), 'closes' => now()->subDay(),
        ]);
        $this->assignment->problems()->attach($this->problem->id, ['points' => 10, 'order_index' => 1]);
        DB::table('assignment_classes')->insert(['id' => (string) Str::uuid(), 'assignment_id' => $this->assignment->id, 'class_id' => $this->class->id]);

        $this->fakeSandbox();
    }

    private function fakeSandbox(): void
    {
        $fake = $this->partialMock(SandboxService::class);
        $fake->shouldReceive('listSandboxSchemas')->andReturnUsing(fn () => $this->schemas);
        $fake->shouldReceive('removeSandboxSchema')->andReturnUsing(function (string $name) {
            $this->removed[] = $name;
            unset($this->schemas[$name]);

            return true;
        });
    }

    private function makeUser(string $kind, ?array $permissions = null): User
    {
        $user = User::create([
            'name' => 'SB ' . uniqid(), 'email' => 'sb_' . uniqid() . '@uit.edu.vn', 'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($kind), 'status' => 'Active', 'initials' => 'SB',
        ]);
        if ($permissions !== null) {
            DB::table('user_access_roles')->where('user_id', $user->id)->delete();
            $role = AccessRole::create(['name' => 'SB role ' . uniqid()]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    private function schemaFor(string $context, ?User $user = null): string
    {
        return (new SandboxService())->studentSchemaName(($user ?? $this->student)->id, $context, $this->problem->id);
    }

    private function cleanup(array $body, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($as ?? $this->admin);

        return $this->postJson('/api/admin/sandbox/cleanup', $body);
    }

    public function test_only_holders_of_the_cleanup_permission_may_look_or_clean(): void
    {
        Sanctum::actingAs($this->makeUser('visitor', ['system.overview', 'class.manage']));

        $this->getJson('/api/admin/sandbox')->assertForbidden();
        $this->postJson('/api/admin/sandbox/cleanup', ['target' => 'orphans', 'confirm' => 'DON'])->assertForbidden();
        $this->assertSame([], $this->removed);
    }

    public function test_cleaning_a_class_removes_only_its_assigned_work(): void
    {
        $mine = $this->schemaFor($this->assignment->id);
        $practice = $this->schemaFor('Practice');
        $outsider = $this->schemaFor($this->assignment->id, $this->makeUser('student'));
        $this->schemas = [$mine => 3, $practice => 1, $outsider => 1, $mine . '_x' => 0, 'dbo_like' => 4];

        Sanctum::actingAs($this->admin);
        $summary = $this->getJson('/api/admin/sandbox')->assertOk()->json();
        $row = collect($summary['classes'])->firstWhere('id', $this->class->id);
        $this->assertSame(1, $row['schemas']);
        $this->assertSame(['student' => 3, 'orphan' => 0, 'temporary' => 1], $summary['totals']);

        $this->cleanup(['target' => 'class', 'classId' => $this->class->id, 'confirm' => $this->class->id])
            ->assertOk()->assertExactJson(['deleted' => 1, 'failed' => 0, 'remaining' => 0]);

        $this->assertSame([$mine], $this->removed);
        $this->assertDatabaseHas('activity_logs', ['action' => "sandbox:clean_class:{$this->class->id}:count=1"]);
    }

    public function test_cleaning_orphans_keeps_attributable_work_and_foreign_schemas(): void
    {
        $mine = $this->schemaFor($this->assignment->id);
        $practice = $this->schemaFor('Practice');
        $orphan = 'sv_' . str_repeat('a', 24);
        $scratch = 'sv_' . str_repeat('b', 24) . '_x';
        $expected = 'exp_' . str_repeat('c', 20);
        $this->schemas = [$mine => 1, $practice => 1, $orphan => 2, $scratch => 0, $expected => 0, 'sv_manual' => 1, 'reporting' => 1];

        $this->cleanup(['target' => 'orphans', 'confirm' => 'DON'])->assertOk()->assertJsonPath('deleted', 3);

        $this->assertEqualsCanonicalizing([$orphan, $scratch, $expected], $this->removed);
    }

    public function test_confirmation_must_match(): void
    {
        $this->schemas = [$this->schemaFor($this->assignment->id) => 1];

        $this->cleanup(['target' => 'class', 'classId' => $this->class->id, 'confirm' => 'sai'])->assertStatus(422);
        $this->cleanup(['target' => 'orphans', 'confirm' => 'don'])->assertStatus(422);
        $this->cleanup(['target' => 'class', 'confirm' => 'x'])->assertStatus(422);
        $this->cleanup(['target' => 'class', 'classId' => 'khong-co', 'confirm' => 'khong-co'])->assertNotFound();
        $this->assertSame([], $this->removed);
    }

    public function test_nothing_is_removed_while_an_activity_is_open(): void
    {
        $this->schemas = [$this->schemaFor($this->assignment->id) => 1, 'sv_' . str_repeat('a', 24) => 1];
        $this->assignment->update(['closes' => now()->addDay()]);

        $this->cleanup(['target' => 'class', 'classId' => $this->class->id, 'confirm' => $this->class->id])->assertStatus(409);
        $this->cleanup(['target' => 'orphans', 'confirm' => 'DON'])->assertStatus(409);
        $this->assertSame([], $this->removed);

        // Chưa đến giờ mở thì không tính là đang mở.
        $this->assignment->update(['opens' => now()->addHour()]);
        $this->cleanup(['target' => 'orphans', 'confirm' => 'DON'])->assertOk()->assertJsonPath('deleted', 1);
    }

    public function test_large_cleanups_are_done_in_batches(): void
    {
        for ($i = 0; $i < 130; $i++) {
            $this->schemas['sv_' . str_pad(dechex($i), 24, '0', STR_PAD_LEFT) . '_x'] = 0;
        }

        $this->cleanup(['target' => 'orphans', 'confirm' => 'DON'])->assertOk()
            ->assertExactJson(['deleted' => 100, 'failed' => 0, 'remaining' => 30]);
        $this->cleanup(['target' => 'orphans', 'confirm' => 'DON'])->assertOk()->assertJsonPath('remaining', 0);
    }
}
