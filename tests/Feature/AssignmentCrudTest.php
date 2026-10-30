<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssignmentCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected User $instructor;
    protected User $student;
    protected ClassModel $class;
    protected Problem $problem1;
    protected Problem $problem2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::create([
            'name'            => 'Teacher Assignment Test',
            'email'           => 'teacher_assign_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor',
            'status'          => 'Active',
            'initials'        => 'TA',
        ]);

        $this->student = User::create([
            'name'            => 'Student Test',
            'email'           => 'student_assign_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'ST',
        ]);

        $this->class = ClassModel::create([
            'id'            => 'CLASS_' . strtoupper(uniqid()),
            'course'        => 'IS004.R12',
            'term'          => 'HK1 2026-2027',
            'status'        => 'Active',
            'instructor_id' => $this->instructor->id,
        ]);

        $this->class->students()->attach($this->student->id);

        $this->problem1 = Problem::create([
            'number'          => 'SQL-01-' . rand(100, 999),
            'title'           => 'Sản phẩm do Trung Quốc sản xuất',
            'description'     => 'Tìm danh sách sản phẩm do Trung Quốc sản xuất',
            'requirements'    => 'SELECT * FROM SanPham WHERE NuocSX = "Trung Quoc"',
            'difficulty'      => 'Easy',
            'creator_id'      => $this->instructor->id,
            'practice_listed' => true,
        ]);

        $this->problem2 = Problem::create([
            'number'          => 'SQL-02-' . rand(100, 999),
            'title'           => 'Sản phẩm có đơn vị tính là cây hoặc quyển',
            'description'     => 'Tìm sản phẩm có DVT là cay hoac quyen',
            'requirements'    => 'SELECT * FROM SanPham WHERE DVT IN ("cay", "quyen")',
            'difficulty'      => 'Easy',
            'creator_id'      => $this->instructor->id,
            'practice_listed' => true,
        ]);
    }

    public function test_get_assignments_list(): void
    {
        Sanctum::actingAs($this->instructor);

        $assignment = Assignment::create([
            'title'         => 'Bài thực hành 01',
            'instructor_id' => $this->instructor->id,
            'published'     => true,
            'closes'        => now()->addDays(7),
        ]);
        $assignment->classes()->attach($this->class->id);
        $assignment->problems()->attach($this->problem1->id, ['points' => 10, 'order_index' => 1]);

        $response = $this->getJson('/api/assignments');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertNotEmpty($data);
        $item = collect($data)->firstWhere('id', $assignment->id);
        $this->assertNotNull($item);
        $this->assertEquals('Bài thực hành 01', $item['title']);
        $this->assertEquals('Open', $item['status']);
        $this->assertEquals(1, $item['problems']);
        $this->assertStringContainsString('IS004.R12', $item['classes']);
    }

    public function test_get_audience_count(): void
    {
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson('/api/assignments/audience-count?audience_type=classes&class_ids[]=' . $this->class->id);
        $response->assertStatus(200);
        $response->assertJson([
            'eligible_students' => 1,
            'class_count'       => 1,
        ]);
    }

    public function test_instructor_can_create_assignment_with_frontend_payload(): void
    {
        Sanctum::actingAs($this->instructor);

        $payload = [
            'title'         => 'Bài thực hành 01: Truy vấn dữ liệu cơ bản',
            'instructions'  => '<p>Sinh viên hoàn thành các câu truy vấn SQL</p>',
            'classIds'      => [$this->class->id],
            'audienceType'  => 'classes',
            'opens'         => now()->toIso8601String(),
            'closes'        => now()->addDays(7)->toIso8601String(),
            'status'        => 'published',
            'studentOptions'=> [
                'hints'     => true,
                'comments'  => false,
                'aiAllowed' => true,
            ],
            'problems'      => [
                [
                    'id'                        => $this->problem1->id,
                    'points'                    => 10,
                    'order'                     => 1,
                    'workspaceParts'            => ['problem-detail', 'schema-browser', 'query-editor'],
                    'datasetVisibility'         => 'all',
                    'databaseStructureVisible'  => true,
                    'databaseDescriptionVisible'=> true,
                ],
                [
                    'id'                        => $this->problem2->id,
                    'points'                    => 10,
                    'order'                     => 2,
                    'workspaceParts'            => ['problem-detail', 'query-editor'],
                    'datasetVisibility'         => 'visible_only',
                    'databaseStructureVisible'  => true,
                    'databaseDescriptionVisible'=> false,
                ],
            ],
        ];

        $response = $this->postJson('/api/assignments', $payload);
        $response->assertStatus(201);
        $created = $response->json();

        $this->assertArrayHasKey('id', $created);
        $this->assertEquals('Bài thực hành 01: Truy vấn dữ liệu cơ bản', $created['title']);
        $this->assertEquals('Open', $created['status']);
        $this->assertEquals(2, $created['problems']);
        $this->assertEquals(1, $created['eligibleStudents']);

        // Verify detail endpoint
        $detailResponse = $this->getJson('/api/assignments/' . $created['id']);
        $detailResponse->assertStatus(200);
        $detail = $detailResponse->json();
        $this->assertEquals(2, $detail['problems']);
        $this->assertCount(2, $detail['problemList']);
        $this->assertEquals(10, $detail['problemList'][0]['points']);
        $this->assertTrue($detail['studentOptions']['hints']);
        $this->assertFalse($detail['studentOptions']['comments']);
        $this->assertTrue($detail['studentOptions']['aiAllowed']);
    }

    public function test_instructor_can_update_assignment(): void
    {
        Sanctum::actingAs($this->instructor);

        $assignment = Assignment::create([
            'title'         => 'Old Title',
            'instructor_id' => $this->instructor->id,
            'published'     => false,
        ]);

        $payload = [
            'title'         => 'Updated Title',
            'instructions'  => 'Updated Instructions',
            'status'        => 'published',
            'classIds'      => [$this->class->id],
            'problems'      => [
                ['id' => $this->problem1->id, 'points' => 15, 'order' => 1],
            ],
        ];

        $response = $this->putJson('/api/assignments/' . $assignment->id, $payload);
        $response->assertStatus(200);
        $this->assertEquals('Updated Title', $response->json('title'));
    }

    public function test_instructor_can_delete_assignment(): void
    {
        Sanctum::actingAs($this->instructor);

        $assignment = Assignment::create([
            'title'         => 'To Delete',
            'instructor_id' => $this->instructor->id,
            'published'     => false,
        ]);

        $response = $this->deleteJson('/api/assignments/' . $assignment->id);
        $response->assertStatus(200);
        $this->assertDatabaseMissing('assignments', ['id' => $assignment->id]);
    }

    public function test_student_cannot_create_assignment(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/assignments', [
            'title' => 'Student Assignment Attempt',
        ]);
        $response->assertStatus(403);
    }

    public function test_student_can_view_assignments_and_problems_without_403(): void
    {
        Sanctum::actingAs($this->student);

        // Student can view their assignments list
        $response = $this->getJson('/api/student/assignments');
        $response->assertStatus(200);

        // Create assignment with closed date (past deadline) and private problem
        $privateProblem = Problem::create([
            'number'          => 'SQL-PRIV-' . rand(100, 999),
            'title'           => 'Private problem in assignment',
            'description'     => 'Solve this private problem',
            'requirements'    => 'SELECT 1;',
            'difficulty'      => 'Medium',
            'creator_id'      => $this->instructor->id,
            'practice_listed' => false,
        ]);

        $assignment = Assignment::create([
            'title'         => 'Past Due Assignment',
            'instructor_id' => $this->instructor->id,
            'published'     => true,
            'opens'         => now()->subDays(5),
            'closes'        => now()->subDays(1),
        ]);
        $assignment->classes()->attach($this->class->id);
        $assignment->problems()->attach($privateProblem->id, ['points' => 10, 'order_index' => 1]);

        // Student can view this assignment in their student assignments list
        $listResponse = $this->getJson('/api/student/assignments');
        $listResponse->assertStatus(200);

        // Student can view this private problem in assignment context without 403
        $probResponse = $this->getJson('/api/problems/' . $privateProblem->id . '?context=' . $assignment->id);
        $probResponse->assertStatus(200);
        $this->assertEquals($privateProblem->id, $probResponse->json('id'));
    }

    public function test_instructor_b_cannot_see_or_select_instructor_a_classes(): void
    {
        $instructorB = User::create([
            'name'            => 'Instructor B',
            'email'           => 'instructor_b_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor_practical',
            'status'          => 'Active',
            'initials'        => 'IB',
        ]);

        $classB = ClassModel::create([
            'id'            => 'CLASS_B_' . strtoupper(uniqid()),
            'course'        => 'CS202',
            'term'          => 'HK1 2026-2027',
            'status'        => 'Active',
            'instructor_id' => $instructorB->id,
        ]);

        Sanctum::actingAs($instructorB);

        // 1. In GET /api/teacher/classes, Instructor B only sees Class B, not Class A
        $classesResponse = $this->getJson('/api/teacher/classes');
        $classesResponse->assertStatus(200);
        $classIds = collect($classesResponse->json())->pluck('id')->all();
        $this->assertContains($classB->id, $classIds);
        $this->assertNotContains($this->class->id, $classIds);

        // 2. Instructor B cannot assign Instructor A's class when creating assignment
        $createAttempt = $this->postJson('/api/assignments', [
            'title'        => 'Instructor B Assignment',
            'classIds'     => [$this->class->id], // Instructor A's class!
            'audienceType' => 'classes',
        ]);
        $createAttempt->assertStatus(403);

        // 3. Instructor B can assign their own class
        $createSuccess = $this->postJson('/api/assignments', [
            'title'        => 'Instructor B Assignment',
            'classIds'     => [$classB->id],
            'audienceType' => 'classes',
        ]);
        $createSuccess->assertStatus(201);
    }

    public function test_instructor_b_cannot_operate_on_instructor_a_assignments(): void
    {
        $instructorB = User::create([
            'name'            => 'Instructor B Oper',
            'email'           => 'instructor_b_oper_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor_theory',
            'status'          => 'Active',
            'initials'        => 'IB',
        ]);

        $assignmentA = Assignment::create([
            'title'         => 'Instructor A Assignment',
            'instructor_id' => $this->instructor->id,
            'published'     => true,
        ]);
        $assignmentA->classes()->attach($this->class->id);

        Sanctum::actingAs($instructorB);

        // 1. Instructor B cannot view Instructor A's assignment detail
        $viewResponse = $this->getJson('/api/assignments/' . $assignmentA->id);
        $viewResponse->assertStatus(403);

        // 2. Instructor B cannot update Instructor A's assignment
        $updateResponse = $this->putJson('/api/assignments/' . $assignmentA->id, [
            'title' => 'Hacked by B',
        ]);
        $updateResponse->assertStatus(403);

        // 3. Instructor B cannot delete Instructor A's assignment
        $deleteResponse = $this->deleteJson('/api/assignments/' . $assignmentA->id);
        $deleteResponse->assertStatus(403);

        // Assignment A still exists
        $this->assertDatabaseHas('assignments', ['id' => $assignmentA->id, 'title' => 'Instructor A Assignment']);
    }
}
