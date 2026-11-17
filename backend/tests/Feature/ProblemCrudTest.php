<?php

namespace Tests\Feature;

use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\TestCase as ProblemTestCase;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProblemCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $instructor1;
    protected User $instructor2;
    protected User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Admin
        $this->adminUser = User::create([
            'name'            => 'Admin Problem Test',
            'email'           => 'admin_prob_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'admin',
            'status'          => 'Active',
            'initials'        => 'AP',
        ]);

        // 2. Instructor 1
        $this->instructor1 = User::create([
            'name'            => 'Teacher One',
            'email'           => 'teacher1_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor',
            'status'          => 'Active',
            'initials'        => 'T1',
        ]);

        // 3. Instructor 2
        $this->instructor2 = User::create([
            'name'            => 'Teacher Two',
            'email'           => 'teacher2_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor',
            'status'          => 'Active',
            'initials'        => 'T2',
        ]);

        // 4. Student
        $this->studentUser = User::create([
            'name'            => 'Student One',
            'email'           => 'student_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'S1',
        ]);
    }

    public function test_get_problems_list_with_role_filtering(): void
    {
        // Public problem
        $pub = Problem::create([
            'number'          => 'SQL-PUB-' . rand(100, 999),
            'title'           => 'Public Problem',
            'difficulty'      => 'Easy',
            'description'     => 'Public problem description',
            'requirements'    => 'Select all rows',
            'practice_listed' => true,
            'creator_id'      => $this->instructor1->id,
        ]);

        // Private problem by Instructor 1
        $priv1 = Problem::create([
            'number'          => 'SQL-PRIV1-' . rand(100, 999),
            'title'           => 'Private Problem 1',
            'difficulty'      => 'Medium',
            'description'     => 'Private description 1',
            'requirements'    => 'Private requirements',
            'practice_listed' => false,
            'creator_id'      => $this->instructor1->id,
        ]);

        // 1. Admin sees all problems
        Sanctum::actingAs($this->adminUser);
        $adminResp = $this->getJson('/api/problems');
        $adminResp->assertStatus(200);
        $adminIds = collect($adminResp->json())->pluck('id');
        $this->assertTrue($adminIds->contains($pub->id));
        $this->assertTrue($adminIds->contains($priv1->id));

        // 2. Instructor 1 sees public and their own private
        Sanctum::actingAs($this->instructor1);
        $t1Resp = $this->getJson('/api/problems');
        $t1Resp->assertStatus(200);
        $t1Ids = collect($t1Resp->json())->pluck('id');
        $this->assertTrue($t1Ids->contains($pub->id));
        $this->assertTrue($t1Ids->contains($priv1->id));

        // 3. Instructor 2 sees public but NOT Instructor 1's private
        Sanctum::actingAs($this->instructor2);
        $t2Resp = $this->getJson('/api/problems');
        $t2Resp->assertStatus(200);
        $t2Ids = collect($t2Resp->json())->pluck('id');
        $this->assertTrue($t2Ids->contains($pub->id));
        $this->assertFalse($t2Ids->contains($priv1->id));

        // 4. Student sees only public problems
        Sanctum::actingAs($this->studentUser);
        $stuResp = $this->getJson('/api/problems');
        $stuResp->assertStatus(200);
        $stuIds = collect($stuResp->json())->pluck('id');
        $this->assertTrue($stuIds->contains($pub->id));
        $this->assertFalse($stuIds->contains($priv1->id));
    }

    public function test_trending_problems_returns_top_items(): void
    {
        Sanctum::actingAs($this->studentUser);
        $response = $this->getJson('/api/problems/trending?range=Week');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_instructor_can_create_problem_with_camel_case_frontend_payload(): void
    {
        Sanctum::actingAs($this->instructor1);

        $payload = [
            'number'                     => 'SQL-TEST-01',
            'title'                      => 'Students with High GPA',
            'difficulty'                 => 'Medium',
            'visibility'                 => 'Public',
            'topics'                     => 'SELECT, WHERE, JOIN',
            'statement'                  => 'Find all students whose GPA is above 3.5.',
            'requirements'               => 'Output student ID and full name.',
            'hints'                      => ['Filter with WHERE gpa > 3.5', 'Order by GPA descending'],
            'database'                   => 'SQL Server',
            'schemaFileName'             => 'university.sql',
            'databaseDescription'        => [
                [
                    'tableName'  => 'Students',
                    'attributes' => 'id, name, gpa',
                    'predicate'  => 'Contains enrolled student academic records',
                ]
            ],
            'databaseDescriptionVisible' => true,
            'schema'                     => 'CREATE TABLE Students (id INT PRIMARY KEY, name VARCHAR(100), gpa FLOAT);',
            'testCases'                  => [
                [
                    'schema'   => 'CREATE TABLE Students (id INT PRIMARY KEY, name VARCHAR(100), gpa FLOAT);',
                    'seedData' => "INSERT INTO Students VALUES (1, 'Alice', 3.8), (2, 'Bob', 3.2);",
                    'isHidden' => false,
                ],
                [
                    'schema'   => 'CREATE TABLE Students (id INT PRIMARY KEY, name VARCHAR(100), gpa FLOAT);',
                    'seedData' => "INSERT INTO Students VALUES (3, 'Charlie', 3.9), (4, 'David', 3.4);",
                    'isHidden' => true,
                ]
            ],
            'referenceSolution'          => 'SELECT id, name FROM Students WHERE gpa > 3.5 ORDER BY gpa DESC;',
            'expectedOutputs'            => [
                [
                    'name'    => 'High GPA Students',
                    'columns' => ['id', 'name'],
                    'rows'    => [[1, 'Alice']],
                ]
            ],
            'expectedOutputVisible'      => true,
            'sqlSolutions'               => [
                [
                    'id'                    => 'sol-1',
                    'name'                  => 'Standard SELECT',
                    'query'                 => 'SELECT id, name FROM Students WHERE gpa > 3.5 ORDER BY gpa DESC;',
                    'description'           => 'Primary reference query',
                    'enabled'               => true,
                    'timeLimitMs'           => 5000,
                    'expectedOutputs'       => [
                        [
                            'name'    => 'High GPA Students',
                            'columns' => ['id', 'name'],
                            'rows'    => [[1, 'Alice']],
                        ]
                    ],
                    'expectedOutputVisible' => true,
                ]
            ]
        ];

        $response = $this->postJson('/api/problems', $payload);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertEquals('SQL-TEST-01', $data['number']);
        $this->assertEquals('Students with High GPA', $data['title']);
        $this->assertEquals('Medium', $data['difficulty']);
        $this->assertTrue($data['practiceListed']);
        $this->assertContains('SELECT', $data['topics']);
        $this->assertEquals('university.sql', $data['schemaFileName']);
        $this->assertCount(2, $data['testCases']);
        $this->assertCount(1, $data['sqlSolutions']);

        // Check DB records
        $problemId = $data['id'];
        $this->assertDatabaseHas('problems', ['id' => $problemId, 'number' => 'SQL-TEST-01']);
        $this->assertDatabaseHas('test_cases_rel', ['problem_id' => $problemId, 'is_hidden' => 1]);
        $this->assertDatabaseHas('problem_sql_solutions', ['problem_id' => $problemId, 'name' => 'Standard SELECT']);

        $topicNames = DB::table('problem_topics')
            ->join('topics', 'topics.id', '=', 'problem_topics.topic_id')
            ->where('problem_topics.problem_id', $problemId)
            ->pluck('topics.name');
        $this->assertTrue($topicNames->contains('SELECT'));
        $this->assertTrue($topicNames->contains('WHERE'));
        $this->assertTrue($topicNames->contains('JOIN'));
    }

    public function test_student_cannot_create_problem(): void
    {
        Sanctum::actingAs($this->studentUser);

        $payload = [
            'number'            => 'SQL-FORBIDDEN',
            'title'             => 'Student Trying to Create',
            'difficulty'        => 'Easy',
            'statement'         => 'Some statement',
            'referenceSolution' => 'SELECT 1;',
        ];

        $response = $this->postJson('/api/problems', $payload);
        $response->assertStatus(403);
    }

    public function test_get_problem_detail_for_teacher_vs_student(): void
    {
        // Teacher creates a problem
        Sanctum::actingAs($this->instructor1);

        $createResp = $this->postJson('/api/problems', [
            'number'            => 'SQL-DETAIL-01',
            'title'             => 'Detail Test Problem',
            'difficulty'        => 'Easy',
            'visibility'        => 'Public',
            'statement'         => 'Statement',
            'referenceSolution' => 'SELECT * FROM Demo;',
            'testCases'         => [
                ['schema' => 'CREATE TABLE Demo (x INT);', 'seedData' => 'INSERT INTO Demo VALUES (1);', 'isHidden' => false],
                ['schema' => 'CREATE TABLE Demo (x INT);', 'seedData' => 'INSERT INTO Demo VALUES (2);', 'isHidden' => true],
            ],
        ]);

        $createResp->assertStatus(201);
        $probId = $createResp->json('id');

        // Teacher views: receives reference solution and all 2 test cases
        Sanctum::actingAs($this->instructor1);
        $teacherView = $this->getJson("/api/problems/{$probId}");
        $teacherView->assertStatus(200);
        $this->assertEquals('SELECT * FROM Demo;', $teacherView->json('referenceSolution'));
        $this->assertCount(2, $teacherView->json('testCases'));

        // Student views: reference solution hidden, hidden test cases filtered out
        Sanctum::actingAs($this->studentUser);
        $studentView = $this->getJson("/api/problems/{$probId}");
        $studentView->assertStatus(200);
        $this->assertEquals('', $studentView->json('referenceSolution'));
        $this->assertCount(1, $studentView->json('testCases'));
    }

    public function test_instructor_can_update_own_problem_and_others_are_forbidden(): void
    {
        Sanctum::actingAs($this->instructor1);
        $createResp = $this->postJson('/api/problems', [
            'number'            => 'SQL-UPDATE-01',
            'title'             => 'Original Title',
            'difficulty'        => 'Easy',
            'visibility'        => 'Public',
            'statement'         => 'Original description',
            'referenceSolution' => 'SELECT 1;',
        ]);
        $probId = $createResp->json('id');

        // Instructor 2 cannot update Instructor 1's problem
        Sanctum::actingAs($this->instructor2);
        $unauthResp = $this->putJson("/api/problems/{$probId}", [
            'number'            => 'SQL-UPDATE-01',
            'title'             => 'Hacked Title',
            'difficulty'        => 'Easy',
            'statement'         => 'Hacked description',
            'referenceSolution' => 'SELECT 1;',
        ]);
        $unauthResp->assertStatus(403);

        // Instructor 1 can update
        Sanctum::actingAs($this->instructor1);
        $updateResp = $this->putJson("/api/problems/{$probId}", [
            'number'            => 'SQL-UPDATE-01-V2',
            'title'             => 'Updated Title By Owner',
            'difficulty'        => 'Hard',
            'visibility'        => 'Private',
            'statement'         => 'Updated statement',
            'referenceSolution' => 'SELECT 2;',
        ]);
        $updateResp->assertStatus(200);
        $this->assertEquals('Updated Title By Owner', $updateResp->json('title'));
        $this->assertEquals('Hard', $updateResp->json('difficulty'));
        $this->assertFalse($updateResp->json('practiceListed'));

        // Admin can also update any problem
        Sanctum::actingAs($this->adminUser);
        $adminResp = $this->putJson("/api/problems/{$probId}", [
            'number'            => 'SQL-UPDATE-01-V3',
            'title'             => 'Admin Overwrite',
            'difficulty'        => 'Medium',
            'statement'         => 'Admin statement',
            'referenceSolution' => 'SELECT 3;',
        ]);
        $adminResp->assertStatus(200);
        $this->assertEquals('Admin Overwrite', $adminResp->json('title'));
    }

    public function test_delete_problem_with_cascading_relations(): void
    {
        Sanctum::actingAs($this->instructor1);
        $createResp = $this->postJson('/api/problems', [
            'number'            => 'SQL-DELETE-01',
            'title'             => 'To be deleted',
            'difficulty'        => 'Easy',
            'topics'            => 'TEMPORARY',
            'statement'         => 'Will be removed',
            'referenceSolution' => 'SELECT 1;',
        ]);
        $probId = $createResp->json('id');

        // Instructor 2 cannot delete
        Sanctum::actingAs($this->instructor2);
        $unauth = $this->deleteJson("/api/problems/{$probId}");
        $unauth->assertStatus(403);

        // Instructor 1 deletes
        Sanctum::actingAs($this->instructor1);
        $deleteResp = $this->deleteJson("/api/problems/{$probId}");
        $deleteResp->assertStatus(200);

        // Verify problem and relations removed
        $this->assertDatabaseMissing('problems', ['id' => $probId]);
        $this->assertDatabaseMissing('test_cases_rel', ['problem_id' => $probId]);
        $this->assertDatabaseMissing('problem_topics', ['problem_id' => $probId]);
        $this->assertDatabaseMissing('problem_sql_solutions', ['problem_id' => $probId]);
    }

    public function test_validate_reference_solution(): void
    {
        // Valid reference solution
        Sanctum::actingAs($this->instructor1);
        $resp = $this->postJson('/api/problems/validate', [
            'referenceSolution' => 'SELECT * FROM Customers WHERE Country = \'Vietnam\';',
        ]);
        $resp->assertStatus(200);
        $this->assertTrue(in_array(strtolower($resp->json('status')), ['success']));

        // Empty reference solution
        $respBad = $this->postJson('/api/problems/validate', [
            'referenceSolution' => '',
        ]);
        $respBad->assertStatus(400);

        // Student forbidden
        Sanctum::actingAs($this->studentUser);
        $respStudent = $this->postJson('/api/problems/validate', [
            'referenceSolution' => 'SELECT 1;',
        ]);
        $respStudent->assertStatus(403);

        // Validation with schema and seed data
        Sanctum::actingAs($this->instructor1);
        $respWithData = $this->postJson('/api/problems/validate', [
            'schema'            => "CREATE TABLE NHANVIEN (MANV char(4), HOTEN varchar(40), PHAI varchar(5) CHECK (PHAI IN (N'Nam', N'Nữ')));",
            'seedData'          => "INSERT INTO NHANVIEN VALUES ('NV01', N'Nguyen Van B', N'Nam');",
            'referenceSolution' => "SELECT MANV, HOTEN FROM NHANVIEN WHERE PHAI = N'Nam';",
        ]);
        $respWithData->assertStatus(200);
        $this->assertEquals('Success', $respWithData->json('status'));
        $this->assertEquals(['MANV', 'HOTEN'], $respWithData->json('table.columns'));
        $this->assertEquals([['NV01', 'Nguyen Van B']], $respWithData->json('table.rows'));
        $this->assertGreaterThan(0, $respWithData->json('executionTimeMs'));
    }
}
