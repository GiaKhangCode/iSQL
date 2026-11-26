<?php

namespace Tests\Feature;

use App\Models\DatabaseLibraryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
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
            ...AccountFixture::for('admin'),
            'status'          => 'Active',
            'initials'        => 'AL',
        ]);

        $this->instructor1 = User::create([
            'name'            => 'Instructor One',
            'email'           => 'inst1_lib_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for('instructor'),
            'status'          => 'Active',
            'initials'        => 'I1',
        ]);

        $this->instructor2 = User::create([
            'name'            => 'Instructor Two',
            'email'           => 'inst2_lib_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for('instructor'),
            'status'          => 'Active',
            'initials'        => 'I2',
        ]);

        $this->studentUser = User::create([
            'name'            => 'Student Lib Test',
            'email'           => 'student_lib_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for('student'),
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

    public function test_the_admin_role_has_no_access_to_the_database_library(): void
    {
        // Instructor 1 creates entry
        Sanctum::actingAs($this->instructor1);
        $postRes = $this->postJson('/api/teacher/database-library', [
            'title' => 'Instructor 1 Normal DB',
        ]);
        $entryId = $postRes->json('id');

        // Admin chỉ có 4 quyền quản trị: không có library.manage nên mọi thao tác bị chặn.
        Sanctum::actingAs($this->adminUser);
        $this->getJson('/api/teacher/database-library')->assertStatus(403);
        $this->getJson("/api/teacher/database-library/{$entryId}")->assertStatus(403);
        $this->putJson("/api/teacher/database-library/{$entryId}", ['title' => 'Admin Updated Title'])->assertStatus(403);
        $this->deleteJson("/api/teacher/database-library/{$entryId}")->assertStatus(403);

        $this->assertDatabaseHas('database_library_entries', ['id' => $entryId, 'title' => 'Instructor 1 Normal DB']);
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

    private function makeEntry(?User $owner, string $title, array $extra = []): DatabaseLibraryEntry
    {
        return DatabaseLibraryEntry::create($extra + [
            'id'               => (string) Str::uuid(),
            'instructor_id'    => $owner?->id,
            'title'            => $title,
            'create_table_sql' => 'CREATE TABLE T (id INT);',
            'revision'         => 1,
        ]);
    }

    public function test_scope_all_shows_own_and_system_but_not_others(): void
    {
        $mine = $this->makeEntry($this->instructor1, 'Scope Mine ' . uniqid());
        $system = $this->makeEntry(null, 'Scope System ' . uniqid());
        $others = $this->makeEntry($this->instructor2, 'Scope Others ' . uniqid());

        Sanctum::actingAs($this->instructor1);
        $ids = collect($this->getJson('/api/teacher/database-library')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertTrue($ids->contains($system->id));
        $this->assertFalse($ids->contains($others->id));

        // Không còn ngoại lệ cho siêu quản trị: ai có library.manage cũng chỉ thấy của mình và mẫu hệ thống.
        Sanctum::actingAs($this->instructor2);
        $ids = collect($this->getJson('/api/teacher/database-library')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($others->id));
        $this->assertTrue($ids->contains($system->id));
        $this->assertFalse($ids->contains($mine->id));
    }

    public function test_instructor_can_view_but_not_edit_or_delete_system_entry(): void
    {
        $system = $this->makeEntry(null, 'System Template');

        Sanctum::actingAs($this->instructor1);
        $this->getJson("/api/teacher/database-library/{$system->id}")->assertOk();
        $this->putJson("/api/teacher/database-library/{$system->id}", ['title' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/teacher/database-library/{$system->id}")->assertStatus(403);
        $this->assertDatabaseHas('database_library_entries', ['id' => $system->id, 'title' => 'System Template']);
    }

    public function test_show_update_destroy_return_404_for_unknown_id(): void
    {
        Sanctum::actingAs($this->instructor1);
        $id = (string) Str::uuid();
        $this->getJson("/api/teacher/database-library/{$id}")->assertStatus(404);
        $this->putJson("/api/teacher/database-library/{$id}", ['title' => 'X'])->assertStatus(404);
        $this->deleteJson("/api/teacher/database-library/{$id}")->assertStatus(404);
    }

    public function test_store_requires_title_and_sets_revision_one_and_owner(): void
    {
        Sanctum::actingAs($this->instructor1);
        $this->postJson('/api/teacher/database-library', [])->assertStatus(422)->assertJsonValidationErrors(['title']);

        $res = $this->postJson('/api/teacher/database-library', [
            'title'               => '  Padded Title  ',
            'create_table_sql'    => 'CREATE TABLE A (id INT);',
            'insert_into_sql'     => 'INSERT INTO A VALUES (1);',
            'insertIntoFileName'  => 'a.sql',
        ])->assertStatus(201);

        $this->assertSame('Padded Title', $res->json('title'));
        $this->assertSame(1, $res->json('revision'));
        $this->assertSame('CREATE TABLE A (id INT);', $res->json('createTableSql'));
        // Không gửi datasets: dựng từ insert_into_*.
        $this->assertSame([['fileName' => 'a.sql', 'sql' => 'INSERT INTO A VALUES (1);']], $res->json('datasets'));
        $this->assertDatabaseHas('database_library_entries', [
            'id' => $res->json('id'), 'instructor_id' => $this->instructor1->id,
        ]);
    }

    public function test_update_is_partial_and_bumps_revision(): void
    {
        $entry = $this->makeEntry($this->instructor1, 'Original', [
            'database_file_name' => 'orig.sql',
            'create_table_sql'   => 'CREATE TABLE Keep (id INT);',
        ]);

        Sanctum::actingAs($this->instructor1);
        $res = $this->putJson("/api/teacher/database-library/{$entry->id}", ['title' => ' Renamed '])->assertOk();

        $this->assertSame('Renamed', $res->json('title'));
        $this->assertSame('orig.sql', $res->json('databaseFileName'));
        $this->assertSame('CREATE TABLE Keep (id INT);', $res->json('createTableSql'));
        $this->assertSame(2, $res->json('revision'));
    }

    public function test_update_datasets_drive_insert_into_fields(): void
    {
        $entry = $this->makeEntry($this->instructor1, 'DS');

        Sanctum::actingAs($this->instructor1);
        $res = $this->putJson("/api/teacher/database-library/{$entry->id}", [
            'datasets' => [['fileName' => 'one.sql', 'sql' => 'INSERT INTO T VALUES (1);']],
        ])->assertOk();

        $this->assertSame('one.sql', $res->json('insertIntoFileName'));
        $this->assertSame('INSERT INTO T VALUES (1);', $res->json('insertIntoSql'));
    }

    public function test_update_with_stale_expected_revision_returns_409_and_changes_nothing(): void
    {
        $entry = $this->makeEntry($this->instructor1, 'Locked');

        Sanctum::actingAs($this->instructor1);
        $res = $this->putJson("/api/teacher/database-library/{$entry->id}", [
            'title' => 'Stale', 'expectedRevision' => 5,
        ])->assertStatus(409);
        $this->assertNotEmpty($res->json('detail'));
        $this->assertSame('Locked', $entry->fresh()->title);
        $this->assertSame(1, $entry->fresh()->revision);

        $this->putJson("/api/teacher/database-library/{$entry->id}", [
            'title' => 'Fresh', 'expected_revision' => 1,
        ])->assertOk();
        $this->assertSame(2, $entry->fresh()->revision);
    }

    public function test_bundle_is_only_replaced_when_sent(): void
    {
        $entry = $this->makeEntry($this->instructor1, 'Bundle', ['bundle_json' => ['k' => 'v']]);

        Sanctum::actingAs($this->instructor1);
        $this->putJson("/api/teacher/database-library/{$entry->id}", ['title' => 'B2'])->assertOk();
        $this->assertSame(['k' => 'v'], $entry->fresh()->bundle_json);

        $this->putJson("/api/teacher/database-library/{$entry->id}", ['bundle' => ['k' => 'w']])->assertOk();
        $this->assertSame(['k' => 'w'], $entry->fresh()->bundle_json);
    }

    // ───────────────────────── quyền theo vai trò mới (capability), không theo loại tài khoản ─────────────────────────

    /** Thay toàn bộ vai trò của người dùng bằng một vai trò tùy chỉnh chỉ có các quyền cho trước. */
    private function onlyPermissions(User $user, array $permissions): User
    {
        \Illuminate\Support\Facades\DB::table('user_access_roles')->where('user_id', $user->id)->delete();
        $role = \App\Models\AccessRole::create(['name' => 'Test ' . uniqid()]);
        $role->syncPermissions($permissions);
        $user->accessRoles()->attach($role->id);
        app(\App\Services\Access\AccessControlService::class)->forget();

        return $user;
    }

    public function test_a_student_account_with_library_manage_role_can_use_the_library(): void
    {
        $user = $this->onlyPermissions($this->studentUser, ['library.manage']);
        $this->assertSame('student', $user->account_type, 'loại tài khoản không đổi, quyền đến từ vai trò');

        Sanctum::actingAs($user);
        $this->postJson('/api/teacher/database-library', ['title' => 'Từ vai trò mới'])->assertStatus(201);
        $this->getJson('/api/teacher/database-library')->assertOk();
    }

    public function test_an_instructor_account_without_library_permissions_is_denied(): void
    {
        $user = $this->onlyPermissions($this->instructor1, ['practice.solve']);
        $this->assertSame('staff', $user->account_type);

        Sanctum::actingAs($user);
        $this->getJson('/api/teacher/database-library')->assertStatus(403);
        $this->postJson('/api/teacher/database-library', ['title' => 'X'])->assertStatus(403);
        $this->postJson('/api/teacher/database-library/test-run', ['createTableSql' => 'CREATE TABLE A (id INT);'])->assertStatus(403);
    }

    public function test_problem_create_alone_can_read_but_not_change_the_library(): void
    {
        $entry = $this->makeEntry(null, 'Mẫu hệ thống để đọc');
        $user = $this->onlyPermissions($this->instructor2, ['problem.create']);

        Sanctum::actingAs($user);
        $ids = collect($this->getJson('/api/teacher/database-library')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($entry->id));
        $this->getJson("/api/teacher/database-library/{$entry->id}")->assertOk();
        $this->postJson('/api/teacher/database-library', ['title' => 'X'])->assertStatus(403);
        $this->putJson("/api/teacher/database-library/{$entry->id}", ['title' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/teacher/database-library/{$entry->id}")->assertStatus(403);
    }

    public function test_system_entries_cannot_be_edited_through_the_api(): void
    {
        $system = $this->makeEntry(null, 'Mẫu hệ thống');

        Sanctum::actingAs($this->adminUser);
        $this->putJson("/api/teacher/database-library/{$system->id}", ['title' => 'Đã sửa'])->assertStatus(403);

        // Người có library.manage cũng không sửa được mẫu hệ thống.
        Sanctum::actingAs($this->instructor1);
        $this->putJson("/api/teacher/database-library/{$system->id}", ['title' => 'Không được'])->assertStatus(403);
    }
}
