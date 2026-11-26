<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Test đặc tả (characterization) cho App\Http\Controllers\ClassRosterController,
 * viết TRƯỚC khi tách logic sang service. Bổ sung cho ClassRosterImportTest
 * (chỉ phủ vài ca import) và ClassInstructorAllocationTest.
 *
 * Frontend chỉ gọi nhóm /api/teacher/classes/{id}/members* và /classes/students;
 * các route /students* và /reset-password vẫn tồn tại nên cũng được khóa lại.
 */
class ClassRosterControllerTest extends TestCase
{
    use DatabaseTransactions;

    private const T = '/api/teacher/classes';
    private const A = '/api/admin/classes';

    private User $admin;
    private User $owner;      // giảng viên thực hành của $class, có nhóm quyền "Giảng viên"
    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->owner = $this->makeInstructor();
        $this->class = $this->makeClass($this->owner);
        Sanctum::actingAs($this->admin);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function makeUser(string $role, array $extra = []): User
    {
        $u = uniqid();

        return User::create(array_merge([
            'name'            => 'RC ' . $role . ' ' . $u,
            'email'           => "rc_{$u}@gm.uit.edu.vn",
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => 'Active',
            'initials'        => 'RC',
        ], $extra));
    }

    /** Giảng viên: vai trò mặc định "Giảng viên" (student.reset_password...) do User::created gán; không có class.manage. */
    private function makeInstructor(string $role = 'instructor_practical'): User
    {
        return $this->makeUser($role);
    }

    private function makeClass(?User $practical = null): ClassModel
    {
        $class = ClassModel::create([
            'id'            => 'RC_' . strtoupper(uniqid()),
            'course'        => 'RC Course',
            'term'          => 'RC Term',
            'instructor_id' => $practical?->id,
            'status'        => 'Active',
        ]);

        return $class;
    }

    private function enroll(User $student, ?ClassModel $class = null, string $role = 'Member'): void
    {
        ($class ?? $this->class)->students()->attach($student->id, ['role' => $role, 'joined_at' => now()]);
    }

    private function enrolledIds(?ClassModel $class = null): array
    {
        return DB::table('class_enrollments')->where('class_id', ($class ?? $this->class)->id)->pluck('student_id')->all();
    }

