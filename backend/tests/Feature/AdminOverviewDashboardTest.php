<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\ActivityLog;
use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\Submission;
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
 * Test đặc tả cho trang Tổng quan của admin: GET /api/admin/overview/{stats,activities,errors}
 * (Admin\OverviewController). Khóa JSON là camelCase vì frontend đọc thẳng (statsData.pendingRequests...).
 *
 * DB dev đã có dữ liệu sẵn nên test đo ĐỘ CHÊNH, hoặc dùng mốc thời gian nằm trong tương lai gần để
 * chắc chắn dữ liệu mẫu nằm đầu danh sách "mới nhất". Mọi thứ được rollback sau test.
 */
class AdminOverviewDashboardTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private const STATS_KEYS = ['users', 'classes', 'pendingRequests', 'unassignedClasses', 'submissionsToday', 'gradingErrors'];

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
            $role = AccessRole::create(['name' => 'Role ' . uniqid(), 'is_super' => false]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/admin/overview/stats')->assertOk()->json();
    }

    private function makeProblem(User $creator, string $title = 'Bài thử tổng quan'): Problem
    {
        return Problem::create([
            'number'          => 'SQL-OV-' . strtoupper(uniqid()),
            'title'           => $title,
            'difficulty'      => 'Easy',
            'description'     => 'Mô tả',
            'requirements'    => 'SELECT 1',
            'practice_listed' => true,
            'creator_id'      => $creator->id,
        ]);
    }

    private function makeSubmission(User $student, Problem $problem, string $result, Carbon $at): Submission
    {
        return Submission::create([
            'id'           => (string) Str::uuid(),
            'user_id'      => $student->id,
            'problem_id'   => $problem->id,
            'source'       => 'SELECT 1',
            'context'      => 'Practice',
            'result'       => $result,
            'score'        => 0,
            'submitted_at' => $at,
        ]);
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
        foreach (['stats', 'activities', 'errors'] as $part) {
            $this->getJson("/api/admin/overview/{$part}")->assertStatus(401);
        }

        $forbidden = [$this->makeUser('instructor'), $this->makeUser('student'), $this->makeUser('faculty_office')];
        foreach ($forbidden as $user) {
            Sanctum::actingAs($user);
            foreach (['stats', 'activities', 'errors'] as $part) {
                $this->getJson("/api/admin/overview/{$part}")->assertStatus(403);
            }
        }
    }

    public function test_any_of_user_class_or_access_management_is_enough(): void
    {
        foreach (['user.manage', 'class.manage', 'access.manage'] as $code) {
            Sanctum::actingAs($this->makeUser('visitor', 'Active', [$code]));
            foreach (['stats', 'activities', 'errors'] as $part) {
                $this->getJson("/api/admin/overview/{$part}")->assertOk();
            }
        }
    }

    public function test_the_old_counter_route_is_gone(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/overview')->assertStatus(404);
    }

    // ------------------------------------------------------------------ stats

    public function test_stats_returns_exactly_the_integer_keys_the_frontend_reads(): void
    {
        $data = $this->stats();

        $this->assertEqualsCanonicalizing(self::STATS_KEYS, array_keys($data));
        foreach (self::STATS_KEYS as $key) {
            $this->assertIsInt($data[$key], "{$key} phải là số nguyên");
            $this->assertGreaterThanOrEqual(0, $data[$key]);
        }
        $this->assertGreaterThanOrEqual(1, $data['users']);
    }

    public function test_stats_users_counts_every_account_in_any_status(): void
    {
        $before = $this->stats();

        $this->makeUser('student');
        $this->makeUser('student', 'Pending');
        $this->makeUser('instructor', 'Inactive');

        $this->assertSame(3, $this->stats()['users'] - $before['users']);
    }

    public function test_stats_classes_counts_only_active_classes(): void
    {
        $before = $this->stats();

        $this->makeClass('Active');
        $this->makeClass('Active');
        $this->makeClass('Archived');

        $this->assertSame(2, $this->stats()['classes'] - $before['classes']);
    }

    public function test_stats_pending_requests_counts_only_pending_staff_accounts(): void
    {
        $before = $this->stats();

        $this->makeUser('instructor', 'Pending');
        $this->makeUser('instructor', 'Pending');
        $this->makeUser('student', 'Pending');      // sinh viên chờ: không phải đơn giảng viên
        $this->makeUser('instructor', 'Active');
        $this->makeUser('instructor', 'Inactive');

        $this->assertSame(2, $this->stats()['pendingRequests'] - $before['pendingRequests']);
    }

    public function test_stats_unassigned_classes_skips_archived_and_classes_with_a_lecturer(): void
    {
        $lecturer = $this->makeUser('instructor');
        $before = $this->stats();

        $this->makeClass('Active');                      // +1
        $this->makeClass('Inactive');                    // +1 (chưa lưu trữ)
        $this->makeClass('Archived');                    // đã lưu trữ: không tính
        $this->makeClass('Active', $lecturer->id);       // đã có giảng viên: không tính

        $this->assertSame(2, $this->stats()['unassignedClasses'] - $before['unassignedClasses']);
    }

    public function test_stats_submissions_today_uses_the_utc_day_boundary(): void
    {
        $student = $this->makeUser('student');
        $problem = $this->makeProblem($student);
        $before = $this->stats();

        $startOfDay = now()->utc()->startOfDay();
        $this->makeSubmission($student, $problem, 'Accepted', $startOfDay);                     // đúng 0h: tính
        $this->makeSubmission($student, $problem, 'Accepted', $startOfDay->copy()->subSecond()); // 23:59:59 hôm qua: không
        $this->makeSubmission($student, $problem, 'Accepted', now());                            // tính

        $this->assertSame(2, $this->stats()['submissionsToday'] - $before['submissionsToday']);
    }

    public function test_stats_grading_errors_match_error_and_timeout_results_in_any_case(): void
    {
        $student = $this->makeUser('student');
        $problem = $this->makeProblem($student);
        $before = $this->stats();

        foreach (['Runtime Error', 'syntax ERROR', 'TIMEOUT'] as $result) {
            $this->makeSubmission($student, $problem, $result, now()->subDays(3));
        }
        foreach (['Accepted', 'Wrong Answer', 'Pending'] as $result) {
            $this->makeSubmission($student, $problem, $result, now()->subDays(3));
        }

        $this->assertSame(3, $this->stats()['gradingErrors'] - $before['gradingErrors']);
    }

    public function test_stats_match_direct_table_counts(): void
    {
        $this->makeUser('instructor', 'Pending');
        $this->makeClass('Active');

        $data = $this->stats();

        $this->assertSame(DB::table('users')->count(), $data['users']);
        $this->assertSame(DB::table('classes')->where('status', 'Active')->count(), $data['classes']);
        $this->assertSame(DB::table('users')->where('status', 'Pending')->where('account_type', 'staff')->count(), $data['pendingRequests']);
        $this->assertSame(
            DB::table('classes')->whereNull('instructor_id')->where('status', '!=', 'Archived')->count(),
            $data['unassignedClasses'],
        );
        $this->assertSame(
            DB::table('submissions')->where(fn ($q) => $q->where('result', 'like', '%error%')->orWhere('result', 'like', '%timeout%'))->count(),
            $data['gradingErrors'],
        );
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

    // ----------------------------------------------------------------- errors

    public function test_errors_are_the_five_newest_error_submissions_with_readable_fields(): void
    {
        Sanctum::actingAs($this->admin);
        $student = $this->makeUser('student');
        $problem = $this->makeProblem($student, 'Truy vấn khách hàng');
        $base = now()->addDays(2);

        $errors = [];
        for ($i = 0; $i < 7; $i++) {
            $errors[$i] = $this->makeSubmission($student, $problem, $i % 2 ? 'Timeout' : 'Runtime Error', $base->copy()->addMinutes($i));
        }
        // Bài nộp đúng, mới hơn tất cả lỗi: không được lọt vào danh sách.
        $this->makeSubmission($student, $problem, 'Accepted', $base->copy()->addHour());

        $rows = $this->getJson('/api/admin/overview/errors')->assertOk()->json();

        $this->assertCount(5, $rows);
        $this->assertSame(array_map(fn ($i) => $errors[$i]->id, range(6, 2)), array_column($rows, 'id'));
        $this->assertSame(['id', 'problemTitle', 'errorType', 'studentName', 'submittedAt'], array_keys($rows[0]));
        $this->assertSame('Truy vấn khách hàng', $rows[0]['problemTitle']);
        $this->assertSame('Runtime Error', $rows[0]['errorType']);
        $this->assertSame('Timeout', $rows[1]['errorType']);
        $this->assertSame($student->name, $rows[0]['studentName']);
        $this->assertStringEndsWith('Z', $rows[0]['submittedAt']);
        $this->assertTrue(Carbon::parse($rows[0]['submittedAt'])->equalTo($base->copy()->addMinutes(6)->startOfSecond()));
    }

    public function test_errors_list_is_empty_safe_when_no_error_is_newer_than_existing_data(): void
    {
        Sanctum::actingAs($this->admin);

        $rows = $this->getJson('/api/admin/overview/errors')->assertOk()->json();

        $this->assertLessThanOrEqual(5, count($rows));
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/error|timeout/i', $row['errorType']);
        }
    }
}
