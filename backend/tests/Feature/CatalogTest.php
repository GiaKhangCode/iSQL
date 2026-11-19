<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Test đặc tả (characterization) cho danh mục Môn học / Học kỳ:
 *   - /api/admin/courses, /api/admin/terms        (Admin\CourseController, Admin\AcademicTermController)
 *   - /api/teacher/courses, /api/teacher/terms    (Teacher\CatalogController)
 *
 * Mục đích: khóa hành vi hiện tại trước khi tách logic sang service (lộ trình 1.1),
 * để chắc chắn việc refactor không làm đổi kết quả mà frontend đang dùng.
 */
class CatalogTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $instructor;
    protected User $noPermissionUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin', 'admin');

        // Giảng viên nhận vai trò mặc định từ hook User::created (AccessRoleAssigner).
        $this->instructor = $this->makeUser('instructor', 'instructor');

        // Role lạ: không thuộc nhóm mặc định nào và không có nhóm quyền => không có quyền gì.
        $this->noPermissionUser = $this->makeUser('visitor', 'nopermission');
    }

    private function makeUser(string $role, string $tag): User
    {
        return User::create([
            'name'            => "Catalog {$tag}",
            'email'           => "catalog_{$tag}_" . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => 'Active',
            'initials'        => 'CT',
        ]);
    }

    private function makeCourse(array $overrides = []): Course
    {
        $unique = strtoupper(uniqid());

        return Course::create(array_merge([
            'code'   => "CRS{$unique}",
            'name'   => "Môn học {$unique}",
            'status' => 'Active',
        ], $overrides));
    }

    private function makeTerm(array $overrides = []): AcademicTerm
    {
        return AcademicTerm::create(array_merge([
            'name'       => 'Học kỳ ' . strtoupper(uniqid()),
            'start_date' => '2026-09-01',
            'end_date'   => '2027-01-15',
            'status'     => 'Active',
        ], $overrides));
    }

    private function makeClass(array $overrides = []): ClassModel
    {
        return ClassModel::create(array_merge([
            'id'     => 'CLS_' . strtoupper(uniqid()),
            'course' => 'Lớp thử nghiệm',
            'term'   => 'HK thử nghiệm',
            'status' => 'Active',
        ], $overrides));
    }

    /** Vị trí của một id trong danh sách JSON trả về (false nếu không có). */
    private function positionOf(array $items, string $id): int|false
    {
        return array_search($id, array_column($items, 'id'), true);
    }

    // ══ Phân quyền ═══════════════════════════════════════════════════════════

    public function test_admin_catalog_routes_require_authentication(): void
    {
        $this->getJson('/api/admin/courses')->assertStatus(401);
        $this->postJson('/api/admin/courses', [])->assertStatus(401);
        $this->getJson('/api/admin/terms')->assertStatus(401);
        $this->postJson('/api/admin/terms', [])->assertStatus(401);
    }

    public function test_admin_catalog_routes_reject_non_admin(): void
    {
        Sanctum::actingAs($this->instructor);

        $course = $this->makeCourse();
        $term = $this->makeTerm();

        $this->getJson('/api/admin/courses')->assertStatus(403);
        $this->postJson('/api/admin/courses', ['code' => 'X1', 'name' => 'X1'])->assertStatus(403);
        $this->putJson("/api/admin/courses/{$course->id}", ['name' => 'Đổi tên'])->assertStatus(403);
        $this->deleteJson("/api/admin/courses/{$course->id}")->assertStatus(403);

        $this->getJson('/api/admin/terms')->assertStatus(403);
        $this->postJson('/api/admin/terms', ['name' => 'X1'])->assertStatus(403);
        $this->putJson("/api/admin/terms/{$term->id}", ['name' => 'Đổi tên'])->assertStatus(403);
        $this->deleteJson("/api/admin/terms/{$term->id}")->assertStatus(403);

        $this->assertDatabaseHas('courses', ['id' => $course->id]);
        $this->assertDatabaseHas('academic_terms', ['id' => $term->id]);
    }

    public function test_teacher_catalog_routes_require_authentication_and_permission(): void
    {
        $this->getJson('/api/teacher/courses')->assertStatus(401);
        $this->getJson('/api/teacher/terms')->assertStatus(401);

        Sanctum::actingAs($this->noPermissionUser);
        $this->getJson('/api/teacher/courses')->assertStatus(403);
        $this->getJson('/api/teacher/terms')->assertStatus(403);
    }

    // ══ Môn học (admin) ══════════════════════════════════════════════════════

    public function test_admin_can_list_courses_with_class_count_ordered_by_name(): void
    {
        $unique = strtoupper(uniqid());
        $zulu = $this->makeCourse(['name' => "Zulu {$unique}"]);
        $alpha = $this->makeCourse(['name' => "Alpha {$unique}"]);
        $this->makeClass(['course_id' => $alpha->id]);
        $this->makeClass(['course_id' => $alpha->id]);

        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/admin/courses');
        $res->assertOk();
        $items = $res->json();

        $alphaPos = $this->positionOf($items, $alpha->id);
        $zuluPos = $this->positionOf($items, $zulu->id);
        $this->assertNotFalse($alphaPos);
        $this->assertNotFalse($zuluPos);
        $this->assertLessThan($zuluPos, $alphaPos, 'Danh sách phải sắp theo tên tăng dần');

        $this->assertSame(2, $items[$alphaPos]['classes_count']);
        $this->assertSame(0, $items[$zuluPos]['classes_count']);
    }

    public function test_admin_can_create_course(): void
    {
        Sanctum::actingAs($this->admin);

        $code = 'NEW' . strtoupper(uniqid());
        $res = $this->postJson('/api/admin/courses', [
            'code'        => $code,
            'name'        => "Tên {$code}",
            'description' => 'Mô tả môn học',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('code', $code)
            ->assertJsonPath('name', "Tên {$code}")
            ->assertJsonPath('description', 'Mô tả môn học');
        $this->assertTrue(Str::isUuid($res->json('id')));

        // Không gửi status thì DB tự đặt mặc định là Active.
        $this->assertDatabaseHas('courses', ['id' => $res->json('id'), 'code' => $code, 'status' => 'Active']);
    }

    public function test_create_course_validates_input(): void
    {
        Sanctum::actingAs($this->admin);
        $existing = $this->makeCourse();

        $this->postJson('/api/admin/courses', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name']);

        $this->postJson('/api/admin/courses', ['code' => $existing->code, 'name' => 'Tên mới ' . uniqid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->postJson('/api/admin/courses', ['code' => 'C' . uniqid(), 'name' => $existing->name])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/courses', ['code' => str_repeat('A', 51), 'name' => 'Tên ' . uniqid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->postJson('/api/admin/courses', ['code' => 'C' . uniqid(), 'name' => 'Tên ' . uniqid(), 'status' => 'Deleted'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_admin_can_show_course_with_class_count(): void
    {
        $course = $this->makeCourse();
        $this->makeClass(['course_id' => $course->id]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/admin/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('id', $course->id)
            ->assertJsonPath('code', $course->code)
            ->assertJsonPath('classes_count', 1);

        $this->getJson('/api/admin/courses/' . Str::uuid())->assertStatus(404);
    }

    public function test_admin_can_update_course_partially_and_get_refreshed_record(): void
    {
        $course = $this->makeCourse(['description' => 'Cũ']);

        Sanctum::actingAs($this->admin);

        $newName = 'Tên mới ' . uniqid();
        $this->putJson("/api/admin/courses/{$course->id}", ['name' => $newName, 'status' => 'Archived'])
            ->assertOk()
            ->assertJsonPath('id', $course->id)
            ->assertJsonPath('name', $newName)
            ->assertJsonPath('status', 'Archived')
            ->assertJsonPath('code', $course->code)       // không gửi thì giữ nguyên
            ->assertJsonPath('description', 'Cũ');

        $this->assertDatabaseHas('courses', ['id' => $course->id, 'name' => $newName, 'status' => 'Archived']);
    }

    public function test_update_course_allows_its_own_unique_values_but_rejects_duplicates(): void
    {
        $course = $this->makeCourse();
        $other = $this->makeCourse();

        Sanctum::actingAs($this->admin);

        // Gửi lại chính code/name của mình thì hợp lệ (ignore chính nó).
        $this->putJson("/api/admin/courses/{$course->id}", ['code' => $course->code, 'name' => $course->name])
            ->assertOk();

        // Trùng với môn khác thì bị từ chối.
        $this->putJson("/api/admin/courses/{$course->id}", ['code' => $other->code])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->putJson("/api/admin/courses/{$course->id}", ['name' => $other->name])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->putJson('/api/admin/courses/' . Str::uuid(), ['name' => 'Không tồn tại'])->assertStatus(404);
    }

    public function test_admin_can_delete_course_and_classes_keep_existing_but_lose_course_link(): void
    {
        $course = $this->makeCourse();
        $class = $this->makeClass(['course_id' => $course->id]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Course deleted successfully');

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'course_id' => null]);

        $this->deleteJson('/api/admin/courses/' . Str::uuid())->assertStatus(404);
    }

    // ══ Học kỳ (admin) ═══════════════════════════════════════════════════════

    public function test_admin_can_list_terms_with_class_count_ordered_by_start_date_desc(): void
    {
        $older = $this->makeTerm(['start_date' => '2001-01-01', 'end_date' => '2001-06-01']);
        $newer = $this->makeTerm(['start_date' => '2099-01-01', 'end_date' => '2099-06-01']);
        $this->makeClass(['term_id' => $newer->id]);

        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/admin/terms');
        $res->assertOk();
        $items = $res->json();

        $newerPos = $this->positionOf($items, $newer->id);
        $olderPos = $this->positionOf($items, $older->id);
        $this->assertNotFalse($newerPos);
        $this->assertNotFalse($olderPos);
        $this->assertLessThan($olderPos, $newerPos, 'Danh sách phải sắp theo ngày bắt đầu giảm dần');

        $this->assertSame(1, $items[$newerPos]['classes_count']);
        $this->assertSame(0, $items[$olderPos]['classes_count']);
    }

    public function test_admin_can_create_term_and_dates_are_formatted_as_y_m_d(): void
    {
        Sanctum::actingAs($this->admin);

        $name = 'HK ' . strtoupper(uniqid());
        $res = $this->postJson('/api/admin/terms', [
            'name'       => $name,
            'start_date' => '2026-09-01',
            'end_date'   => '2027-01-15',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('name', $name)
            ->assertJsonPath('start_date', '2026-09-01')
            ->assertJsonPath('end_date', '2027-01-15');
        $this->assertTrue(Str::isUuid($res->json('id')));
        $this->assertDatabaseHas('academic_terms', ['id' => $res->json('id'), 'name' => $name, 'status' => 'Active']);
    }

    public function test_admin_can_create_term_without_dates(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/terms', ['name' => 'HK ' . strtoupper(uniqid())])
            ->assertStatus(201)
            ->assertJsonPath('start_date', null)
            ->assertJsonPath('end_date', null);
    }

    public function test_create_term_validates_input(): void
    {
        Sanctum::actingAs($this->admin);
        $existing = $this->makeTerm();

        $this->postJson('/api/admin/terms', [])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/terms', ['name' => $existing->name])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/terms', ['name' => str_repeat('A', 101)])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/terms', ['name' => 'HK ' . uniqid(), 'start_date' => 'không phải ngày'])
            ->assertStatus(422)->assertJsonValidationErrors(['start_date']);

        // Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.
        $this->postJson('/api/admin/terms', ['name' => 'HK ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2026-08-31'])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->postJson('/api/admin/terms', ['name' => 'HK ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2026-09-01'])
            ->assertStatus(201);

        $this->postJson('/api/admin/terms', ['name' => 'HK ' . uniqid(), 'status' => 'Deleted'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_admin_can_show_term_with_class_count(): void
    {
        $term = $this->makeTerm();
        $this->makeClass(['term_id' => $term->id]);
        $this->makeClass(['term_id' => $term->id]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/admin/terms/{$term->id}")
            ->assertOk()
            ->assertJsonPath('id', $term->id)
            ->assertJsonPath('start_date', '2026-09-01')
            ->assertJsonPath('classes_count', 2);

        $this->getJson('/api/admin/terms/' . Str::uuid())->assertStatus(404);
    }

    public function test_admin_can_update_term_and_get_refreshed_record(): void
    {
        $term = $this->makeTerm();

        Sanctum::actingAs($this->admin);

        $newName = 'HK mới ' . uniqid();
        $this->putJson("/api/admin/terms/{$term->id}", ['name' => $newName, 'status' => 'Archived'])
            ->assertOk()
            ->assertJsonPath('name', $newName)
            ->assertJsonPath('status', 'Archived')
            ->assertJsonPath('start_date', '2026-09-01');   // không gửi thì giữ nguyên

        $this->assertDatabaseHas('academic_terms', ['id' => $term->id, 'name' => $newName, 'status' => 'Archived']);
    }

    public function test_update_term_allows_its_own_name_but_rejects_duplicates(): void
    {
        $term = $this->makeTerm();
        $other = $this->makeTerm();

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/terms/{$term->id}", ['name' => $term->name])->assertOk();

        $this->putJson("/api/admin/terms/{$term->id}", ['name' => $other->name])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->putJson("/api/admin/terms/{$term->id}", ['start_date' => '2026-09-01', 'end_date' => '2026-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);

        $this->putJson('/api/admin/terms/' . Str::uuid(), ['name' => 'Không tồn tại'])->assertStatus(404);
    }

    public function test_admin_can_delete_term_and_classes_keep_existing_but_lose_term_link(): void
    {
        $term = $this->makeTerm();
        $class = $this->makeClass(['term_id' => $term->id]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/terms/{$term->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Academic term deleted successfully');

        $this->assertDatabaseMissing('academic_terms', ['id' => $term->id]);
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'term_id' => null]);

        $this->deleteJson('/api/admin/terms/' . Str::uuid())->assertStatus(404);
    }

    // ══ Danh mục cho giảng viên (Teacher\CatalogController) ══════════════════

    public function test_teacher_courses_lists_only_active_ordered_by_name(): void
    {
        $unique = strtoupper(uniqid());
        $zulu = $this->makeCourse(['name' => "Zulu {$unique}"]);
        $alpha = $this->makeCourse(['name' => "Alpha {$unique}"]);
        $archived = $this->makeCourse(['name' => "Archived {$unique}", 'status' => 'Archived']);

        Sanctum::actingAs($this->instructor);

        $res = $this->getJson('/api/teacher/courses');
        $res->assertOk();
        $items = $res->json();

        $alphaPos = $this->positionOf($items, $alpha->id);
        $zuluPos = $this->positionOf($items, $zulu->id);
        $this->assertNotFalse($alphaPos);
        $this->assertNotFalse($zuluPos);
        $this->assertLessThan($zuluPos, $alphaPos);
        $this->assertFalse($this->positionOf($items, $archived->id), 'Môn đã lưu trữ không được trả về');

        foreach ($items as $item) {
            $this->assertSame('Active', $item['status']);
        }
    }

    public function test_teacher_terms_lists_only_active_ordered_by_start_date_desc(): void
    {
        $older = $this->makeTerm(['start_date' => '2001-01-01', 'end_date' => '2001-06-01']);
        $newer = $this->makeTerm(['start_date' => '2099-01-01', 'end_date' => '2099-06-01']);
        $archived = $this->makeTerm(['start_date' => '2050-01-01', 'end_date' => '2050-06-01', 'status' => 'Archived']);

        Sanctum::actingAs($this->instructor);

        $res = $this->getJson('/api/teacher/terms');
        $res->assertOk();
        $items = $res->json();

        $newerPos = $this->positionOf($items, $newer->id);
        $olderPos = $this->positionOf($items, $older->id);
        $this->assertNotFalse($newerPos);
        $this->assertNotFalse($olderPos);
        $this->assertLessThan($olderPos, $newerPos);
        $this->assertFalse($this->positionOf($items, $archived->id), 'Học kỳ đã lưu trữ không được trả về');

        foreach ($items as $item) {
            $this->assertSame('Active', $item['status']);
        }
    }

    public function test_teacher_catalog_responses_have_expected_fields(): void
    {
        $course = $this->makeCourse();
        $term = $this->makeTerm();

        Sanctum::actingAs($this->instructor);

        $courseItem = collect($this->getJson('/api/teacher/courses')->json())->firstWhere('id', $course->id);
        $this->assertSame($course->code, $courseItem['code']);
        $this->assertSame($course->name, $courseItem['name']);

        $termItem = collect($this->getJson('/api/teacher/terms')->json())->firstWhere('id', $term->id);
        $this->assertSame($term->name, $termItem['name']);
        $this->assertSame('2026-09-01', $termItem['start_date']);
        $this->assertSame('2027-01-15', $termItem['end_date']);
    }
}
