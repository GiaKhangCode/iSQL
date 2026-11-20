<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\ActivityLog;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Test đặc tả (characterization) cho App\Http\Controllers\Admin\ClassController,
 * viết TRƯỚC khi tách ActivityLog + validate ra khỏi controller.
 * Bổ sung cho ClassInstructorAllocationTest (tập trung vào phân công giảng viên).
 *
 * Chuỗi ActivityLog là HỢP ĐỒNG với frontend (OverviewPage.tsx đọc "Created class X",
 * "Updated class X" bằng regex) nên được khóa chính xác.
 */
class AdminClassControllerTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Giảng viên mặc định phải có class.teach như AccessRoleSeeder, bất kể DB dev đã được sửa hay chưa.
        $this->useDefaultRolePermissions();
        $this->admin = $this->makeUser('admin');
        Sanctum::actingAs($this->admin);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function makeUser(string $role, string $status = 'Active'): User
    {
        return User::create([
            'name'            => 'AC ' . $role . ' ' . uniqid(),
            'email'           => 'ac_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => $status,
            'initials'        => 'AC',
        ]);
    }

    private function makeClass(array $attrs = []): ClassModel
    {
        return ClassModel::create(array_merge([
            'id'     => 'AC_' . strtoupper(uniqid()),
            'course' => 'AC Course ' . uniqid(),
            'term'   => 'AC Term ' . uniqid(),
            'status' => 'Active',
        ], $attrs));
    }

    private function makeCourse(): Course
    {
        return Course::create(['name' => 'AC Catalog Course ' . uniqid(), 'code' => 'ACC' . strtoupper(substr(uniqid(), -6))]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create(['name' => 'AC Catalog Term ' . uniqid()]);
    }

    private function logCount(string $action): int
    {
        return ActivityLog::where('action', $action)->count();
    }

    private function assertLogged(string $action): void
    {
        $this->assertSame(1, $this->logCount($action), "Phải có đúng 1 ActivityLog: {$action}");
        $this->assertSame($this->admin->id, ActivityLog::where('action', $action)->first()->user_id);
    }

    // ───────────────────────── truy cập ─────────────────────────

    /** @return array<string, array{0:string,1:string}> */
    public static function adminClassRoutes(): array
    {
        return [
            'index'   => ['GET', '/api/admin/classes'],
            'store'   => ['POST', '/api/admin/classes'],
            'show'    => ['GET', '/api/admin/classes/x'],
            'update'  => ['PUT', '/api/admin/classes/x'],
            'patch'   => ['PATCH', '/api/admin/classes/x'],
            'destroy' => ['DELETE', '/api/admin/classes/x'],
            'assign'  => ['PUT', '/api/admin/classes/x/instructor'],
        ];
    }

    /**
     * @dataProvider adminClassRoutes
     */
    public function test_non_admin_roles_are_forbidden(string $method, string $uri): void
    {
        foreach (['student', 'instructor_practical', 'department_head', 'faculty_office'] as $role) {
            Sanctum::actingAs($this->makeUser($role));
            $this->json($method, $uri, [])->assertStatus(403);
        }
    }

    public function test_guest_gets_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/classes')->assertStatus(401);
    }

    // ───────────────────────── index / show ─────────────────────────

    public function test_index_lists_newest_first_with_frontend_shape(): void
    {
        $old = $this->makeClass(['created_at' => '2020-01-01 00:00:00']);
        $new = $this->makeClass(['created_at' => '2030-01-01 00:00:00']);

        $res = $this->getJson('/api/admin/classes')->assertOk();

        $ids = array_column($res->json(), 'id');
        $this->assertLessThan(array_search($old->id, $ids), array_search($new->id, $ids), 'lớp mới hơn phải đứng trước');
        $this->assertCount(ClassModel::count(), $ids);

        $row = collect($res->json())->firstWhere('id', $new->id);
        foreach ([
            'id', 'course', 'course_code', 'theory_class', 'practice_class', 'lecturer', 'lecturer_id', 'lecturerId',
            'students', 'status', 'semester', 'term', 'dates',
            'start_date', 'startDate', 'end_date', 'endDate',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, $key);
        }
        // Một lớp một giảng viên: không còn trường giảng viên thực hành riêng.
        $this->assertArrayNotHasKey('practice_instructor_id', $row);
        $this->assertArrayNotHasKey('practice_lecturer', $row);
    }

    public function test_row_values_for_unassigned_class_without_dates(): void
    {
        $class = $this->makeClass(['term' => 'HK Test']);

        $row = $this->getJson("/api/admin/classes/{$class->id}")->assertOk()->json();

        $this->assertSame('Unassigned', $row['lecturer']);
        $this->assertNull($row['lecturer_id']);
        $this->assertSame($row['lecturer_id'], $row['lecturerId']);
        $this->assertSame(0, $row['students']);
        $this->assertSame('HK Test', $row['semester']);
        $this->assertSame('HK Test', $row['term']);
        $this->assertSame('Active', $row['status']);
        $this->assertSame('Sep 07 – Nov 28, 2026', $row['dates'], 'giá trị mặc định khi thiếu ngày');
        $this->assertNull($row['start_date']);
        $this->assertNull($row['endDate']);
        $this->assertSame($class->id, $row['theory_class'], 'theory_class mặc định = id');
        $this->assertSame($class->id, $row['practice_class']);
        $this->assertSame('', $row['course_code']);
    }

    public function test_row_values_with_dates_instructor_and_students(): void
    {
        $course = $this->makeCourse();
        $instructor = $this->makeUser('instructor_practical');
        $class = $this->makeClass([
            'course'        => $course->name,
            'course_id'     => $course->id,
            'instructor_id' => $instructor->id,
            'start_date'    => '2026-09-01',
            'end_date'      => '2026-12-01',
        ]);
        foreach ([$this->makeUser('student'), $this->makeUser('student')] as $s) {
            ClassEnrollment::create(['class_id' => $class->id, 'student_id' => $s->id, 'role' => 'Member']);
        }

        $row = $this->getJson("/api/admin/classes/{$class->id}")->assertOk()->json();

        $this->assertSame($instructor->name, $row['lecturer']);
        $this->assertSame($instructor->id, $row['lecturer_id']);
        $this->assertSame(2, $row['students']);
        $this->assertSame('2026-09-01 – 2026-12-01', $row['dates']);
        $this->assertSame('2026-09-01', $row['start_date']);
        $this->assertSame('2026-09-01', $row['startDate']);
        $this->assertSame('2026-12-01', $row['end_date']);
        $this->assertSame('2026-12-01', $row['endDate']);
        $this->assertSame($course->code, $row['course_code']);
    }

    public function test_response_does_not_leak_password_hashes(): void
    {
        $instructor = $this->makeUser('instructor_practical');
        $class = $this->makeClass(['instructor_id' => $instructor->id]);
        ClassEnrollment::create(['class_id' => $class->id, 'student_id' => $this->makeUser('student')->id, 'role' => 'Member']);

        $this->assertStringNotContainsString('hashed_password', $this->getJson("/api/admin/classes/{$class->id}")->getContent());
        $this->assertStringNotContainsString('hashed_password', $this->getJson('/api/admin/classes')->getContent());
    }

    public function test_show_unknown_class_is_404_json(): void
    {
        $this->getJson('/api/admin/classes/khong-ton-tai')->assertStatus(404)->assertJsonStructure(['message', 'detail']);
    }

    // ───────────────────────── store ─────────────────────────

    public function test_store_minimal_payload_generates_id_defaults_and_logs(): void
    {
        $name = 'AC Khóa ' . uniqid();

        $res = $this->postJson('/api/admin/classes', ['course' => $name, 'term' => 'HK1'])->assertStatus(201);

        $id = $res->json('id');
        $this->assertNotEmpty($id);
        $res->assertJsonPath('course', $name)
            ->assertJsonPath('term', 'HK1')
            ->assertJsonPath('status', 'Active')
            ->assertJsonPath('lecturer', 'Unassigned')
            ->assertJsonPath('students', 0);
        $this->assertDatabaseHas('classes', ['id' => $id, 'course' => $name, 'term' => 'HK1', 'instructor_id' => null]);
        $this->assertLogged("Created class {$id}");
    }

    public function test_store_with_frontend_payload_shape(): void
    {
        // CoursePages.tsx: { id, course, semester: term, lecturerId: "Unassigned" }
        $id = 'AC_' . strtoupper(uniqid());

        $res = $this->postJson('/api/admin/classes', [
            'id' => $id, 'course' => 'Cơ sở dữ liệu', 'semester' => 'HK2 2026', 'lecturerId' => 'Unassigned',
        ])->assertStatus(201);

        $res->assertJsonPath('id', $id)->assertJsonPath('term', 'HK2 2026')->assertJsonPath('semester', 'HK2 2026');
        $this->assertDatabaseHas('classes', ['id' => $id, 'term' => 'HK2 2026', 'instructor_id' => null]);
        $this->assertLogged("Created class {$id}");
    }

    public function test_store_rejects_duplicate_id_without_logging(): void
    {
        $existing = $this->makeClass();

        $res = $this->postJson('/api/admin/classes', ['id' => $existing->id, 'course' => 'X', 'term' => 'Y'])
            ->assertStatus(422);

        $res->assertJsonValidationErrors('id', 'errors');
        $this->assertSame(0, $this->logCount("Created class {$existing->id}"));
    }

    public function test_store_resolves_catalog_ids_from_names_and_names_from_ids(): void
    {
        $course = $this->makeCourse();
        $term = $this->makeTerm();

        // theo tên → course_id/term_id được tra từ danh mục
        $byName = $this->postJson('/api/admin/classes', ['course' => $course->name, 'term' => $term->name])->assertStatus(201);
        $this->assertDatabaseHas('classes', ['id' => $byName->json('id'), 'course_id' => $course->id, 'term_id' => $term->id]);

        // theo id → tên lấy từ danh mục
        $byId = $this->postJson('/api/admin/classes', ['course_id' => $course->id, 'term_id' => $term->id])->assertStatus(201);
        $byId->assertJsonPath('course', $course->name)->assertJsonPath('term', $term->name)->assertJsonPath('course_code', $course->code);

        // tên lạ → không có id danh mục
        $free = $this->postJson('/api/admin/classes', ['course' => 'AC Lạ ' . uniqid(), 'term' => 'AC Kỳ lạ ' . uniqid()])->assertStatus(201);
        $this->assertDatabaseHas('classes', ['id' => $free->json('id'), 'course_id' => null, 'term_id' => null]);
    }

    public function test_store_rejects_catalog_id_that_contradicts_the_name(): void
    {
        $course = $this->makeCourse();
        $term = $this->makeTerm();
        $before = ClassModel::count();

        $this->postJson('/api/admin/classes', ['course_id' => $course->id, 'course' => 'Tên khác', 'term' => 'T'])
            ->assertStatus(422)->assertJsonValidationErrors('course_id', 'errors');
        $this->postJson('/api/admin/classes', ['course' => 'C', 'term_id' => $term->id, 'term' => 'Tên khác'])
            ->assertStatus(422)->assertJsonValidationErrors('term_id', 'errors');

        $this->assertSame($before, ClassModel::count());
    }

    public function test_store_accepts_camel_case_date_aliases(): void
    {
        $res = $this->postJson('/api/admin/classes', [
            'course' => 'C', 'term' => 'T', 'startDate' => '2026-09-07', 'endDate' => '2026-11-28',
        ])->assertStatus(201);

        $res->assertJsonPath('start_date', '2026-09-07')->assertJsonPath('end_date', '2026-11-28')
            ->assertJsonPath('dates', '2026-09-07 – 2026-11-28');
    }

    public function test_store_end_date_must_not_precede_start_date_for_both_key_styles(): void
    {
        $before = ClassModel::count();

        $this->postJson('/api/admin/classes', [
            'course' => 'C', 'term' => 'T', 'start_date' => '2026-12-01', 'end_date' => '2026-09-01',
        ])->assertStatus(422)->assertJsonValidationErrors('end_date', 'errors');

        // Frontend dùng camelCase: trước đây không bị kiểm tra.
        $this->postJson('/api/admin/classes', [
            'course' => 'C', 'term' => 'T', 'startDate' => '2026-12-01', 'endDate' => '2026-09-01',
        ])->assertStatus(422)->assertJsonValidationErrors('end_date', 'errors');

        // Trộn hai kiểu tên cũng bị bắt.
        $this->postJson('/api/admin/classes', [
            'course' => 'C', 'term' => 'T', 'start_date' => '2026-12-01', 'endDate' => '2026-09-01',
        ])->assertStatus(422);

        $this->assertSame($before, ClassModel::count());
    }

    public function test_store_allows_same_day_start_and_end_and_open_ended_ranges(): void
    {
        $this->postJson('/api/admin/classes', ['course' => 'C', 'term' => 'T', 'startDate' => '2026-09-01', 'endDate' => '2026-09-01'])->assertStatus(201);
        $this->postJson('/api/admin/classes', ['course' => 'C', 'term' => 'T', 'startDate' => '2026-09-01'])->assertStatus(201);
        $this->postJson('/api/admin/classes', ['course' => 'C', 'term' => 'T', 'endDate' => '2026-09-01'])->assertStatus(201);
    }

    /** @return array<string, array{0:array<string,mixed>,1:string}> */
    public static function invalidStorePayloads(): array
    {
        return [
            'thiếu course và course_id' => [['term' => 'T'], 'course'],
            'thiếu term/semester/term_id' => [['course' => 'C'], 'term'],
            'course_id không tồn tại'   => [['course_id' => 'nope', 'term' => 'T'], 'course_id'],
            'term_id không tồn tại'     => [['course' => 'C', 'term_id' => 'nope'], 'term_id'],
            'status không hợp lệ'       => [['course' => 'C', 'term' => 'T', 'status' => 'Closed'], 'status'],
            'start_date không phải ngày' => [['course' => 'C', 'term' => 'T', 'start_date' => 'abc'], 'start_date'],
            'course quá 255 ký tự'      => [['course' => str_repeat('x', 256), 'term' => 'T'], 'course'],
        ];
    }

    /**
     * @dataProvider invalidStorePayloads
     */
    public function test_store_rejects_invalid_payload_without_side_effects(array $payload, string $errorField): void
    {
        $classes = ClassModel::count();
        $logs = ActivityLog::count();

        $res = $this->postJson('/api/admin/classes', $payload)->assertStatus(422);

        $res->assertJsonValidationErrors($errorField, 'errors');
        $this->assertNotEmpty($res->json('detail'));
        $this->assertSame($classes, ClassModel::count());
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_store_with_lecturer_sets_the_class_instructor(): void
    {
        $instructor = $this->makeUser('instructor_practical');

        $res = $this->postJson('/api/admin/classes', ['course' => 'C', 'term' => 'T', 'lecturer_id' => $instructor->id])
            ->assertStatus(201);

        $id = $res->json('id');
        $res->assertJsonPath('lecturer_id', $instructor->id)->assertJsonPath('lecturer', $instructor->name);
        $this->assertDatabaseHas('classes', ['id' => $id, 'instructor_id' => $instructor->id]);
    }

    public function test_store_ignores_a_legacy_assignment_type_field(): void
    {
        $instructor = $this->makeUser('instructor_theory');

        $res = $this->postJson('/api/admin/classes', [
            'course' => 'C', 'term' => 'T', 'instructor_id' => $instructor->id, 'assignment_type' => 'theory',
        ])->assertStatus(201);

        $this->assertDatabaseHas('classes', ['id' => $res->json('id'), 'instructor_id' => $instructor->id]);
        $res->assertJsonPath('lecturer_id', $instructor->id);
    }

    public function test_store_lecturer_aliases_are_all_accepted(): void
    {
        $instructor = $this->makeUser('instructor_practical');

        foreach (['lecturer_id', 'lecturerId', 'instructor_id'] as $key) {
            $this->postJson('/api/admin/classes', ['course' => 'C', 'term' => 'T', $key => $instructor->id])
                ->assertStatus(201)->assertJsonPath('lecturer_id', $instructor->id);
        }
    }

    public function test_store_with_invalid_lecturer_is_422_and_creates_nothing(): void
    {
        $student = $this->makeUser('student');
        $inactive = $this->makeUser('instructor_practical', 'Inactive');
        $classes = ClassModel::count();
        $logs = ActivityLog::count();

        foreach ([$student->id, $inactive->id, 'khong-co-user-nay'] as $lecturerId) {
            $this->postJson('/api/admin/classes', ['course' => 'C', 'term' => 'T', 'lecturer_id' => $lecturerId])
                ->assertStatus(422)->assertJsonValidationErrors('instructor_id', 'errors');
        }

        $this->assertSame($classes, ClassModel::count(), 'không được để lại lớp dở dang');
        $this->assertSame($logs, ActivityLog::count());
        $this->assertSame(0, ClassModel::whereIn('instructor_id', [$student->id, $inactive->id])->count());
    }

    public function test_store_with_invalid_custom_id_and_lecturer_does_not_reserve_the_id(): void
    {
        $student = $this->makeUser('student');
        $id = 'AC_' . strtoupper(uniqid());

        $this->postJson('/api/admin/classes', ['id' => $id, 'course' => 'C', 'term' => 'T', 'lecturer_id' => $student->id])->assertStatus(422);

        $this->assertDatabaseMissing('classes', ['id' => $id]);
        $this->postJson('/api/admin/classes', ['id' => $id, 'course' => 'C', 'term' => 'T'])->assertStatus(201);
    }

    // ───────────────────────── update ─────────────────────────

    public function test_update_changes_only_sent_fields_and_logs(): void
    {
        $class = $this->makeClass(['course' => 'Giữ nguyên', 'term' => 'Cũ', 'status' => 'Active']);

        // CoursePages.tsx: { semester: newSemester }
        $res = $this->putJson("/api/admin/classes/{$class->id}", ['semester' => 'Mới'])->assertOk();

        $res->assertJsonPath('term', 'Mới')->assertJsonPath('semester', 'Mới')->assertJsonPath('course', 'Giữ nguyên')
            ->assertJsonPath('status', 'Active');
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'course' => 'Giữ nguyên', 'term' => 'Mới']);
        $this->assertLogged("Updated class {$class->id}");
    }

    public function test_patch_alias_behaves_like_put(): void
    {
        $class = $this->makeClass();

        $this->patchJson("/api/admin/classes/{$class->id}", ['status' => 'Archived'])->assertOk()->assertJsonPath('status', 'Archived');
    }

    public function test_update_full_frontend_payload(): void
    {
        // CoursePages.tsx: { course, semester, lecturerId, status, startDate, endDate }
        $instructor = $this->makeUser('instructor_practical');
        $class = $this->makeClass();

        $res = $this->putJson("/api/admin/classes/{$class->id}", [
            'course' => 'Môn mới', 'semester' => 'HK3', 'lecturerId' => $instructor->id,
            'status' => 'Draft', 'startDate' => '2026-02-01', 'endDate' => '2026-06-01',
        ])->assertOk();

        $res->assertJsonPath('course', 'Môn mới')->assertJsonPath('term', 'HK3')->assertJsonPath('status', 'Draft')
            ->assertJsonPath('lecturer_id', $instructor->id)->assertJsonPath('start_date', '2026-02-01')
            ->assertJsonPath('end_date', '2026-06-01');
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'instructor_id' => $instructor->id]);
    }

    public function test_update_renaming_course_reresolves_catalog_id(): void
    {
        $course = $this->makeCourse();
        $class = $this->makeClass(['course' => $course->name, 'course_id' => $course->id]);

        $this->putJson("/api/admin/classes/{$class->id}", ['course' => 'AC Không có trong danh mục ' . uniqid()])->assertOk();
        $this->assertNull(ClassModel::find($class->id)->course_id);

        $this->putJson("/api/admin/classes/{$class->id}", ['course' => $course->name])->assertOk();
        $this->assertSame($course->id, ClassModel::find($class->id)->course_id);
    }

    public function test_update_with_catalog_id_replaces_name_and_rejects_contradiction(): void
    {
        $course = $this->makeCourse();
        $class = $this->makeClass();

        $this->putJson("/api/admin/classes/{$class->id}", ['course_id' => $course->id])->assertOk()->assertJsonPath('course', $course->name);

        $this->putJson("/api/admin/classes/{$class->id}", ['course_id' => $course->id, 'course' => 'Sai tên'])
            ->assertStatus(422)->assertJsonValidationErrors('course_id', 'errors');
    }

    public function test_update_unassigned_lecturer_clears_the_instructor(): void
    {
        $practical = $this->makeUser('instructor_practical');
        $class = $this->makeClass(['instructor_id' => $practical->id]);

        $res = $this->putJson("/api/admin/classes/{$class->id}", ['lecturerId' => 'Unassigned'])->assertOk();

        $res->assertJsonPath('lecturer', 'Unassigned')->assertJsonPath('lecturer_id', null);
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'instructor_id' => null]);
    }

    public function test_update_without_lecturer_keys_keeps_the_instructor(): void
    {
        $practical = $this->makeUser('instructor_practical');
        $class = $this->makeClass(['instructor_id' => $practical->id]);

        $this->putJson("/api/admin/classes/{$class->id}", ['status' => 'Archived'])->assertOk()->assertJsonPath('lecturer_id', $practical->id);

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'instructor_id' => $practical->id]);
    }

    public function test_update_with_non_instructor_lecturer_is_422_and_changes_nothing(): void
    {
        $class = $this->makeClass(['term' => 'Nguyên']);
        $student = $this->makeUser('student');

        $this->putJson("/api/admin/classes/{$class->id}", ['semester' => 'Đổi', 'lecturerId' => $student->id])
            ->assertStatus(422);

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'term' => 'Nguyên']);
        $this->assertSame(0, $this->logCount("Updated class {$class->id}"));
    }

    public function test_update_cannot_change_the_class_id(): void
    {
        $class = $this->makeClass();

        $this->putJson("/api/admin/classes/{$class->id}", ['id' => 'DOI-ID', 'status' => 'Draft'])->assertOk()->assertJsonPath('id', $class->id);

        $this->assertDatabaseMissing('classes', ['id' => 'DOI-ID']);
    }

    public function test_update_rejects_end_before_start_for_both_key_styles(): void
    {
        $class = $this->makeClass();
        $logs = ActivityLog::count();

        $this->putJson("/api/admin/classes/{$class->id}", ['start_date' => '2026-12-01', 'end_date' => '2026-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date', 'errors');
        $this->putJson("/api/admin/classes/{$class->id}", ['startDate' => '2026-12-01', 'endDate' => '2026-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date', 'errors');

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'start_date' => null, 'end_date' => null]);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_update_compares_a_single_date_with_the_stored_one(): void
    {
        $class = $this->makeClass(['start_date' => '2026-09-01', 'end_date' => '2026-12-01']);

        // Chỉ gửi đầu mới, so với đầu còn lại đang lưu.
        $this->putJson("/api/admin/classes/{$class->id}", ['end_date' => '2026-01-01'])->assertStatus(422);
        $this->putJson("/api/admin/classes/{$class->id}", ['startDate' => '2027-01-01'])->assertStatus(422);
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'start_date' => '2026-09-01', 'end_date' => '2026-12-01']);

        // Hợp lệ: dời cả hai, hoặc xóa một đầu (null).
        $this->putJson("/api/admin/classes/{$class->id}", ['start_date' => '2027-01-01', 'end_date' => '2027-05-01'])->assertOk();
        $this->putJson("/api/admin/classes/{$class->id}", ['end_date' => null])->assertOk()->assertJsonPath('end_date', null);
        $this->putJson("/api/admin/classes/{$class->id}", ['start_date' => '2030-01-01'])->assertOk();
    }

    public function test_update_with_invalid_dates_and_lecturer_changes_nothing(): void
    {
        $instructor = $this->makeUser('instructor_practical');
        $class = $this->makeClass();

        $this->putJson("/api/admin/classes/{$class->id}", [
            'lecturerId' => $instructor->id, 'start_date' => '2026-12-01', 'end_date' => '2026-01-01',
        ])->assertStatus(422);

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'instructor_id' => null]);
    }

    /** @return array<string, array{0:array<string,mixed>,1:string}> */
    public static function invalidUpdatePayloads(): array
    {
        return [
            'course rỗng'         => [['course' => ''], 'course'],
            'term rỗng'           => [['term' => ''], 'term'],
            'status sai'          => [['status' => 'Closed'], 'status'],
            'course_id không có'  => [['course_id' => 'nope'], 'course_id'],
            'term_id không có'    => [['term_id' => 'nope'], 'term_id'],
            'end_date sai định dạng' => [['end_date' => 'abc'], 'end_date'],
        ];
    }

    /**
     * @dataProvider invalidUpdatePayloads
     */
    public function test_update_rejects_invalid_payload_without_side_effects(array $payload, string $errorField): void
    {
        $class = $this->makeClass(['course' => 'Gốc', 'term' => 'Gốc']);
        $logs = ActivityLog::count();

        $this->putJson("/api/admin/classes/{$class->id}", $payload)
            ->assertStatus(422)->assertJsonValidationErrors($errorField, 'errors');

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'course' => 'Gốc', 'term' => 'Gốc']);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_update_unknown_class_is_404_without_log(): void
    {
        $logs = ActivityLog::count();

        $this->putJson('/api/admin/classes/khong-ton-tai', ['status' => 'Draft'])->assertStatus(404)->assertJsonStructure(['message', 'detail']);

        $this->assertSame($logs, ActivityLog::count());
    }

    // ───────────────────────── assignInstructor ─────────────────────────

    public function test_assign_instructor_sets_the_class_instructor_and_logs(): void
    {
        $instructor = $this->makeUser('instructor_practical');
        $class = $this->makeClass();

        $this->postJson("/api/admin/classes/{$class->id}/instructor", ['instructor_id' => $instructor->id])->assertOk();

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'instructor_id' => $instructor->id]);
        $this->assertLogged("Assigned instructor {$instructor->id} to class {$class->id}");
    }

    public function test_assign_instructor_replaces_the_previous_instructor(): void
    {
        [$a, $b] = [$this->makeUser('instructor_practical'), $this->makeUser('instructor_theory')];
        $class = $this->makeClass();

        $this->putJson("/api/admin/classes/{$class->id}/instructor", ['instructor_id' => $a->id])->assertOk();
        $res = $this->putJson("/api/admin/classes/{$class->id}/instructor", ['instructor_id' => $b->id])->assertOk();

        $res->assertJsonPath('lecturer_id', $b->id);
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'instructor_id' => $b->id]);
    }

    public function test_assign_instructor_accepts_the_three_id_aliases(): void
    {
        $instructor = $this->makeUser('instructor_practical');
        $class = $this->makeClass();

        foreach (['instructor_id', 'lecturer_id', 'lecturerId'] as $key) {
            $this->putJson("/api/admin/classes/{$class->id}/instructor", [$key => $instructor->id])
                ->assertOk()->assertJsonPath('lecturer_id', $instructor->id);
        }
    }

    public function test_assign_instructor_ignores_a_legacy_assignment_type_field(): void
    {
        $instructor = $this->makeUser('instructor_practical');
        $class = $this->makeClass();

        foreach (['theory', 'practical', 'lab'] as $type) {
            $this->putJson("/api/admin/classes/{$class->id}/instructor", ['instructor_id' => $instructor->id, 'assignment_type' => $type])
                ->assertOk()->assertJsonPath('lecturer_id', $instructor->id);
        }
    }

    public function test_assign_instructor_requires_an_id(): void
    {
        $class = $this->makeClass();

        $res = $this->putJson("/api/admin/classes/{$class->id}/instructor", [])->assertStatus(422);

        $res->assertJsonPath('detail', 'Cần cung cấp instructor_id hoặc lecturer_id.');
        $this->assertSame(0, ActivityLog::where('action', 'like', "Assigned instructor % to class {$class->id}")->count());
    }

    public function test_assign_unassigned_removes_the_instructor(): void
    {
        $instructor = $this->makeUser('instructor_practical');
        $class = $this->makeClass();
        $this->putJson("/api/admin/classes/{$class->id}/instructor", ['instructor_id' => $instructor->id])->assertOk();

        $this->putJson("/api/admin/classes/{$class->id}/instructor", ['instructor_id' => 'Unassigned'])
            ->assertOk()->assertJsonPath('lecturer', 'Unassigned');

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'instructor_id' => null]);
        $this->assertLogged("Assigned instructor Unassigned to class {$class->id}");
    }

    public function test_assign_instructor_rejects_inactive_and_unknown_class(): void
    {
        $class = $this->makeClass();
        $inactive = $this->makeUser('instructor_practical', 'Inactive');
        $instructor = $this->makeUser('instructor_practical');

        $this->putJson("/api/admin/classes/{$class->id}/instructor", ['instructor_id' => $inactive->id])->assertStatus(422);
        $this->putJson('/api/admin/classes/khong-ton-tai/instructor', ['instructor_id' => $instructor->id])->assertStatus(404);

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'instructor_id' => null]);
        $this->assertSame(0, ActivityLog::where('action', 'like', "Assigned instructor % to class {$class->id}")->count());
    }

    // ───────────────────────── destroy ─────────────────────────

    public function test_destroy_deletes_class_and_cascades_children(): void
    {
        $instructor = $this->makeUser('instructor_practical');
        $student = $this->makeUser('student');
        $class = $this->makeClass();
        $this->putJson("/api/admin/classes/{$class->id}/instructor", ['instructor_id' => $instructor->id])->assertOk();
        ClassEnrollment::create(['class_id' => $class->id, 'student_id' => $student->id, 'role' => 'Member']);

        $this->deleteJson("/api/admin/classes/{$class->id}")->assertOk()->assertExactJson(['message' => 'Class deleted successfully']);

        $this->assertDatabaseMissing('classes', ['id' => $class->id]);
        $this->assertDatabaseMissing('class_enrollments', ['class_id' => $class->id]);
        $this->assertDatabaseHas('users', ['id' => $instructor->id]);
        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertLogged("Deleted class {$class->id}");
    }

    public function test_destroy_unknown_class_is_404_without_log(): void
    {
        $logs = ActivityLog::count();

        $this->deleteJson('/api/admin/classes/khong-ton-tai')->assertStatus(404)->assertJsonStructure(['message', 'detail']);

        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_destroy_only_removes_the_requested_class(): void
    {
        [$a, $b] = [$this->makeClass(), $this->makeClass()];

        $this->deleteJson("/api/admin/classes/{$a->id}")->assertOk();

        $this->assertDatabaseHas('classes', ['id' => $b->id]);
    }
}