    private function csv(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function import(string $content, string $name = 'sv.csv', ?ClassModel $class = null)
    {
        return $this->post(self::T . '/' . ($class ?? $this->class)->id . '/members/import', ['file' => $this->csv($name, $content)], ['Accept' => 'application/json']);
    }

    private function actingAsStudent(): User
    {
        $s = $this->makeUser('student');
        Sanctum::actingAs($s);

        return $s;
    }

    // ───────────────────────── truy cập ─────────────────────────

    public function test_guest_gets_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::T . "/{$this->class->id}/members")->assertStatus(401);
        $this->postJson(self::T . "/{$this->class->id}/members", [])->assertStatus(401);
    }

    public function test_account_without_class_permissions_cannot_read_unrelated_rosters_or_write(): void
    {
        Sanctum::actingAs($this->makeUser('visitor'));

        $this->getJson(self::T . "/{$this->class->id}/members")->assertStatus(404); // không dạy lớp nào
        $this->getJson(self::T . '/students')->assertStatus(403);
        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => 'x'])->assertStatus(403);
    }

    public function test_write_routes_require_class_manage_permission(): void
    {
        $student = $this->makeUser('student');
        $faculty = $this->makeUser('faculty_office');
        $head = $this->makeUser('department_head');

        // Kể cả giảng viên dạy chính lớp này cũng không được ghi danh sách lớp.
        foreach ([$student, $faculty, $head, $this->owner] as $actor) {
            Sanctum::actingAs($actor);
            $id = $this->class->id;
            $this->postJson(self::T . "/{$id}/members", ['student_id' => $student->id])->assertStatus(403);
            $this->deleteJson(self::T . "/{$id}/members/{$student->id}")->assertStatus(403);
            $this->post(self::T . "/{$id}/members/import", ['file' => $this->csv('a.csv', 'x')], ['Accept' => 'application/json'])->assertStatus(403);
            $this->postJson(self::T . "/{$id}/students", ['student_ids' => [$student->id]])->assertStatus(403);
            $this->putJson(self::T . "/{$id}/students", ['student_ids' => []])->assertStatus(403);
            $this->deleteJson(self::T . "/{$id}/students/{$student->id}")->assertStatus(403);
            $this->getJson(self::T . '/students')->assertStatus(403);
        }
        $this->assertSame([], $this->enrolledIds());
    }

    public function test_admin_prefix_requires_admin_role(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson(self::A . "/{$this->class->id}/members")->assertStatus(403);
        $this->getJson(self::A . '/students')->assertStatus(403);
        $this->postJson(self::A . "/{$this->class->id}/students", ['student_ids' => []])->assertStatus(403);
    }

    public function test_admin_prefix_routes_behave_like_teacher_routes(): void
    {
        $s = $this->makeUser('student');

        $this->postJson(self::A . "/{$this->class->id}/members", ['student_id' => $s->id])->assertStatus(201);
        $this->getJson(self::A . "/{$this->class->id}/members")->assertOk()->assertJsonPath('0.id', $s->id);
        $this->getJson(self::A . "/{$this->class->id}/students")->assertOk()->assertJsonPath('0.id', $s->id);
        $this->deleteJson(self::A . "/{$this->class->id}/members/{$s->id}")->assertOk();
        $this->postJson(self::A . "/{$this->class->id}/students", ['student_ids' => [$s->id]])->assertStatus(201);
        $this->deleteJson(self::A . "/{$this->class->id}/students/{$s->id}")->assertOk();
        $this->assertContains($s->id, array_column($this->getJson(self::A . '/students')->assertOk()->json(), 'id'));
    }

    /** Admin chỉ có class.manage; đặt lại mật khẩu cần thêm student.reset_password (vai trò khác hoặc gán riêng). */
    private function grantPasswordResetToAdmin(): void
    {
        $role = \App\Models\AccessRole::create(['name' => 'Reset MK ' . uniqid()]);
        $role->syncPermissions(['student.reset_password']);
        $this->admin->accessRoles()->attach($role->id);
        app(\App\Services\Access\AccessControlService::class)->forget();
    }

    public function test_unknown_class_is_404_on_every_roster_route(): void
    {
        $this->grantPasswordResetToAdmin();
        $s = $this->makeUser('student');
        $x = 'khong-co-lop';

        $this->getJson(self::T . "/{$x}/members")->assertStatus(404);
        $this->getJson(self::T . "/{$x}/students")->assertStatus(404);
        $this->postJson(self::T . "/{$x}/members", ['student_id' => $s->id])->assertStatus(404);
        $this->deleteJson(self::T . "/{$x}/members/{$s->id}")->assertStatus(404);
        $this->postJson(self::T . "/{$x}/students", ['student_ids' => [$s->id]])->assertStatus(404);
        $this->putJson(self::T . "/{$x}/students", ['student_ids' => []])->assertStatus(404);
        $this->deleteJson(self::T . "/{$x}/students/{$s->id}")->assertStatus(404);
        $this->post(self::T . "/{$x}/members/import", ['file' => $this->csv('a.csv', 'x')], ['Accept' => 'application/json'])->assertStatus(404);
        $this->postJson(self::T . "/{$x}/students/{$s->id}/reset-password", ['password' => 'abcdefgh'])->assertStatus(404);
    }

    // ───────────────────────── phạm vi lớp (khóa lại hành vi hiện tại) ─────────────────────────

    public function test_instructor_cannot_change_the_roster_even_of_the_class_they_teach(): void
    {
        $student = $this->makeUser('student');
        $enrolled = $this->makeUser('student');
        $this->enroll($enrolled);
        Sanctum::actingAs($this->owner);
        $id = $this->class->id;

        $this->postJson(self::T . "/{$id}/members", ['student_id' => $student->id])->assertStatus(403);
        $this->deleteJson(self::T . "/{$id}/members/{$enrolled->id}")->assertStatus(403);
        $this->postJson(self::T . "/{$id}/students", ['student_ids' => [$student->id]])->assertStatus(403);
        $this->putJson(self::T . "/{$id}/students", ['student_ids' => []])->assertStatus(403);
        $this->deleteJson(self::T . "/{$id}/students/{$enrolled->id}")->assertStatus(403);
        $this->post(self::T . "/{$id}/members/import", ['file' => $this->csv('a.csv', $student->email)], ['Accept' => 'application/json'])->assertStatus(403);

        $this->assertSame([$enrolled->id], $this->enrolledIds(), 'danh sách lớp không đổi');
        // Nhưng vẫn đọc được lớp mình dạy.
        $this->getJson(self::T . "/{$id}/members")->assertOk()->assertJsonPath('0.id', $enrolled->id);
    }

    public function test_an_account_with_class_manage_via_a_custom_role_can_write_even_if_users_role_is_student(): void
    {
        $manager = $this->makeUser('student');
        DB::table('user_access_roles')->where('user_id', $manager->id)->delete();
        $role = \App\Models\AccessRole::create(['name' => 'QL lớp ' . uniqid()]);
        $role->syncPermissions(['class.manage']);
        $manager->accessRoles()->attach($role->id);
        app(\App\Services\Access\AccessControlService::class)->forget();
        $student = $this->makeUser('student');
        Sanctum::actingAs($manager);

        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => $student->id])->assertStatus(201);
        $this->assertSame([$student->id], $this->enrolledIds());
        $this->getJson(self::T . '/students')->assertOk();
    }

    public function test_the_class_instructor_can_read_but_not_edit_the_roster(): void
    {
        $teacher = $this->makeInstructor('instructor_theory');
        $this->class->update(['instructor_id' => $teacher->id]);
        $student = $this->makeUser('student');
        $this->enroll($student);
        Sanctum::actingAs($teacher);

        $this->getJson(self::T . "/{$this->class->id}/members")->assertOk()->assertJsonPath('0.id', $student->id);
        $this->getJson(self::T . "/{$this->class->id}/students")->assertOk();
        $this->deleteJson(self::T . "/{$this->class->id}/members/{$student->id}")->assertStatus(403);
        $this->assertSame([$student->id], $this->enrolledIds());
    }

    public function test_instructor_cannot_read_the_roster_of_a_class_they_do_not_teach(): void
    {
        $this->enroll($this->makeUser('student'));
        Sanctum::actingAs($this->makeInstructor());

        $this->getJson(self::T . "/{$this->class->id}/members")->assertStatus(404);
        $this->getJson(self::T . "/{$this->class->id}/students")->assertStatus(404);
    }

    public function test_students_cannot_read_rosters_or_the_student_directory(): void
    {
        $classmate = $this->makeUser('student');
        $this->enroll($classmate);
        $this->actingAsStudent();

        $this->getJson(self::T . "/{$this->class->id}/members")->assertStatus(404); // sinh viên không dạy lớp nào
        $this->getJson(self::T . "/{$this->class->id}/students")->assertStatus(404);
        $this->getJson(self::T . '/students')->assertStatus(403);
        $this->getJson(self::A . "/{$this->class->id}/members")->assertStatus(403);
    }

    public function test_admin_department_head_and_faculty_office_can_read_any_roster(): void
    {
        $classmate = $this->makeUser('student');
        $this->enroll($classmate);

        foreach ([$this->admin, $this->makeUser('department_head'), $this->makeUser('faculty_office')] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson(self::T . "/{$this->class->id}/members")->assertOk()->assertJsonPath('0.id', $classmate->id);
            $this->getJson(self::T . "/{$this->class->id}/students")->assertOk();
        }
    }

    public function test_only_class_managers_see_the_student_directory(): void
    {
        $this->makeUser('student');

        Sanctum::actingAs($this->admin);
        $this->getJson(self::T . '/students')->assertOk();

        foreach ([$this->makeUser('department_head'), $this->makeUser('faculty_office'), $this->owner] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson(self::T . '/students')->assertStatus(403);
        }
    }

    public function test_department_head_cannot_change_any_roster(): void
    {
        $student = $this->makeUser('student');
        Sanctum::actingAs($this->makeUser('department_head'));

        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => $student->id])->assertStatus(403);
        $this->assertSame([], $this->enrolledIds());
    }

    public function test_all_students_lists_only_active_students_sorted_by_name_with_shape(): void
    {
        $b = $this->makeUser('student', ['name' => 'RCZ Bình']);
        $a = $this->makeUser('student', ['name' => 'RCA An']);
        $inactive = $this->makeUser('student', ['status' => 'Inactive']);
        $teacher = $this->makeUser('instructor_practical');

        $res = $this->getJson(self::T . '/students')->assertOk();

        $ids = array_column($res->json(), 'id');
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($teacher->id, $ids);
        $this->assertLessThan(array_search($b->id, $ids), array_search($a->id, $ids));

        $row = collect($res->json())->firstWhere('id', $a->id);
        $this->assertSame(['id', 'name', 'email', 'role', 'joined_at', 'joinedAt'], array_keys($row));
        $this->assertSame('student', $row['role']);
        $this->assertSame($row['joined_at'], $row['joinedAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $row['joined_at']);
        $this->assertStringNotContainsString('hashed_password', $res->getContent());
    }

    // ───────────────────────── getMembers / index ─────────────────────────

    public function test_members_shape_order_and_pivot_role(): void
    {
        $z = $this->makeUser('student', ['name' => 'RCZ Cuối']);
        $a = $this->makeUser('student', ['name' => 'RCA Đầu']);
        $this->enroll($z);
        $this->enroll($a, null, 'Assistant');

        $res = $this->getJson(self::T . "/{$this->class->id}/members")->assertOk();

        $this->assertSame([$a->id, $z->id], array_column($res->json(), 'id'));
        $this->assertSame(['id', 'name', 'email', 'role', 'joined_at', 'joinedAt'], array_keys($res->json('0')));
        $this->assertSame('Assistant', $res->json('0.role'));
        $this->assertSame('Member', $res->json('1.role'));
        $this->assertNotNull($res->json('0.joined_at'));
        $this->assertSame($res->json('0.joined_at'), $res->json('0.joinedAt'));
    }

    public function test_members_of_empty_class_is_empty_array_and_other_classes_are_not_mixed_in(): void
    {
        $other = $this->makeClass();
        $this->enroll($this->makeUser('student'), $other);

        $this->getJson(self::T . "/{$this->class->id}/members")->assertOk()->assertExactJson([]);
        $this->getJson(self::T . "/{$this->class->id}/students")->assertOk()->assertExactJson([]);
    }

    public function test_students_index_returns_user_rows_sorted_by_name_without_password_hash(): void
    {
        $z = $this->makeUser('student', ['name' => 'RCZ Hai']);
        $a = $this->makeUser('student', ['name' => 'RCA Một']);
        $this->enroll($z);
        $this->enroll($a);

        $res = $this->getJson(self::T . "/{$this->class->id}/students")->assertOk();

        $this->assertSame([$a->id, $z->id], array_column($res->json(), 'id'));
        $this->assertSame($a->email, $res->json('0.email'));
        $this->assertStringNotContainsString('hashed_password', $res->getContent());
    }

    // ───────────────────────── addMember ─────────────────────────

    public function test_add_member_by_id_creates_enrollment_with_member_role(): void
    {
        $s = $this->makeUser('student');

        $res = $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => $s->id])->assertStatus(201);

        $this->assertSame(['id', 'name', 'email', 'role', 'joined_at', 'joinedAt'], array_keys($res->json()));
        $res->assertJsonPath('id', $s->id)->assertJsonPath('name', $s->name)->assertJsonPath('role', 'Member');
        $this->assertSame($res->json('joined_at'), $res->json('joinedAt'));
        $this->assertDatabaseHas('class_enrollments', ['class_id' => $this->class->id, 'student_id' => $s->id, 'role' => 'Member']);
    }

    public function test_add_member_resolves_email_case_insensitively_and_by_student_code_prefix(): void
    {
        $byEmail = $this->makeUser('student');
        $code = 'rc' . substr(str_replace('.', '', uniqid('', true)), -8);
        $byCode = $this->makeUser('student', ['email' => "{$code}@gm.uit.edu.vn"]);

        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => strtoupper($byEmail->email)])
            ->assertStatus(201)->assertJsonPath('id', $byEmail->id);
        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => strtoupper($code)])
            ->assertStatus(201)->assertJsonPath('id', $byCode->id);
    }

    public function test_add_member_accepts_email_key_and_first_of_student_ids(): void
    {
        $a = $this->makeUser('student');
        $b = $this->makeUser('student');
        $c = $this->makeUser('student');

        $this->postJson(self::T . "/{$this->class->id}/members", ['email' => $a->email])->assertStatus(201)->assertJsonPath('id', $a->id);
        $this->postJson(self::T . "/{$this->class->id}/members", ['student_ids' => [$b->id, $c->id]])->assertStatus(201)->assertJsonPath('id', $b->id);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->enrolledIds());
    }

    public function test_add_member_error_cases_use_message_and_detail_keys(): void
    {
        $teacher = $this->makeUser('instructor_theory');
        $s = $this->makeUser('student');
        $this->enroll($s);
        $url = self::T . "/{$this->class->id}/members";

        // Dạng lỗi chuẩn của API: {message, detail} (frontend đọc `detail`).
        $cases = [
            [[], 422, 'student_id is required'],
            [['student_ids' => []], 422, 'student_id is required'],
            [['student_id' => '   '], 422, 'student_id is required'],
            [['student_id' => ['x']], 422, 'student_id is required'],
            [['student_id' => 'khong-ton-tai-rc'], 404, 'Không tìm thấy sinh viên.'],
            [['student_id' => $teacher->id], 422, 'Tài khoản này không phải là sinh viên.'],
            [['student_id' => $s->id], 400, 'Sinh viên này đã được thêm vào lớp từ trước.'],
        ];
        foreach ($cases as [$payload, $status, $message]) {
            $this->postJson($url, $payload)->assertStatus($status)
                ->assertExactJson(['message' => $message, 'detail' => $message]);
        }

        $this->assertSame([$s->id], $this->enrolledIds());
    }

    public function test_add_member_does_not_treat_like_wildcards_as_wildcards(): void
    {
        // `%` và `_` trong định danh được escape: "x%" chỉ khớp tiền tố đúng là "x%".
        $tag = 'rcwild' . substr(str_replace('.', '', uniqid('', true)), -6);
        $this->makeUser('student', ['email' => "{$tag}xyz@gm.uit.edu.vn"]);
        $this->makeUser('student', ['email' => "{$tag}_a@gm.uit.edu.vn"]);

        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => "{$tag}%"])->assertStatus(404);
        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => '%'])->assertStatus(404);
        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => "{$tag}_a"])->assertStatus(201);
        $this->assertCount(1, $this->enrolledIds());
    }

    public function test_add_member_by_code_prefix_is_rejected_when_ambiguous(): void
    {
        $tag = 'rcdup' . substr(str_replace('.', '', uniqid('', true)), -6);
        $this->makeUser('student', ['email' => "{$tag}@gm.uit.edu.vn"]);
        $this->makeUser('student', ['email' => "{$tag}@student.uit.edu.vn"]);

        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => $tag])
            ->assertStatus(422)->assertJsonPath('detail', "Có nhiều tài khoản khớp với \"{$tag}\". Hãy nhập email đầy đủ.");
        $this->assertSame([], $this->enrolledIds());

        // Email đầy đủ thì vẫn thêm được.
        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => "{$tag}@student.uit.edu.vn"])->assertStatus(201);
    }

    public function test_exact_id_wins_over_a_code_prefix_match(): void
    {
        $byId = $this->makeUser('student');
        $this->makeUser('student', ['email' => "{$byId->id}@gm.uit.edu.vn"]); // email có tiền tố trùng id

        $this->postJson(self::T . "/{$this->class->id}/members", ['student_id' => $byId->id])
            ->assertStatus(201)->assertJsonPath('id', $byId->id);
    }

    // ───────────────────────── removeMember ─────────────────────────

    public function test_remove_member_by_id_and_by_email(): void
    {
        $a = $this->makeUser('student');
        $b = $this->makeUser('student');
        $keep = $this->makeUser('student');
        foreach ([$a, $b, $keep] as $s) {
            $this->enroll($s);
        }

        $this->deleteJson(self::T . "/{$this->class->id}/members/{$a->id}")->assertOk()->assertExactJson(['message' => 'Member removed successfully']);
        $this->deleteJson(self::T . "/{$this->class->id}/members/" . rawurlencode(strtoupper($b->email)))->assertOk();

        $this->assertSame([$keep->id], $this->enrolledIds());
    }

    public function test_remove_member_not_in_class_is_404_with_detail(): void
    {
        $stranger = $this->makeUser('student');
        $other = $this->makeClass();
        $this->enroll($stranger, $other);

        $this->deleteJson(self::T . "/{$this->class->id}/members/{$stranger->id}")
            ->assertStatus(404)->assertExactJson(['message' => 'Sinh viên không có trong lớp học này.', 'detail' => 'Sinh viên không có trong lớp học này.']);
        $this->deleteJson(self::T . "/{$this->class->id}/members/khong-co-ai")->assertStatus(404);

        $this->assertSame([$stranger->id], $this->enrolledIds($other), 'lớp khác không bị ảnh hưởng');
    }

    // ───────────────────────── /students: add / replace / remove ─────────────────────────

    public function test_students_add_is_idempotent_and_returns_sorted_roster_with_201(): void
    {
        $z = $this->makeUser('student', ['name' => 'RCZ Ba']);
        $a = $this->makeUser('student', ['name' => 'RCA Bốn']);
        $this->enroll($z);

        $res = $this->postJson(self::T . "/{$this->class->id}/students", ['student_ids' => [$z->id, $a->id]])->assertStatus(201);

        $this->assertSame([$a->id, $z->id], array_column($res->json(), 'id'));
        $this->assertEqualsCanonicalizing([$a->id, $z->id], $this->enrolledIds());
        $this->assertDatabaseHas('class_enrollments', ['class_id' => $this->class->id, 'student_id' => $a->id, 'role' => 'Member']);
    }

    /** @return array<string, array{0:array<string,mixed>,1:string}> */
    public static function invalidStudentIds(): array
    {
        return [
            'thiếu student_ids'  => [[], 'student_ids'],
            'mảng rỗng'          => [['student_ids' => []], 'student_ids'],
            'không phải mảng'    => [['student_ids' => 'abc'], 'student_ids'],
            'id không tồn tại'   => [['student_ids' => ['khong-ton-tai']], 'student_ids.0'],
            'id trùng nhau'      => [['student_ids' => ['@s', '@s']], 'student_ids.0'],
            'quá 1000 phần tử'   => [['student_ids' => array_fill(0, 1001, 'x')], 'student_ids'],
        ];
    }

    /**
     * @dataProvider invalidStudentIds
     */
    public function test_students_add_validates_student_ids(array $payload, string $errorKey): void
    {
        $s = $this->makeUser('student');
        $payload = json_decode(str_replace('@s', $s->id, json_encode($payload)), true);
        $this->enroll($this->makeUser('student'));
        $before = $this->enrolledIds();

        $this->postJson(self::T . "/{$this->class->id}/students", $payload)
            ->assertStatus(422)->assertJsonValidationErrors($errorKey, 'errors');

        $this->assertSame($before, $this->enrolledIds());
    }

    public function test_students_replace_validates_student_ids(): void
    {
        $s = $this->makeUser('student');
        $this->enroll($this->makeUser('student'));
        $before = $this->enrolledIds();
        $url = self::T . "/{$this->class->id}/students";

        $this->putJson($url, ['student_ids' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('student_ids', 'errors');
        $this->putJson($url, ['student_ids' => ['khong-ton-tai']])->assertStatus(422)->assertJsonValidationErrors('student_ids.0', 'errors');
        $this->putJson($url, ['student_ids' => [$s->id, $s->id]])->assertStatus(422)->assertJsonValidationErrors('student_ids.0', 'errors');

        $this->assertSame($before, $this->enrolledIds());
    }

    public function test_students_add_rejects_non_student_accounts_atomically(): void
    {
        $s = $this->makeUser('student');
        $teacher = $this->makeUser('instructor_practical');

        $this->postJson(self::T . "/{$this->class->id}/students", ['student_ids' => [$s->id, $teacher->id]])
            ->assertStatus(422)->assertJsonValidationErrors('student_ids', 'errors');
        $this->putJson(self::T . "/{$this->class->id}/students", ['student_ids' => [$teacher->id]])->assertStatus(422);

        $this->assertSame([], $this->enrolledIds());
    }

    public function test_students_replace_swaps_the_whole_roster_and_empty_clears_it(): void
    {
        [$old, $keep, $new] = [$this->makeUser('student'), $this->makeUser('student'), $this->makeUser('student')];
        $this->enroll($old);
        $this->enroll($keep);

        $res = $this->putJson(self::T . "/{$this->class->id}/students", ['student_ids' => [$keep->id, $new->id]])->assertOk();

        $this->assertEqualsCanonicalizing([$keep->id, $new->id], array_column($res->json(), 'id'));
        $this->assertEqualsCanonicalizing([$keep->id, $new->id], $this->enrolledIds());

        $this->putJson(self::T . "/{$this->class->id}/students", ['student_ids' => []])->assertOk()->assertExactJson([]);
        $this->assertSame([], $this->enrolledIds());
    }

    public function test_students_replace_requires_the_key_to_be_present(): void
    {
        $this->enroll($this->makeUser('student'));

        $this->putJson(self::T . "/{$this->class->id}/students", [])->assertStatus(422)->assertJsonValidationErrors('student_ids', 'errors');

        $this->assertCount(1, $this->enrolledIds());
    }

    public function test_students_remove_returns_remaining_roster_or_404(): void
    {
        [$a, $b] = [$this->makeUser('student'), $this->makeUser('student')];
        $this->enroll($a);
        $this->enroll($b);

        $res = $this->deleteJson(self::T . "/{$this->class->id}/students/{$a->id}")->assertOk();

        $this->assertSame([$b->id], array_column($res->json(), 'id'));
        $this->deleteJson(self::T . "/{$this->class->id}/students/{$a->id}")
            ->assertStatus(404)->assertJsonPath('detail', 'Student is not enrolled in this class.');
    }

    // ───────────────────────── import CSV/TXT ─────────────────────────

    public function test_import_requires_a_valid_csv_or_txt_file(): void
    {
        $id = $this->class->id;

        $missing = 'Vui lòng tải lên tệp danh sách sinh viên hợp lệ (.csv hoặc .txt).';
        $unsupported = 'Định dạng tệp không được hỗ trợ. Vui lòng sử dụng tệp .csv hoặc .txt.';
        $this->postJson(self::T . "/{$id}/members/import", [])
            ->assertStatus(422)->assertExactJson(['message' => $missing, 'detail' => $missing]);
        $this->import('a,b', 'sv.xlsx')->assertStatus(422)
            ->assertExactJson(['message' => $unsupported, 'detail' => $unsupported]);
        $this->import('abc', 'sv.pdf')->assertStatus(422);
    }

    public function test_import_empty_file_returns_200_with_file_error(): void
    {
        $this->import("  \n ", 'sv.csv')->assertOk()->assertExactJson([
            'total_processed' => 0, 'success_count' => 0, 'skipped_count' => 0, 'failed_count' => 0,
            'errors' => [['email' => 'file', 'error' => 'Tệp rỗng hoặc không có dữ liệu.']],
        ]);
    }

    public function test_import_result_reports_each_kind_of_problem(): void
    {
        $ok = $this->makeUser('student');
        $already = $this->makeUser('student');
        $this->enroll($already);
        $teacher = $this->makeUser('instructor_practical');

        $res = $this->import(implode("\n", [
            $ok->email, $already->email, $teacher->email, 'nguoi_la_rc@gm.uit.edu.vn', strtoupper($ok->email),
        ]), 'sv.txt')->assertOk();

        $res->assertJsonPath('success_count', 1)->assertJsonPath('skipped_count', 2)->assertJsonPath('failed_count', 2)
            ->assertJsonPath('total_processed', 5);
        $errors = collect($res->json('errors'));
        $this->assertSame('Sinh viên đã tham gia lớp học từ trước.', $errors->firstWhere('email', $already->email)['error']);
        $this->assertSame('Trùng lặp trong tệp tải lên.', $errors->firstWhere('email', strtoupper($ok->email))['error']);
        $this->assertSame("Tài khoản ({$teacher->name}) không phải là sinh viên.", $errors->firstWhere('email', $teacher->email)['error']);
        $this->assertSame('Không tìm thấy người dùng với thông tin: nguoi_la_rc@gm.uit.edu.vn.', $errors->firstWhere('email', 'nguoi_la_rc@gm.uit.edu.vn')['error']);
        $this->assertEqualsCanonicalizing([$already->id, $ok->id], $this->enrolledIds());
        $this->assertSame('Member', DB::table('class_enrollments')->where('student_id', $ok->id)->value('role'));
    }

    public function test_import_csv_uses_email_column_over_mssv_column(): void
    {
        $byEmail = $this->makeUser('student');
        $decoy = $this->makeUser('student');

        // total_processed = 1 chứng tỏ dòng tiêu đề không bị đếm như một sinh viên.
        $this->import("MSSV,Họ tên,Email\n{$decoy->id},Tên,{$byEmail->email}\n")
            ->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('failed_count', 0)->assertJsonPath('total_processed', 1);

        $this->assertSame([$byEmail->id], $this->enrolledIds());
    }

    public function test_import_csv_falls_back_to_mssv_column_when_email_cell_is_blank(): void
    {
        $code = 'rc' . substr(str_replace('.', '', uniqid('', true)), -8);
        $s = $this->makeUser('student', ['email' => "{$code}@gm.uit.edu.vn"]);

        $this->import("Email,MSSV\n,{$code}\n")->assertOk()->assertJsonPath('success_count', 1);

        $this->assertSame([$s->id], $this->enrolledIds());
    }

    /** @return array<string, array{0:string}> */
    public static function delimiters(): array
    {
        return ['dấu phẩy' => [','], 'chấm phẩy' => [';'], 'tab' => ["\t"]];
    }

    /**
     * @dataProvider delimiters
     */
    public function test_import_detects_the_delimiter(string $d): void
    {
        // Dùng cột MSSV (không có email trong dòng) để buộc phải tách cột đúng delimiter,
        // không thể "đoán" bằng cách tìm email trong cả dòng.
        $code = 'rc' . substr(str_replace('.', '', uniqid('', true)), -8);
        $s = $this->makeUser('student', ['email' => "{$code}@gm.uit.edu.vn"]);

        $this->import("STT{$d}MSSV{$d}Tên\n1{$d}{$code}{$d}Tên\n")
            ->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('failed_count', 0)->assertJsonPath('total_processed', 1);

        $this->assertSame([$s->id], $this->enrolledIds());
    }

    public function test_import_headerless_csv_picks_first_email_cell_else_first_code_like_cell(): void
    {
        $a = $this->makeUser('student');
        $code = 'rc' . substr(str_replace('.', '', uniqid('', true)), -8);
        $b = $this->makeUser('student', ['email' => "{$code}@gm.uit.edu.vn"]);

        $res = $this->import("1,Nguyễn Văn A,{$a->email}\n2,Trần B,{$code}\n")->assertOk();

        $res->assertJsonPath('success_count', 2)->assertJsonPath('failed_count', 0);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->enrolledIds());
    }

    public function test_import_comma_or_semicolon_list_on_one_line_keeps_every_email(): void
    {
        [$a, $b, $c] = [$this->makeUser('student'), $this->makeUser('student'), $this->makeUser('student')];

        $this->import("{$a->email}, {$b->email}\n{$c->email}\n", 'sv.txt')->assertOk()
            ->assertJsonPath('success_count', 3)->assertJsonPath('total_processed', 3)->assertJsonPath('failed_count', 0);

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $this->enrolledIds());
    }

    public function test_import_list_of_student_codes_on_one_line_keeps_every_code(): void
    {
        $tag = substr(str_replace('.', '', uniqid('', true)), -6);
        $a = $this->makeUser('student', ['email' => "{$tag}01@gm.uit.edu.vn"]);
        $b = $this->makeUser('student', ['email' => "{$tag}02@gm.uit.edu.vn"]);

        $this->import("{$tag}01; {$tag}02\n", 'sv.txt')->assertOk()
            ->assertJsonPath('success_count', 2)->assertJsonPath('failed_count', 0);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->enrolledIds());
    }

    public function test_import_row_with_a_name_next_to_the_code_does_not_import_the_name(): void
    {
        $tag = substr(str_replace('.', '', uniqid('', true)), -6);
        $s = $this->makeUser('student', ['email' => "{$tag}77@gm.uit.edu.vn"]);

        // "Student" cũng có 6-15 ký tự chữ-số nhưng không có chữ số → không phải mã; hàng không phải danh sách thuần.
        $this->import("1,Student,{$tag}77\n")->assertOk()
            ->assertJsonPath('total_processed', 1)->assertJsonPath('success_count', 1)->assertJsonPath('failed_count', 0);

        $this->assertSame([$s->id], $this->enrolledIds());
    }

    public function test_import_reports_ambiguous_code_instead_of_picking_one(): void
    {
        $tag = 'rcdup' . substr(str_replace('.', '', uniqid('', true)), -6);
        $this->makeUser('student', ['email' => "{$tag}@gm.uit.edu.vn"]);
        $this->makeUser('student', ['email' => "{$tag}@student.uit.edu.vn"]);

        $res = $this->import($tag, 'sv.txt')->assertOk();

        $res->assertJsonPath('failed_count', 1)->assertJsonPath('success_count', 0);
        $this->assertSame("Có nhiều tài khoản khớp với: {$tag}. Hãy dùng email đầy đủ.", $res->json('errors.0.error'));
        $this->assertSame([], $this->enrolledIds());
    }

    public function test_import_does_not_treat_like_wildcards_as_wildcards(): void
    {
        $tag = 'rcwild' . substr(str_replace('.', '', uniqid('', true)), -6);
        $this->makeUser('student', ['email' => "{$tag}xyz@gm.uit.edu.vn"]);

        $this->import("{$tag}%", 'sv.txt')->assertOk()->assertJsonPath('failed_count', 1)->assertJsonPath('success_count', 0);

        $this->assertSame([], $this->enrolledIds());
    }

    public function test_import_txt_accepts_quoted_and_embedded_emails(): void
    {
        $c = $this->makeUser('student');

        $this->import("\"{$c->email}\"\n", 'sv.txt')->assertOk()->assertJsonPath('success_count', 1);
        $this->assertSame([$c->id], $this->enrolledIds());

        $d = $this->makeUser('student');
        $this->import("Danh sách: <{$d->email}> (SV)\n", 'sv.txt')->assertOk()->assertJsonPath('success_count', 1);
    }

    public function test_import_txt_tokens_without_emails_are_looked_up_by_id_or_code(): void
    {
        $s = $this->makeUser('student');

        $this->import("email\nstt\n{$s->id}\n", 'sv.txt')->assertOk()
            ->assertJsonPath('success_count', 1)->assertJsonPath('failed_count', 0);
    }

    public function test_import_strips_utf8_bom_and_skips_blank_lines(): void
    {
        $s = $this->makeUser('student');

        $this->import("\xEF\xBB\xBFEmail\r\n\r\n{$s->email}\r\n\r\n")->assertOk()
            ->assertJsonPath('total_processed', 1)->assertJsonPath('success_count', 1);
    }

    public function test_import_rejects_xlsx_renamed_to_csv_and_does_not_enroll(): void
    {
        $this->import("PK\x03\x04" . str_repeat("\0", 40))->assertStatus(422)->assertJsonStructure(['detail']);

        $this->assertSame([], $this->enrolledIds());
    }

    public function test_import_only_touches_the_requested_class(): void
    {
        $other = $this->makeClass();
        $s = $this->makeUser('student');

        $this->import($s->email)->assertOk();

        $this->assertSame([], $this->enrolledIds($other));
    }

    // ───────────────────────── reset-password ─────────────────────────

    public function test_an_account_with_class_manage_and_the_reset_permission_resets_any_class_and_revokes_tokens(): void
    {
        $this->grantPasswordResetToAdmin();
        $s = $this->makeUser('student');
        $this->enroll($s);
        $s->createToken('t');

        $this->postJson(self::T . "/{$this->class->id}/students/{$s->id}/reset-password", ['password' => 'MatKhauMoi1'])
            ->assertOk()->assertExactJson(['message' => 'Student password reset successfully']);

        $s->refresh();
        $this->assertTrue(Hash::check('MatKhauMoi1', $s->hashed_password));
        $this->assertSame(0, $s->tokens()->count());
    }

    public function test_owner_practical_instructor_can_reset_but_not_for_other_classes(): void
    {
        $mine = $this->makeUser('student');
        $theirs = $this->makeUser('student');
        $otherClass = $this->makeClass($this->makeInstructor());
        $this->enroll($mine);
        $this->enroll($theirs, $otherClass);
        Sanctum::actingAs($this->owner);

        $this->postJson(self::T . "/{$this->class->id}/students/{$mine->id}/reset-password", ['password' => 'MatKhauMoi1'])->assertOk();
        $this->postJson(self::T . "/{$otherClass->id}/students/{$theirs->id}/reset-password", ['password' => 'MatKhauMoi1'])->assertStatus(404);

        $this->assertTrue(Hash::check('password123', $theirs->refresh()->hashed_password));
    }

    public function test_only_admin_and_practical_instructors_with_the_permission_may_reset_passwords(): void
    {
        $s = $this->makeUser('student');
        $this->enroll($s);

        // Không có quyền student.reset_password: bị chặn ở route.
        foreach (['department_head', 'faculty_office', 'student'] as $role) {
            Sanctum::actingAs($this->makeUser($role));
            $this->postJson(self::T . "/{$this->class->id}/students/{$s->id}/reset-password", ['password' => 'MatKhauMoi1'])->assertStatus(403);
        }
        // Có quyền nhưng không dạy lớp này (hoặc chỉ dạy lý thuyết): không thấy lớp.
        Sanctum::actingAs($this->makeInstructor('instructor_theory'));
        $this->postJson(self::T . "/{$this->class->id}/students/{$s->id}/reset-password", ['password' => 'MatKhauMoi1'])->assertStatus(404);

        $this->assertTrue(Hash::check('password123', $s->refresh()->hashed_password));
    }

    public function test_the_admin_role_alone_cannot_reset_passwords(): void
    {
        $s = $this->makeUser('student');
        $this->enroll($s);

        $this->postJson(self::T . "/{$this->class->id}/students/{$s->id}/reset-password", ['password' => 'MatKhauMoi1'])->assertStatus(403);
        $this->assertTrue(Hash::check('password123', $s->refresh()->hashed_password));
    }

    public function test_reset_password_validation_and_enrollment_check(): void
    {
        $this->grantPasswordResetToAdmin();
        $s = $this->makeUser('student');
        $stranger = $this->makeUser('student');
        $this->enroll($s);
        $url = fn ($id) => self::T . "/{$this->class->id}/students/{$id}/reset-password";

        $this->postJson($url($stranger->id), ['password' => 'MatKhauMoi1'])->assertStatus(422)->assertJsonValidationErrors('student_id', 'errors');
        $this->postJson($url($s->id), [])->assertStatus(422)->assertJsonValidationErrors('password', 'errors');
        $this->postJson($url($s->id), ['password' => 'ngan'])->assertStatus(422)->assertJsonValidationErrors('password', 'errors');

        $this->assertTrue(Hash::check('password123', $s->refresh()->hashed_password));
    }
}
