<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\Course;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClassInstructorAllocationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected string $adminToken;

    protected User $instructor1;
    protected string $instructor1Token;

    protected User $instructor2;
    protected string $instructor2Token;

    protected User $studentUser;
    protected string $studentToken;

    protected ClassModel $testClass;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Admin
        $this->adminUser = User::create([
            'name'            => 'Admin Allocation Test',
            'email'           => 'admin_alloc_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'admin',
            'status'          => 'Active',
            'initials'        => 'AA',
        ]);
        $this->adminToken = $this->adminUser->createToken('admin-token')->plainTextToken;

        // 2. Instructor 1 (Practical)
        $this->instructor1 = User::create([
            'name'            => 'Dr. Teacher Practical',
            'email'           => 'instructor1_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor_practical',
            'status'          => 'Active',
            'initials'        => 'TP',
            'department'      => 'Software Engineering',
        ]);
        $this->instructor1Token = $this->instructor1->createToken('instructor1-token')->plainTextToken;

        // 3. Instructor 2 (Theory)
        $this->instructor2 = User::create([
            'name'            => 'Dr. Teacher Theory',
            'email'           => 'instructor2_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor_theory',
            'status'          => 'Active',
            'initials'        => 'TT',
            'department'      => 'Computer Science',
        ]);
        $this->instructor2Token = $this->instructor2->createToken('instructor2-token')->plainTextToken;

        // 4. Student
        $this->studentUser = User::create([
            'name'            => 'Student Test',
            'email'           => 'student_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'ST',
        ]);
        $this->studentToken = $this->studentUser->createToken('student-token')->plainTextToken;

        // 5. Test Class
        $this->testClass = ClassModel::create([
            'id'            => 'TEST_CLASS_' . strtoupper(uniqid()),
            'course'        => 'Database Systems',
            'term'          => 'Fall 2026',
            'instructor_id' => null,
            'status'        => 'Active',
        ]);
    }

    public function test_admin_can_allocate_instructor_via_put_instructor_endpoint(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/classes/{$this->testClass->id}/instructor", [
                'instructor_id'   => $this->instructor1->id,
                'assignment_type' => 'practical',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('id', $this->testClass->id)
            ->assertJsonPath('lecturer', $this->instructor1->name)
            ->assertJsonPath('lecturer_id', $this->instructor1->id);

        $this->assertDatabaseHas('class_instructors', [
            'class_id'        => $this->testClass->id,
            'instructor_id'   => $this->instructor1->id,
            'assignment_type' => 'practical',
        ]);

        $this->assertDatabaseHas('classes', [
            'id'            => $this->testClass->id,
            'instructor_id' => $this->instructor1->id,
        ]);
    }

    public function test_admin_can_allocate_instructor_via_allocate_teacher_alias(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/classes/{$this->testClass->id}/allocate-teacher", [
                'lecturer_id'     => $this->instructor1->id,
                'assignment_type' => 'practical',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('lecturer_id', $this->instructor1->id);
    }

    public function test_admin_can_allocate_instructor_during_class_update(): void
    {
        // Similar to SQL-UIT behavior in AdminEditClassPage
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/classes/{$this->testClass->id}", [
                'course'      => 'Advanced Database Systems',
                'semester'    => 'Spring 2027',
                'lecturerId'  => $this->instructor1->id,
                'status'      => 'Active',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('course', 'Advanced Database Systems')
            ->assertJsonPath('semester', 'Spring 2027')
            ->assertJsonPath('lecturer_id', $this->instructor1->id)
            ->assertJsonPath('lecturer', $this->instructor1->name);

        $this->assertDatabaseHas('classes', [
            'id'            => $this->testClass->id,
            'instructor_id' => $this->instructor1->id,
        ]);
    }

    public function test_admin_can_unassign_instructor_during_class_update(): void
    {
        // Pre-assign
        $this->testClass->instructor_id = $this->instructor1->id;
        $this->testClass->save();

        // Update with Unassigned
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/classes/{$this->testClass->id}", [
                'lecturerId' => 'Unassigned',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('lecturer_id', null)
            ->assertJsonPath('lecturer', 'Unassigned');

        $this->assertDatabaseHas('classes', [
            'id'            => $this->testClass->id,
            'instructor_id' => null,
        ]);

        $this->assertDatabaseMissing('class_instructors', [
            'class_id' => $this->testClass->id,
        ]);
    }

    public function test_admin_can_sync_both_theory_and_practical_instructors(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/classes/{$this->testClass->id}/instructors", [
                'theory_instructor_id'    => $this->instructor2->id,
                'practical_instructor_id' => $this->instructor1->id,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('class_instructors', [
            'class_id'        => $this->testClass->id,
            'instructor_id'   => $this->instructor2->id,
            'assignment_type' => 'theory',
        ]);

        $this->assertDatabaseHas('class_instructors', [
            'class_id'        => $this->testClass->id,
            'instructor_id'   => $this->instructor1->id,
            'assignment_type' => 'practical',
        ]);
    }

    public function test_admin_can_remove_instructor_assignment_by_type(): void
    {
        // First sync
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/classes/{$this->testClass->id}/instructors", [
                'theory_instructor_id'    => $this->instructor2->id,
                'practical_instructor_id' => $this->instructor1->id,
            ]);

        // Remove theory instructor
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->deleteJson("/api/admin/classes/{$this->testClass->id}/instructors/theory");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('class_instructors', [
            'class_id'        => $this->testClass->id,
            'assignment_type' => 'theory',
        ]);

        // Practical instructor remains
        $this->assertDatabaseHas('class_instructors', [
            'class_id'        => $this->testClass->id,
            'assignment_type' => 'practical',
        ]);
    }

    public function test_assigning_non_instructor_fails_validation(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/classes/{$this->testClass->id}/instructor", [
                'instructor_id'   => $this->studentUser->id, // Student, not instructor
                'assignment_type' => 'practical',
            ]);

        $response->assertStatus(422);
    }

    public function test_student_cannot_allocate_instructor(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->studentToken}")
            ->putJson("/api/admin/classes/{$this->testClass->id}/instructor", [
                'instructor_id'   => $this->instructor1->id,
                'assignment_type' => 'practical',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_user_with_lecturer_role_from_frontend_dialog(): void
    {
        $email = 'hungxm_' . uniqid() . '@uit.edu.vn';
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/users', [
                'name'     => 'Mai Xuân Hùng',
                'email'    => $email,
                'password' => 'password123',
                'role'     => 'Lecturer', // Frontend sends capitalized 'Lecturer'
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('email', $email)
            ->assertJsonPath('name', 'Mai Xuân Hùng')
            ->assertJsonPath('roleDisplay', 'Lecturer');

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'role'  => 'instructor_practical',
        ]);
    }

    public function test_admin_get_users_formats_instructor_role_for_frontend_dropdown(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/users');

        $response->assertStatus(200);

        // Find instructor in response
        $users = collect($response->json());
        $instructor = $users->firstWhere('id', $this->instructor1->id);

        $this->assertNotNull($instructor);
        $this->assertSame('Lecturer', $instructor['role']);
        $this->assertSame('Lecturer', $instructor['roleDisplay']);
    }

    public function test_class_members_endpoints_work_for_frontend(): void
    {
        // 1. Get all students
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/teacher/classes/students');
        $response->assertStatus(200);
        $this->assertTrue(collect($response->json())->contains('id', $this->studentUser->id));

        // 2. Add member to class
        $addResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/teacher/classes/{$this->testClass->id}/members", [
                'student_id' => $this->studentUser->id,
            ]);
        $addResponse->assertStatus(201)
            ->assertJsonPath('id', $this->studentUser->id);

        // 3. Get class members
        $membersResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/teacher/classes/{$this->testClass->id}/members");
        $membersResponse->assertStatus(200);
        $this->assertTrue(collect($membersResponse->json())->contains('id', $this->studentUser->id));

        // 4. Remove member
        $delResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->deleteJson("/api/teacher/classes/{$this->testClass->id}/members/{$this->studentUser->id}");
        $delResponse->assertStatus(200);

        // 5. Verify removed
        $membersAfter = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/teacher/classes/{$this->testClass->id}/members");
        $this->assertFalse(collect($membersAfter->json())->contains('id', $this->studentUser->id));
    }
}
