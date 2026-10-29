<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClassRosterImportTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected string $adminToken;
    protected User $instructor;
    protected string $instructorToken;
    protected User $student1;
    protected User $student2;
    protected ClassModel $testClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name'            => 'Admin Test',
            'email'           => 'admin_roster_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'admin',
            'status'          => 'Active',
            'initials'        => 'AT',
        ]);
        $this->adminToken = $this->adminUser->createToken('admin-token')->plainTextToken;

        $this->instructor = User::create([
            'name'            => 'Instructor Test',
            'email'           => 'inst_roster_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor_practical',
            'status'          => 'Active',
            'initials'        => 'IT',
        ]);
        $this->instructorToken = $this->instructor->createToken('inst-token')->plainTextToken;

        $this->student1 = User::create([
            'name'            => 'Student One',
            'email'           => 'student1_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'S1',
        ]);

        $this->student2 = User::create([
            'name'            => 'Student Two',
            'email'           => 'student2_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'S2',
        ]);

        $lecturerGroup = \Illuminate\Support\Facades\DB::table('role_groups')->where('name', 'Giảng viên')->first();
        if ($lecturerGroup) {
            \Illuminate\Support\Facades\DB::table('account_assign_role_group')->insert([
                'account_id'    => $this->instructor->id,
                'role_group_id' => $lecturerGroup->id,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $this->testClass = ClassModel::create([
            'course'        => 'CS101 · Introduction to Databases',
            'term'          => 'Semester 1, 2026-2027',
            'instructor_id' => $this->instructor->id,
            'status'        => 'Active',
        ]);
    }

    public function test_assign_single_student_to_class(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->instructorToken)
            ->postJson("/api/teacher/classes/{$this->testClass->id}/members", [
                'student_id' => $this->student1->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('id', $this->student1->id)
            ->assertJsonPath('email', $this->student1->email)
            ->assertJsonPath('role', 'Member');

        $this->assertDatabaseHas('class_enrollments', [
            'class_id'   => $this->testClass->id,
            'student_id' => $this->student1->id,
        ]);
    }

    public function test_assign_student_by_email(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->instructorToken)
            ->postJson("/api/teacher/classes/{$this->testClass->id}/members", [
                'student_id' => $this->student2->email,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('id', $this->student2->id)
            ->assertJsonPath('email', $this->student2->email);

        $this->assertDatabaseHas('class_enrollments', [
            'class_id'   => $this->testClass->id,
            'student_id' => $this->student2->id,
        ]);
    }

    public function test_remove_student_from_class(): void
    {
        $this->testClass->students()->attach($this->student1->id, [
            'role'      => 'Member',
            'joined_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->instructorToken)
            ->deleteJson("/api/teacher/classes/{$this->testClass->id}/members/{$this->student1->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Member removed successfully');

        $this->assertDatabaseMissing('class_enrollments', [
            'class_id'   => $this->testClass->id,
            'student_id' => $this->student1->id,
        ]);
    }

    public function test_import_students_via_csv_with_headers(): void
    {
        $csvContent = "STT,MSSV,Họ và tên,Email\n" .
                      "1,22520001,Student One,{$this->student1->email}\n" .
                      "2,22520002,Student Two,{$this->student2->email}\n" .
                      "3,22529999,Non Existing,not_found_user_999@gm.uit.edu.vn\n";

        $file = UploadedFile::fake()->createWithContent('students.csv', $csvContent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->instructorToken)
            ->post("/api/teacher/classes/{$this->testClass->id}/members/import", [
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJson([
                'total_processed' => 3,
                'success_count'   => 2,
                'skipped_count'   => 0,
                'failed_count'    => 1,
            ]);

        $this->assertDatabaseHas('class_enrollments', [
            'class_id'   => $this->testClass->id,
            'student_id' => $this->student1->id,
        ]);
        $this->assertDatabaseHas('class_enrollments', [
            'class_id'   => $this->testClass->id,
            'student_id' => $this->student2->id,
        ]);
    }

    public function test_import_students_via_txt_one_email_per_line(): void
    {
        $txtContent = "{$this->student1->email}\n" .
                      "{$this->student2->email}\n" .
                      "{$this->student1->email}\n"; // Trùng lặp

        $file = UploadedFile::fake()->createWithContent('students.txt', $txtContent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->instructorToken)
            ->post("/api/teacher/classes/{$this->testClass->id}/members/import", [
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJson([
                'total_processed' => 3,
                'success_count'   => 2,
                'skipped_count'   => 1,
                'failed_count'    => 0,
            ]);
    }

    public function test_import_students_rejects_binary_xlsx_file(): void
    {
        $xlsxHeader = "PK\x03\x04" . str_repeat("\x00", 50);
        $file = UploadedFile::fake()->createWithContent('students.csv', $xlsxHeader);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->instructorToken)
            ->post("/api/teacher/classes/{$this->testClass->id}/members/import", [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['detail']);
    }
}
