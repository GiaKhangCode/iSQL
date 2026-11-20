<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Test đặc tả cho hàng đợi duyệt giảng viên trên trang Tổng quan:
 * GET /api/admin/users/lecturer-requests, POST .../{id}/approve, POST .../{id}/reject.
 *
 * "Đang chờ duyệt" = tài khoản nhân sự (account_type = staff) có status Pending. Từ chối = xóa tài khoản chờ
 * (như bản gốc SQL-UIT); duyệt = chuyển sang Active. Cả hai chỉ áp dụng cho tài khoản đang Pending.
 */
class LecturerRequestTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();

        $this->admin = $this->makeUser('admin');
    }

    private function makeUser(string $kind, string $status = 'Active', ?array $permissions = null, array $extra = []): User
    {
        $user = User::create([
            'name'            => "Request {$kind} " . uniqid(),
            'email'           => "request_{$kind}_" . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($kind),
            'status'          => $status,
            'initials'        => 'RQ',
            ...$extra,
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

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    /** @return array<int, array<string, mixed>> */
    private function requests(): array
    {
        $this->asAdmin();

        return $this->getJson('/api/admin/users/lecturer-requests')->assertOk()->json();
    }

    private function pendingCount(): int
    {
        $this->asAdmin();

        return $this->getJson('/api/admin/overview/stats')->assertOk()->json('pendingRequests');
    }

    // ------------------------------------------------------------------ quyền

    public function test_requires_authentication_and_user_management(): void
    {
        $pending = $this->makeUser('instructor', 'Pending');

        $this->getJson('/api/admin/users/lecturer-requests')->assertStatus(401);
        $this->postJson("/api/admin/users/lecturer-requests/{$pending->id}/approve")->assertStatus(401);
        $this->postJson("/api/admin/users/lecturer-requests/{$pending->id}/reject")->assertStatus(401);

        foreach ([$this->makeUser('instructor'), $this->makeUser('student'), $this->makeUser('visitor', 'Active', ['class.manage'])] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/admin/users/lecturer-requests')->assertStatus(403);
            $this->postJson("/api/admin/users/lecturer-requests/{$pending->id}/approve")->assertStatus(403);
            $this->postJson("/api/admin/users/lecturer-requests/{$pending->id}/reject")->assertStatus(403);
        }

        $this->assertSame('Pending', $pending->fresh()->status, 'bị chặn thì không được đổi gì');
        Sanctum::actingAs($this->makeUser('visitor', 'Active', ['user.manage']));
        $this->getJson('/api/admin/users/lecturer-requests')->assertOk();
    }

    // ------------------------------------------------------------------- list

    public function test_list_returns_only_pending_staff_with_display_fields(): void
    {
        $lecturer = $this->makeUser('instructor', 'Pending', null, ['department' => 'Khoa HTTT']);
        $other = [
            $this->makeUser('student', 'Pending'),
            $this->makeUser('instructor', 'Active'),
            $this->makeUser('instructor', 'Inactive'),
        ];

        $rows = $this->requests();
        $byId = array_column($rows, null, 'id');

        $this->assertArrayHasKey($lecturer->id, $byId);
        foreach ($other as $user) {
            $this->assertArrayNotHasKey($user->id, $byId);
        }

        $row = $byId[$lecturer->id];
        $this->assertSame(['id', 'name', 'email', 'department', 'submitted'], array_keys($row));
        $this->assertSame($lecturer->name, $row['name']);
        $this->assertSame($lecturer->email, $row['email']);
        $this->assertSame('Khoa HTTT', $row['department']);
        $this->assertSame($lecturer->created_at->utc()->format('d/m/Y'), $row['submitted']);
    }

    public function test_department_is_an_empty_string_when_missing(): void
    {
        $lecturer = $this->makeUser('instructor', 'Pending');

        $row = array_column($this->requests(), null, 'id')[$lecturer->id];

        $this->assertSame('', $row['department']);
    }

    public function test_list_is_oldest_first_and_matches_the_overview_counter(): void
    {
        $newer = $this->makeUser('instructor', 'Pending');
        $older = $this->makeUser('instructor', 'Pending');
        DB::table('users')->where('id', $older->id)->update(['created_at' => now()->subDays(40)]);
        DB::table('users')->where('id', $newer->id)->update(['created_at' => now()->subDays(2)]);

        $ids = array_column($this->requests(), 'id');

        $this->assertLessThan(array_search($newer->id, $ids), array_search($older->id, $ids), 'đơn cũ hơn đứng trước');
        $this->assertCount($this->pendingCount(), $ids, 'panel và bộ đếm dùng cùng một định nghĩa');
    }

    // ---------------------------------------------------------------- approve

    public function test_approve_activates_the_account_and_records_the_activity(): void
    {
        $lecturer = $this->makeUser('instructor', 'Pending');
        $before = $this->pendingCount();

        $this->postJson("/api/admin/users/lecturer-requests/{$lecturer->id}/approve")
            ->assertOk()->assertJson(['message' => 'Approved']);

        $this->assertSame('Active', $lecturer->fresh()->status);
        $this->assertSame($before - 1, $this->pendingCount());
        $this->assertArrayNotHasKey($lecturer->id, array_column($this->requests(), null, 'id'));

        $log = ActivityLog::where('action', "Approved lecturer {$lecturer->email}")->first();
        $this->assertNotNull($log, 'chuỗi khớp regex /^Approved lecturer (.+)$/ của frontend');
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_approved_lecturer_can_now_hold_their_role_permissions(): void
    {
        $lecturer = $this->makeUser('instructor', 'Pending');
        $access = app(AccessControlService::class);
        $this->assertFalse($access->can($lecturer, 'class.teach'), 'chưa duyệt thì chưa có quyền');

        $this->asAdmin();
        $this->postJson("/api/admin/users/lecturer-requests/{$lecturer->id}/approve")->assertOk();
        $access->forget();

        $this->assertTrue($access->can($lecturer->fresh(), 'class.teach'));
    }

    public function test_approve_only_works_on_pending_staff(): void
    {
        $this->asAdmin();
        $active = $this->makeUser('instructor', 'Active');
        $pendingStudent = $this->makeUser('student', 'Pending');
        $logsBefore = ActivityLog::count();

        $this->postJson("/api/admin/users/lecturer-requests/{$active->id}/approve")->assertStatus(404);
        $this->postJson("/api/admin/users/lecturer-requests/{$pendingStudent->id}/approve")->assertStatus(404);
        $this->postJson('/api/admin/users/lecturer-requests/khong-ton-tai/approve')->assertStatus(404);

        $this->assertSame('Pending', $pendingStudent->fresh()->status);
        $this->assertSame($logsBefore, ActivityLog::count(), 'không ghi nhật ký cho thao tác bị từ chối');
    }

    public function test_approving_twice_fails_the_second_time(): void
    {
        $lecturer = $this->makeUser('instructor', 'Pending');
        $this->asAdmin();

        $this->postJson("/api/admin/users/lecturer-requests/{$lecturer->id}/approve")->assertOk();
        $this->postJson("/api/admin/users/lecturer-requests/{$lecturer->id}/approve")->assertStatus(404);
        $this->assertSame(1, ActivityLog::where('action', "Approved lecturer {$lecturer->email}")->count());
    }

    // ----------------------------------------------------------------- reject

    public function test_reject_deletes_the_pending_account_and_records_the_activity(): void
    {
        $lecturer = $this->makeUser('instructor', 'Pending');
        $before = $this->pendingCount();

        $this->postJson("/api/admin/users/lecturer-requests/{$lecturer->id}/reject")
            ->assertOk()->assertJson(['message' => 'Rejected']);

        $this->assertNull(User::find($lecturer->id));
        $this->assertSame($before - 1, $this->pendingCount());

        $log = ActivityLog::where('action', "Rejected lecturer {$lecturer->email}")->first();
        $this->assertNotNull($log, 'chuỗi khớp regex /^Rejected lecturer (.+)$/ của frontend');
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_reject_never_touches_accounts_that_are_not_pending_staff(): void
    {
        $this->asAdmin();
        $active = $this->makeUser('instructor', 'Active');
        $inactive = $this->makeUser('instructor', 'Inactive');
        $pendingStudent = $this->makeUser('student', 'Pending');
        $logsBefore = ActivityLog::count();

        foreach ([$active, $inactive, $pendingStudent] as $user) {
            $this->postJson("/api/admin/users/lecturer-requests/{$user->id}/reject")->assertStatus(404);
            $this->assertNotNull(User::find($user->id), 'tài khoản không chờ duyệt không được bị xóa');
        }
        $this->postJson('/api/admin/users/lecturer-requests/khong-ton-tai/reject')->assertStatus(404);
        $this->assertSame($logsBefore, ActivityLog::count());
    }

    public function test_rejecting_twice_fails_the_second_time(): void
    {
        $lecturer = $this->makeUser('instructor', 'Pending');
        $this->asAdmin();

        $this->postJson("/api/admin/users/lecturer-requests/{$lecturer->id}/reject")->assertOk();
        $this->postJson("/api/admin/users/lecturer-requests/{$lecturer->id}/reject")->assertStatus(404);
    }

    public function test_errors_use_the_json_error_shape(): void
    {
        $this->asAdmin();

        $res = $this->postJson('/api/admin/users/lecturer-requests/khong-ton-tai/approve')->assertStatus(404);

        $this->assertArrayHasKey('detail', $res->json());
        $this->assertArrayHasKey('message', $res->json());
    }
}
