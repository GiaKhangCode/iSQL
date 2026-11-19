<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Test đặc tả cho POST /api/admin/users/{id}/approve (Admin\UserController::approve).
 *
 * Khóa các quy tắc duyệt tài khoản giảng viên (vai trò mặc định gán lúc tạo, quyền hiệu lực
 * sau khi duyệt, không nhân đôi vai trò/quyền trực tiếp).
 */
class LecturerApprovalTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin', 'Active');
    }

    private function makeUser(string $role, string $status, string $password = 'password123'): User
    {
        return User::create([
            'name'            => "Approval {$role}",
            'email'           => "approval_{$role}_" . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make($password),
            ...AccountFixture::for($role),
            'status'          => $status,
            'initials'        => 'AP',
        ]);
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

    public function test_approve_activates_a_pending_lecturer_and_enables_the_lecturer_permissions(): void
    {
        $pending = $this->makeUser('instructor_practical', 'Pending');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('id', $pending->id)
            ->assertJsonPath('status', 'Active');

        $pending->refresh();
        $this->assertSame('Active', $pending->status);
        $access = app(\App\Services\Access\AccessControlService::class);
        $access->forget($pending->id);
        $this->assertTrue($access->can($pending, 'problem.create'));
        $this->assertTrue($access->can($pending, 'results.grade'));
        $this->assertFalse($access->can($pending, 'class.manage'));
    }

    public function test_approve_accepts_every_staff_account(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['instructor', 'instructor_practical', 'instructor_theory', 'department_head', 'faculty_office'] as $role) {
            $pending = $this->makeUser($role, 'Pending');

            $this->postJson("/api/admin/users/{$pending->id}/approve")
                ->assertOk()
                ->assertJsonPath('status', 'Active');
        }
    }

    public function test_approve_keeps_existing_roles_and_direct_permissions_without_duplicating(): void
    {
        $pending = $this->makeUser('instructor_theory', 'Pending');
        $before = \Illuminate\Support\Facades\DB::table('user_access_roles')->where('user_id', $pending->id)->pluck('access_role_id')->all();
        $this->assertNotEmpty($before, 'vai trò mặc định đã được gán từ lúc tạo tài khoản');
        \Illuminate\Support\Facades\DB::table('user_permissions')->insert(['user_id' => $pending->id, 'permission' => 'library.manage']);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$pending->id}/approve")->assertOk();

        $after = \Illuminate\Support\Facades\DB::table('user_access_roles')->where('user_id', $pending->id)->pluck('access_role_id')->all();
        $this->assertEqualsCanonicalizing($before, $after, 'không đổi, không trùng lặp');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('user_permissions')->where('user_id', $pending->id)->count());
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

    public function test_approve_rejects_student_accounts(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['student'] as $role) {
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
