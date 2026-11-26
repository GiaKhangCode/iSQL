<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\Examination;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Test đặc tả cho trang Tổng quan của admin: GET /api/admin/overview/{health,attention,exams,activities}
 * (Admin\OverviewController). Khóa JSON là camelCase vì frontend đọc thẳng (statsData.pendingRequests...).
 *
 * DB dev đã có dữ liệu sẵn nên test đo ĐỘ CHÊNH, hoặc dùng mốc thời gian nằm trong tương lai gần để
 * chắc chắn dữ liệu mẫu nằm đầu danh sách "mới nhất". Mọi thứ được rollback sau test.
 */
class AdminOverviewDashboardTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private const PARTS = ['health', 'attention', 'exams', 'activities'];

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();

        $this->admin = $this->makeUser('admin');
    }

    private function makeUser(string $kind, string $status = 'Active', ?array $permissions = null): User
    {
        $user = User::create([
            'name'            => "Overview {$kind} " . uniqid(),
            'email'           => "overview_{$kind}_" . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($kind),
            'status'          => $status,
            'initials'        => 'OV',
        ]);

        if ($permissions !== null) {
            DB::table('user_access_roles')->where('user_id', $user->id)->delete();
            $role = AccessRole::create(['name' => 'Role ' . uniqid()]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    private function attention(?User $as = null): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($as ?? $this->admin);

        return $this->getJson('/api/admin/overview/attention')->assertOk()->json();
    }

    private function makeLog(?User $user, string $action, Carbon $at): ActivityLog
    {
        return ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user?->id,
            'action'     => $action,
            'created_at' => $at,
        ]);
    }

    private function makeClass(string $status, ?string $instructorId = null): ClassModel
    {
        return ClassModel::create([
            'id'            => 'OV_' . strtoupper(uniqid()),
            'course'        => 'Lớp tổng quan',
            'term'          => 'HK thử',
            'status'        => $status,
            'instructor_id' => $instructorId,
        ]);
    }

    // ------------------------------------------------------------------ quyền

    public function test_endpoints_require_authentication_and_a_management_capability(): void
    {
        foreach (self::PARTS as $part) {
            $this->getJson("/api/admin/overview/{$part}")->assertStatus(401);
        }

        $forbidden = [$this->makeUser('instructor'), $this->makeUser('student'), $this->makeUser('faculty_office')];
        foreach ($forbidden as $user) {
            Sanctum::actingAs($user);
            foreach (self::PARTS as $part) {
                $this->getJson("/api/admin/overview/{$part}")->assertStatus(403);
            }
        }
    }

    public function test_the_overview_tab_needs_system_overview(): void
    {
        Sanctum::actingAs($this->makeUser('visitor', 'Active', ['system.overview']));
        foreach (self::PARTS as $part) {
            $this->getJson("/api/admin/overview/{$part}")->assertOk();
        }

        // Quản lý người dùng/lớp/vai trò không còn tự mở tab Tổng quan.
        foreach (['user.manage', 'class.manage', 'access.manage'] as $code) {
            Sanctum::actingAs($this->makeUser('visitor', 'Active', [$code]));
            $this->getJson('/api/admin/overview/attention')->assertStatus(403);
        }
    }

    public function test_the_old_counter_route_is_gone(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/overview')->assertStatus(404);
    }

    public function test_the_grading_result_routes_are_gone(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/overview/stats')->assertStatus(404);
        $this->getJson('/api/admin/overview/errors')->assertStatus(404);
    }

    // -------------------------------------------------------------- attention

    public function test_attention_counts_only_active_accounts_without_any_role_or_permission(): void
    {
        $before = $this->attention()['usersWithoutRole']['count'];

        $this->makeUser('visitor');                       // +1
        $this->makeUser('visitor', 'Inactive');           // đã khóa: không tính
        $this->makeUser('student');                       // có vai trò mặc định
        $direct = $this->makeUser('visitor');             // có quyền gán riêng: không tính
        DB::table('user_permissions')->insert(['user_id' => $direct->id, 'permission' => 'system.overview']);

        $after = $this->attention()['usersWithoutRole'];
        $this->assertSame(1, $after['count'] - $before);
        $this->assertLessThanOrEqual(20, count($after['names']));
    }

    public function test_attention_lists_the_active_access_managers(): void
    {
        $before = $this->attention()['accessManagers']['count'];

        $this->makeUser('visitor', 'Active', ['access.manage']);
        $this->makeUser('visitor', 'Inactive', ['access.manage']);

        $this->assertSame(1, $this->attention()['accessManagers']['count'] - $before);
    }

    public function test_unassigned_classes_are_only_reported_to_class_managers(): void
    {
        $lecturer = $this->makeUser('instructor');
        $before = $this->attention()['unassignedClasses'];

        $this->makeClass('Active');
        $this->makeClass('Archived');
        $this->makeClass('Active', $lecturer->id);

        $this->assertSame(1, $this->attention()['unassignedClasses'] - $before);
        $this->assertNull($this->attention($this->makeUser('visitor', 'Active', ['system.overview']))['unassignedClasses']);
    }

    // ------------------------------------------------------- health and exams

    public function test_health_reports_each_service(): void
    {
        Sanctum::actingAs($this->admin);

        $rows = $this->getJson('/api/admin/overview/health')->assertOk()->json();

        $this->assertSame(['mysql', 'sqlserver', 'redis'], array_column($rows, 'key'));
        $this->assertTrue($rows[0]['ok']);
        $this->assertSame(['key', 'ok', 'ms', 'detail'], array_keys($rows[0]));
    }

    public function test_exams_lists_published_sessions_running_or_opening_within_a_day(): void
    {
        $examination = Examination::create([
            'title' => 'OV exam ' . uniqid(), 'exam_date' => now()->toDateString(), 'owner_id' => $this->admin->id,
        ]);
        $make = fn (string $title, Carbon $opens, Carbon $closes, bool $published = true) => Assignment::create([
            'title' => $title, 'instructor_id' => $this->admin->id, 'examination_id' => $examination->id,
            'published' => $published, 'opens' => $opens, 'closes' => $closes,
        ]);
        $live = $make('OV live', now()->subHour(), now()->addHour());
        $soon = $make('OV soon', now()->addHours(23), now()->addHours(25));
        $make('OV later', now()->addHours(30), now()->addHours(31));
        $make('OV over', now()->subHours(3), now()->subHour());
        $make('OV draft', now()->subHour(), now()->addHour(), false);

        Sanctum::actingAs($this->admin);
        $rows = collect($this->getJson('/api/admin/overview/exams')->assertOk()->json())
            ->filter(fn ($row) => str_starts_with($row['title'], 'OV '))->values();

        $this->assertSame([$live->id, $soon->id], $rows->pluck('id')->all());
        $this->assertSame(['Live', 'Upcoming'], $rows->pluck('status')->all());
        $this->assertSame(0, $rows[0]['students']);
    }

    // ------------------------------------------------------------- activities

    public function test_activities_are_the_ten_newest_in_descending_order_with_actor_names(): void
    {
        Sanctum::actingAs($this->admin);
        $base = now()->addDays(2);
        $logs = [];
        for ($i = 0; $i < 12; $i++) {
            $logs[$i] = $this->makeLog($this->admin, "Created class OV{$i}", $base->copy()->addMinutes($i));
        }

        $rows = $this->getJson('/api/admin/overview/activities')->assertOk()->json();

        $this->assertCount(10, $rows);
        $expected = array_map(fn ($i) => $logs[$i]->id, range(11, 2));
        $this->assertSame($expected, array_column($rows, 'id'), 'mới nhất trước, chỉ 10 dòng');
        $this->assertSame(['id', 'action', 'by', 'time'], array_keys($rows[0]));
        $this->assertSame('Created class OV11', $rows[0]['action']);
        $this->assertSame($this->admin->name, $rows[0]['by']);
    }

    public function test_activity_without_a_user_is_attributed_to_system_and_time_is_utc_iso(): void
    {
        Sanctum::actingAs($this->admin);
        $at = now()->addDays(5)->startOfSecond();
        $this->makeLog(null, 'results:publish:abc', $at);

        $row = $this->getJson('/api/admin/overview/activities')->assertOk()->json('0');

        $this->assertSame('System', $row['by']);
        $this->assertStringEndsWith('Z', $row['time']);
        $this->assertTrue(Carbon::parse($row['time'])->equalTo($at), 'cùng thời điểm, theo UTC');
    }

    public function test_activities_with_equal_timestamps_are_still_returned_deterministically(): void
    {
        Sanctum::actingAs($this->admin);
        $at = now()->addDays(6)->startOfSecond();
        for ($i = 0; $i < 3; $i++) {
            $this->makeLog($this->admin, "Updated class TIE{$i}", $at);
        }

        $first = $this->getJson('/api/admin/overview/activities')->assertOk()->json();
        $second = $this->getJson('/api/admin/overview/activities')->assertOk()->json();

        $this->assertSame(array_column($first, 'id'), array_column($second, 'id'));
        $this->assertCount(3, array_filter($first, fn ($row) => str_starts_with($row['action'], 'Updated class TIE')));
    }
}
