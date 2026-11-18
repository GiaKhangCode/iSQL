<?php

namespace Tests\Feature;

use App\Models\RoleGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test đặc tả cho POST /api/admin/users/{id}/approve (Admin\UserController::approve).
 *
 * Khóa các quy tắc duyệt tài khoản giảng viên trước khi bỏ đoạn truy vấn RoleGroup
 * khỏi controller (lộ trình 1.5).
 */
class LecturerApprovalTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected ?RoleGroup $lecturerGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin', 'Active');
        $this->lecturerGroup = RoleGroup::where('name', 'like', '%Giảng viên%')->first();
    }

    private function makeUser(string $role, string $status, string $password = 'password123'): User
    {
        return User::create([
            'name'            => "Approval {$role}",
            'email'           => "approval_{$role}_" . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make($password),
            'role'            => $role,
            'status'          => $status,
            'initials'        => 'AP',
        ]);
    }

    private function requireLecturerGroup(): RoleGroup
    {
        if (!$this->lecturerGroup) {
            $this->markTestSkipped('Chưa seed nhóm quyền Giảng viên (chạy PermissionSeeder).');
        }

        return $this->lecturerGroup;
    }

    // ── Phân quyền ──────────────────────────────────────────────────────────

    public function test_approve_requires_authentication_and_admin_role(): void
    {
        $pending = $this->makeUser('instructor_practical', 'Pending');

        $this->postJson("/api/admin/users/{$pending->id}/approve")->assertStatus(401);

        Sanctum::actingAs($this->makeUser('instructor', 'Active'));
        $this->postJson("/api/admin/users/{$pending->id}/approve")->assertStatus(403);

        Sanctum::actingAs($this->makeUser('student', 'Active'));
        $this->postJson("/api/admin/users/{$pending->id}/approve")->assertStatus(403);

        $this->assertSame('Pending', $pending->fresh()->status);
    }

    // ── Duyệt thành công ────────────────────────────────────────────────────

    public function test_approve_activates_a_pending_lecturer_and_assigns_the_lecturer_group(): void
    {
        $group = $this->requireLecturerGroup();
        $pending = $this->makeUser('instructor_practical', 'Pending');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('id', $pending->id)
            ->assertJsonPath('status', 'Active');

        $pending->refresh();
        $this->assertSame('Active', $pending->status);
        $this->assertTrue($pending->roleGroups()->where('role_groups.id', $group->id)->exists());
        $this->assertTrue($pending->canDo('CLASS_MGMT', 'add'));
    }

    public function test_approve_accepts_every_instructor_role(): void
    {
        $this->requireLecturerGroup();

        Sanctum::actingAs($this->admin);

        foreach (User::INSTRUCTOR_ROLES as $role) {
            $pending = $this->makeUser($role, 'Pending');

            $this->postJson("/api/admin/users/{$pending->id}/approve")
                ->assertOk()
                ->assertJsonPath('status', 'Active');
        }
    }

    public function test_approve_adds_the_group_without_removing_existing_groups_or_duplicating(): void
    {
        $group = $this->requireLecturerGroup();
        $otherGroup = RoleGroup::where('id', '!=', $group->id)->first();
        if (!$otherGroup) {
            $this->markTestSkipped('Cần ít nhất hai nhóm quyền trong DB.');
        }

        $pending = $this->makeUser('instructor_theory', 'Pending');
        // Đã thuộc nhóm Giảng viên và một nhóm khác từ trước.
        $pending->roleGroups()->sync([$group->id, $otherGroup->id]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$pending->id}/approve")->assertOk();

        $ids = $pending->roleGroups()->pluck('role_groups.id')->all();
        $this->assertCount(2, $ids, 'không được trùng lặp hay mất nhóm cũ');
        $this->assertEqualsCanonicalizing([$group->id, $otherGroup->id], $ids);
    }

    public function test_pending_lecturer_can_log_in_only_after_approval(): void
    {
        $pending = $this->makeUser('instructor_practical', 'Pending', 'secret-pass-123');
        $credentials = ['email' => $pending->email, 'password' => 'secret-pass-123'];

        $this->postJson('/api/auth/login', $credentials)->assertStatus(403);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/users/{$pending->id}/approve")->assertOk();

        // Sanctum::actingAs giữ phiên của admin, nên đăng nhập bằng guard mặc định để thử lại.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', $credentials)
            ->assertOk()
            ->assertJsonPath('user.id', $pending->id);
    }

    // ── Từ chối ─────────────────────────────────────────────────────────────

    public function test_approve_rejects_non_instructor_accounts(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['student', 'department_head', 'faculty_office', 'admin'] as $role) {
            $user = $this->makeUser($role, 'Pending');

            $this->postJson("/api/admin/users/{$user->id}/approve")
                ->assertStatus(422)
                ->assertJsonValidationErrors(['user_id']);

            $this->assertSame('Pending', $user->fresh()->status, "{$role} không được đổi trạng thái");
        }
    }

    public function test_approve_rejects_accounts_that_are_not_pending(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['Active', 'Inactive'] as $status) {
            $user = $this->makeUser('instructor_practical', $status);

            $this->postJson("/api/admin/users/{$user->id}/approve")
                ->assertStatus(422)
                ->assertJsonValidationErrors(['status']);

            $this->assertSame($status, $user->fresh()->status);
        }
    }

    public function test_approve_returns_404_for_unknown_user(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/users/' . Str::uuid() . '/approve')->assertStatus(404);
    }
}
