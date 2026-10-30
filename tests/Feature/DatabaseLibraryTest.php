<?php

namespace Tests\Feature;

use App\Models\DatabaseLibraryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DatabaseLibraryTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $instructor1;
    protected User $instructor2;
    protected User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name'            => 'Admin Lib Test',
            'email'           => 'admin_lib_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'admin',
            'status'          => 'Active',
            'initials'        => 'AL',
        ]);

        $this->instructor1 = User::create([
            'name'            => 'Instructor One',
            'email'           => 'inst1_lib_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor',
            'status'          => 'Active',
            'initials'        => 'I1',
        ]);

        $this->instructor2 = User::create([
            'name'            => 'Instructor Two',
            'email'           => 'inst2_lib_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'instructor',
            'status'          => 'Active',
            'initials'        => 'I2',
        ]);

        $this->studentUser = User::create([
            'name'            => 'Student Lib Test',
            'email'           => 'student_lib_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'SL',
        ]);
    }

    public function test_student_cannot_access_database_library(): void
    {
        Sanctum::actingAs($this->studentUser);

        // GET index
        $res = $this->getJson('/api/teacher/database-library');
        $res->assertStatus(403);

        // POST create
        $resPost = $this->postJson('/api/teacher/database-library', [
            'title' => 'Student DB Attempt',
        ]);
        $resPost->assertStatus(403);
    }

    public function test_instructor_can_create_and_read_own_entry(): void
    {
        Sanctum::actingAs($this->instructor1);

        $payload = [
            'title'                  => 'University Management DB',
            'databaseFileName'       => 'university.sql',
            'createTableFileName'    => 'schema.sql',
            'createTableSql'         => 'CREATE TABLE Students (id INT PRIMARY KEY, name VARCHAR(100));',
            'insertIntoFileName'     => 'data.sql',
            'insertIntoSql'          => "INSERT INTO Students VALUES (1, 'Alice');",
            'datasets'               => [
                [
                    'fileName' => 'data.sql',
                    'sql'      => "INSERT INTO Students VALUES (1, 'Alice');",
                ]
            ]
        ];

        $postRes = $this->postJson('/api/teacher/database-library', $payload);
        $postRes->assertStatus(201);
        $entryId = $postRes->json('id');
        $this->assertNotEmpty($entryId);
        $this->assertEquals('University Management DB', $postRes->json('title'));

        // Instructor 1 can view
        $getRes = $this->getJson("/api/teacher/database-library/{$entryId}");
        $getRes->assertStatus(200);
        $this->assertEquals('university.sql', $getRes->json('databaseFileName'));
    }

    public function test_instructor2_cannot_view_or_modify_instructor1_private_entry(): void
    {
        // Instructor 1 creates entry
        Sanctum::actingAs($this->instructor1);
        $postRes = $this->postJson('/api/teacher/database-library', [
            'title'               => 'Instructor 1 Secret DB',
            'createTableSql'      => 'CREATE TABLE Secret (id INT);',
        ]);
        $postRes->assertStatus(201);
        $entryId = $postRes->json('id');

        // Instructor 2 tries to GET (show)
        Sanctum::actingAs($this->instructor2);
        $getRes = $this->getJson("/api/teacher/database-library/{$entryId}");
        $getRes->assertStatus(403);

        // Instructor 2 tries to PUT (update)
        $putRes = $this->putJson("/api/teacher/database-library/{$entryId}", [
            'title' => 'Tampered Title',
        ]);
        $putRes->assertStatus(403);

        // Instructor 2 tries to DELETE
        $delRes = $this->deleteJson("/api/teacher/database-library/{$entryId}");
        $delRes->assertStatus(403);
    }

    public function test_admin_can_view_update_and_delete_any_entry(): void
    {
        // Instructor 1 creates entry
        Sanctum::actingAs($this->instructor1);
        $postRes = $this->postJson('/api/teacher/database-library', [
            'title' => 'Instructor 1 Normal DB',
        ]);
        $entryId = $postRes->json('id');

        // Admin views
        Sanctum::actingAs($this->adminUser);
        $getRes = $this->getJson("/api/teacher/database-library/{$entryId}");
        $getRes->assertStatus(200);

        // Admin updates
        $putRes = $this->putJson("/api/teacher/database-library/{$entryId}", [
            'title' => 'Admin Updated Title',
        ]);
        $putRes->assertStatus(200);
        $this->assertEquals('Admin Updated Title', $putRes->json('title'));

        // Admin deletes
        $delRes = $this->deleteJson("/api/teacher/database-library/{$entryId}");
        $delRes->assertStatus(200);
        $this->assertTrue($delRes->json('ok'));

        $this->assertDatabaseMissing('database_library_entries', ['id' => $entryId]);
    }

    public function test_store_rejects_dangerous_sql_statements(): void
    {
        Sanctum::actingAs($this->instructor1);

        $payload = [
            'title'          => 'Malicious DB Entry',
            'createTableSql' => 'CREATE TABLE Test (id INT); EXEC xp_cmdshell("dir");',
        ];

        $res = $this->postJson('/api/teacher/database-library', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['createTableSql']);
    }

    public function test_test_run_endpoint_validates_sql_schema(): void
    {
        Sanctum::actingAs($this->instructor1);

        // Valid SQL test-run
        $validRes = $this->postJson('/api/teacher/database-library/test-run', [
            'createTableSql' => 'CREATE TABLE DemoLib (id INT PRIMARY KEY, name VARCHAR(50));',
            'insertIntoSql'  => "INSERT INTO DemoLib VALUES (1, 'Test');",
        ]);
        $validRes->assertStatus(200);
        $validRes->assertJsonFragment(['status' => 'Success']);

        // Empty SQL test-run
        $emptyRes = $this->postJson('/api/teacher/database-library/test-run', [
            'createTableSql' => '',
            'insertIntoSql'  => '',
        ]);
        $emptyRes->assertStatus(422);
    }

    public function test_index_supports_search_scope_and_pagination(): void
    {
        Sanctum::actingAs($this->instructor1);

        // Create 2 entries for instructor 1
        DatabaseLibraryEntry::create([
            'id'                  => (string) Str::uuid(),
            'instructor_id'       => $this->instructor1->id,
            'title'               => 'E-Commerce Store DB',
            'database_file_name'  => 'ecommerce.sql',
            'create_table_sql'    => 'CREATE TABLE Products (id INT);',
        ]);

        DatabaseLibraryEntry::create([
            'id'                  => (string) Str::uuid(),
            'instructor_id'       => $this->instructor1->id,
            'title'               => 'Hospital Clinical System',
            'database_file_name'  => 'hospital.sql',
            'create_table_sql'    => 'CREATE TABLE Doctors (id INT);',
        ]);

        // Create 1 system entry (no instructor_id)
        DatabaseLibraryEntry::create([
            'id'                  => (string) Str::uuid(),
            'instructor_id'       => null,
            'title'               => 'Global Standard Northwind',
            'database_file_name'  => 'northwind.sql',
            'create_table_sql'    => 'CREATE TABLE Customers (id INT);',
        ]);

        // 1. Search by keyword
        $searchRes = $this->getJson('/api/teacher/database-library?search=Hospital');
        $searchRes->assertStatus(200);
        $titles = collect($searchRes->json())->pluck('title');
        $this->assertTrue($titles->contains('Hospital Clinical System'));
        $this->assertFalse($titles->contains('E-Commerce Store DB'));

        // 2. Filter scope=system
        $sysRes = $this->getJson('/api/teacher/database-library?scope=system');
        $sysRes->assertStatus(200);
        $sysTitles = collect($sysRes->json())->pluck('title');
        $this->assertTrue($sysTitles->contains('Global Standard Northwind'));
        $this->assertFalse($sysTitles->contains('E-Commerce Store DB'));

        // 3. Filter scope=my
        $myRes = $this->getJson('/api/teacher/database-library?scope=my');
        $myRes->assertStatus(200);
        $myTitles = collect($myRes->json())->pluck('title');
        $this->assertTrue($myTitles->contains('E-Commerce Store DB'));
        $this->assertFalse($myTitles->contains('Global Standard Northwind'));

        // 4. Pagination
        $pageRes = $this->getJson('/api/teacher/database-library?page=1&per_page=2');
        $pageRes->assertStatus(200);
        $pageRes->assertJsonStructure(['data', 'total', 'currentPage', 'lastPage', 'perPage']);
        $this->assertCount(2, $pageRes->json('data'));
    }
}

