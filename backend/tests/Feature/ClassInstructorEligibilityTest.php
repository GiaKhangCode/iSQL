<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\ClassModel;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Ai được phân công dạy lớp: người Active có quyền class.teach (qua vai trò thường hoặc gán riêng).
 * Không đọc loại tài khoản. Siêu quản trị không tự có class.teach (NEVER_FOR_SUPER).
 *
 * Mỗi ca dựng tài khoản có loại tài khoản LỆCH với quyền để chứng minh điều đó.
 */
class ClassInstructorEligibilityTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private User $admin;
    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        $this->admin = $this->makeUser('admin');
        $this->class = ClassModel::create([
            'id' => 'CE_' . strtoupper(uniqid()), 'course' => 'CE Course', 'term' => 'CE Term', 'status' => 'Active',
        ]);
        Sanctum::actingAs($this->admin);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function makeUser(string $role, string $status = 'Active', string $name = ''): User
    {
        $u = uniqid();

        return User::create([
            'name'            => $name !== '' ? $name : "CE {$role} {$u}",
            'email'           => "ce_{$u}@uit.edu.vn",
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => $status,
            'initials'        => 'CE',
        ]);
    }

    /** Thay mọi vai trò của $user bằng một vai trò tùy chỉnh có đúng các quyền $codes. */
    private function giveOnly(User $user, array $codes): User
    {
        DB::table('user_access_roles')->where('user_id', $user->id)->delete();
        $role = AccessRole::create(['name' => 'CE vai trò ' . uniqid()]);
        $role->syncPermissions($codes);
        $user->accessRoles()->attach($role->id);
        app(AccessControlService::class)->forget();

        return $user;
    }

    private function assign(User $instructor, string $prefix = '/api/admin')
    {
        return $this->putJson("{$prefix}/classes/{$this->class->id}/instructor", [
            'instructor_id' => $instructor->id,
        ]);
    }

    private function listedIds(): array
    {
        return collect($this->getJson('/api/admin/instructors')->assertOk()->json())->pluck('id')->all();
    }

    // ───────────────── người có class.teach dù loại tài khoản = student ─────────────────

    public function test_a_student_account_with_class_teach_via_a_custom_role_can_be_assigned_everywhere(): void
    {
        $teacher = $this->giveOnly($this->makeUser('student'), ['class.teach']);

        $this->assign($teacher)->assertOk()->assertJsonPath('lecturer_id', $teacher->id);
        $this->assertDatabaseHas('classes', ['id' => $this->class->id, 'instructor_id' => $teacher->id]);

        $this->postJson('/api/admin/classes', ['course' => 'C', 'term' => 'T', 'lecturer_id' => $teacher->id])
            ->assertStatus(201)->assertJsonPath('lecturer_id', $teacher->id);

        $this->assertContains($teacher->id, $this->listedIds());
    }

    public function test_a_direct_class_teach_grant_is_enough(): void
    {
        $teacher = $this->giveOnly($this->makeUser('student'), []);
        DB::table('user_permissions')->insert(['user_id' => $teacher->id, 'permission' => 'class.teach']);
        app(AccessControlService::class)->forget();

        $this->assign($teacher)->assertOk();
        $this->assertContains($teacher->id, $this->listedIds());
    }

    public function test_the_teacher_prefix_assign_route_uses_the_same_rule(): void
    {
        $teacher = $this->giveOnly($this->makeUser('student'), ['class.teach']);
        $stale = $this->giveOnly($this->makeUser('instructor_practical'), ['results.grade']);

        $this->assign($teacher, '/api/teacher')->assertOk();
        $this->assign($stale, '/api/teacher')->assertStatus(422)->assertJsonValidationErrors('instructor_id', 'errors');
        $this->assertDatabaseHas('classes', ['id' => $this->class->id, 'instructor_id' => $teacher->id]);
    }

    // ───────────────── loại tài khoản = instructor nhưng không còn class.teach ─────────────────

    public function test_an_instructor_account_without_class_teach_is_rejected_everywhere(): void
    {
        $stale = $this->giveOnly($this->makeUser('instructor_practical'), ['problem.create', 'results.grade', 'assignment.create']);
        $classes = ClassModel::count();

        $this->assign($stale)->assertStatus(422)->assertJsonValidationErrors('instructor_id', 'errors');
        $this->postJson('/api/admin/classes', ['course' => 'C', 'term' => 'T', 'lecturer_id' => $stale->id])
            ->assertStatus(422)->assertJsonValidationErrors('instructor_id', 'errors');

        $this->assertSame($classes, ClassModel::count(), 'không tạo lớp dở dang');
        $this->assertDatabaseHas('classes', ['id' => $this->class->id, 'instructor_id' => null]);
        $this->assertNotContains($stale->id, $this->listedIds());
    }

    public function test_holders_that_are_not_active_are_rejected_and_not_listed(): void
    {
        foreach (['Inactive'] as $status) {
            $u = $this->giveOnly($this->makeUser('instructor_practical', $status), ['class.teach']);

            $this->assign($u)->assertStatus(422);
            $this->assertNotContains($u->id, $this->listedIds());
        }
    }

    // ───────────────── vai trò mặc định ─────────────────

    public function test_default_roles_only_the_lecturer_role_can_teach(): void
    {
        $lecturer = $this->makeUser('instructor_theory');
        $head = $this->makeUser('department_head');
        $office = $this->makeUser('faculty_office');
        $student = $this->makeUser('student');

        $this->assign($lecturer)->assertOk();
        foreach ([$head, $office, $student] as $u) {
            $this->assign($u)->assertStatus(422);
        }

        $listed = $this->listedIds();
        $this->assertContains($lecturer->id, $listed);
        foreach ([$head, $office, $student, $this->admin] as $u) {
            $this->assertNotContains($u->id, $listed);
        }
    }

    public function test_a_super_admin_teaches_only_with_class_teach_from_another_role(): void
    {
        $this->assign($this->admin)->assertStatus(422);
        $this->assertFalse(app(AccessControlService::class)->can($this->admin, 'class.teach'));

        $extra = AccessRole::create(['name' => 'CE dạy ' . uniqid()]);
        $extra->syncPermissions(['class.teach']);
        $this->admin->accessRoles()->attach($extra->id);
        app(AccessControlService::class)->forget();

        $this->assign($this->admin)->assertOk();
        $this->assertContains($this->admin->id, $this->listedIds());
    }

    // ───────────────── mất quyền khi đang dạy ─────────────────

    public function test_losing_class_teach_keeps_existing_assignments_but_blocks_new_ones(): void
    {
        $teacher = $this->giveOnly($this->makeUser('instructor_practical'), ['class.teach']);
        $this->assign($teacher)->assertOk();

        $this->giveOnly($teacher, ['results.grade']);

        $this->assertDatabaseHas('classes', ['id' => $this->class->id, 'instructor_id' => $teacher->id]);
        $this->getJson("/api/admin/classes/{$this->class->id}")->assertOk()->assertJsonPath('lecturer_id', $teacher->id);

        // Một người khác cũng không có class.teach thì không được thay vào.
        $other = $this->giveOnly($this->makeUser('instructor_practical'), ['results.grade']);
        $this->assign($other)->assertStatus(422);
        $this->assertDatabaseHas('classes', ['id' => $this->class->id, 'instructor_id' => $teacher->id]);
    }

    public function test_resubmitting_the_current_instructor_does_not_require_class_teach(): void
    {
        $current = $this->giveOnly($this->makeUser('instructor_practical'), ['class.teach']);
        $other = $this->giveOnly($this->makeUser('instructor_theory'), ['class.teach']);
        $this->assign($current)->assertOk();

        // Mất quyền dạy nhưng vẫn đứng lớp.
        $this->giveOnly($current, ['results.grade']);
        $this->giveOnly($other, ['results.grade']);

        // Form sửa lớp của frontend luôn gửi lại giảng viên đang chọn: sửa ngày không được 422.
        $this->putJson("/api/admin/classes/{$this->class->id}", [
            'lecturerId' => $current->id, 'endDate' => '2027-01-31',
        ])->assertOk()->assertJsonPath('lecturer_id', $current->id);
        $this->assign($current)->assertOk();
        $this->assign($current, '/api/teacher')->assertOk();

        // Nhưng đổi sang người khác là phân công mới, cần class.teach.
        $this->assign($other)->assertStatus(422);
        $this->putJson("/api/admin/classes/{$this->class->id}", ['lecturerId' => $other->id])->assertStatus(422);
        $this->assertDatabaseHas('classes', ['id' => $this->class->id, 'instructor_id' => $current->id]);
    }

    public function test_changing_the_account_type_of_a_teaching_instructor_is_no_longer_blocked(): void
    {
        $teacher = $this->makeUser('instructor_practical');
        $this->assign($teacher)->assertOk();

        $this->putJson("/api/admin/users/{$teacher->id}", ['account_type' => 'student'])->assertOk();

        $this->assertSame('student', $teacher->fresh()->account_type);
        $this->assertDatabaseHas('classes', ['id' => $this->class->id, 'instructor_id' => $teacher->id]);
    }

    // ───────────────── GET /api/admin/instructors ─────────────────

    public function test_instructor_list_shape_and_order(): void
    {
        $b = $this->giveOnly($this->makeUser('student', 'Active', 'CE zz Bình ' . uniqid()), ['class.teach']);
        $a = $this->giveOnly($this->makeUser('student', 'Active', 'CE aa An ' . uniqid()), ['class.teach']);

        $rows = $this->getJson('/api/admin/instructors')->assertOk()->json();
        $ids = array_column($rows, 'id');
        $this->assertLessThan(array_search($b->id, $ids, true), array_search($a->id, $ids, true), 'sắp theo tên');

        $row = collect($rows)->firstWhere('id', $a->id);
        $this->assertSame(['id', 'name', 'email', 'department'], array_keys($row));
        $this->assertSame($a->name, $row['name']);
        $this->assertSame(count($ids), count(array_unique($ids)), 'không trùng khi có quyền từ nhiều nguồn');
    }

    public function test_instructor_list_has_no_duplicates_when_granted_twice(): void
    {
        $u = $this->giveOnly($this->makeUser('student'), ['class.teach']);
        DB::table('user_permissions')->insert(['user_id' => $u->id, 'permission' => 'class.teach']);
        app(AccessControlService::class)->forget();

        $this->assertSame(1, count(array_keys($this->listedIds(), $u->id, true)));
    }

    public function test_instructor_list_requires_class_manage(): void
    {
        Sanctum::actingAs($this->makeUser('instructor_practical'));
        $this->getJson('/api/admin/instructors')->assertStatus(403);

        Sanctum::actingAs($this->giveOnly($this->makeUser('student'), ['class.manage']));
        $this->getJson('/api/admin/instructors')->assertOk();
    }

    public function test_instructor_list_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/instructors')->assertStatus(401);
    }
}
