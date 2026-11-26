<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Problem;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Quyền quản lý bài toán theo vai trò mới (capability): problem.create cho tạo/sửa/xóa,
 * problem.create hoặc assignment.create cho "góc nhìn giảng viên", không đọc loại tài khoản.
 */
class ProblemPermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tag = 'Perm' . substr(uniqid(), -7);
    }

    private function makeUser(string $legacyRole, array $permissions = null, string $name = null): User
    {
        $user = User::create([
            'name'            => $name ?? "User {$this->tag}",
            'email'           => 'prm_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($legacyRole),
            'status'          => 'Active',
            'initials'        => 'PU',
        ]);

        if ($permissions !== null) {
            DB::table('user_access_roles')->where('user_id', $user->id)->delete();
            $role = AccessRole::create(['name' => 'Role ' . uniqid()]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    private function makeProblem(User $creator, bool $listed = false): Problem
    {
        return Problem::create([
            'number'          => 'PRM-' . strtoupper(substr(uniqid(), -8)),
            'title'           => "Problem {$this->tag} " . uniqid(),
            'difficulty'      => 'Easy',
            'description'     => 'Mô tả',
            'requirements'    => 'Yêu cầu',
            'practice_listed' => $listed,
            'creator_id'      => $creator->id,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'number'    => 'PRM-' . strtoupper(substr(uniqid(), -8)),
            'title'     => "Created {$this->tag}",
            'difficulty' => 'Easy',
            'visibility' => 'Private',
            'statement' => 'Đề bài',
        ];
    }

    // ───────────────────────── tạo / sửa / xóa theo problem.create ─────────────────────────

    public function test_an_account_with_problem_create_can_author_even_if_users_role_is_student(): void
    {
        $author = $this->makeUser('student', ['problem.create']);
        Sanctum::actingAs($author);

        $res = $this->postJson('/api/problems', $this->payload())->assertStatus(201);
        $id = $res->json('id');
        $this->assertSame($author->id, Problem::findOrFail($id)->creator_id);

        $this->putJson("/api/problems/{$id}", $this->payload(['title' => "Renamed {$this->tag}"]))->assertOk();
        $this->assertSame("Renamed {$this->tag}", Problem::findOrFail($id)->title);

        $ids = collect($this->getJson('/api/problems')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($id), 'người có problem.create thấy bài của mình');

        $this->deleteJson("/api/problems/{$id}")->assertOk();
        $this->assertNull(Problem::find($id));
    }

    public function test_an_instructor_account_without_problem_create_cannot_author(): void
    {
        $user = $this->makeUser('instructor', ['practice.solve']);
        $mine = $this->makeProblem($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/problems', $this->payload())->assertStatus(403);
        $this->putJson("/api/problems/{$mine->id}", $this->payload())->assertStatus(403);
        $this->deleteJson("/api/problems/{$mine->id}")->assertStatus(403);
        $this->postJson('/api/problems/validate', ['referenceSolution' => 'SELECT 1'])->assertStatus(403);
        $this->postJson('/api/problems/validate-stage', [])->assertStatus(403);
        $this->assertNotNull(Problem::find($mine->id));
    }

    public function test_only_the_creator_may_edit(): void
    {
        $owner = $this->makeUser('instructor');
        $problem = $this->makeProblem($owner);
        $stranger = $this->makeUser('student', ['problem.create']);

        Sanctum::actingAs($stranger);
        $this->putJson("/api/problems/{$problem->id}", $this->payload())->assertStatus(403);
        $this->deleteJson("/api/problems/{$problem->id}")->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->putJson("/api/problems/{$problem->id}", $this->payload(['title' => "Edited {$this->tag}"]))->assertOk();
        $this->deleteJson("/api/problems/{$problem->id}")->assertOk();
    }

    public function test_the_admin_role_cannot_edit_or_delete_problems(): void
    {
        $owner = $this->makeUser('instructor');
        $problem = $this->makeProblem($owner);
        $admin = $this->makeUser('admin');

        Sanctum::actingAs($admin);
        $this->putJson("/api/problems/{$problem->id}", $this->payload(['title' => "By admin {$this->tag}"]))->assertStatus(403);
        $this->deleteJson("/api/problems/{$problem->id}")->assertStatus(403);
        $this->assertNotNull($problem->fresh());
    }

    // ───────────────────────── góc nhìn giảng viên / sinh viên ─────────────────────────

    public function test_staff_view_comes_from_permissions_not_users_role(): void
    {
        $assignmentOnly = $this->makeUser('visitor', ['assignment.create']);
        $practiceOnly = $this->makeUser('instructor', ['practice.solve']);
        $problem = $this->makeProblem($assignmentOnly);

        // assignment.create: góc nhìn giảng viên (thấy bài của mình).
        Sanctum::actingAs($assignmentOnly);
        $ids = collect($this->getJson('/api/problems')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($problem->id));

        // Chỉ practice.solve: góc nhìn sinh viên, không thấy bài riêng tư dù loại tài khoản là instructor.
        Sanctum::actingAs($practiceOnly);
        $ids = collect($this->getJson('/api/problems')->assertOk()->json())->pluck('id');
        $this->assertFalse($ids->contains($problem->id));
    }

    public function test_reference_solution_is_only_shown_to_staff_by_permission(): void
    {
        $author = $this->makeUser('student', ['problem.create']);
        $student = $this->makeUser('instructor', ['practice.solve']);

        Sanctum::actingAs($author);
        $id = $this->postJson('/api/problems', $this->payload(['visibility' => 'Public', 'referenceSolution' => 'SELECT 42;']))->assertStatus(201)->json('id');
        $this->assertSame('SELECT 42;', $this->getJson("/api/problems/{$id}")->assertOk()->json('referenceSolution'));

        Sanctum::actingAs($student);
        $this->assertSame('', $this->getJson("/api/problems/{$id}")->assertOk()->json('referenceSolution'));
    }

    public function test_a_private_problem_is_not_visible_to_unrelated_accounts(): void
    {
        $owner = $this->makeUser('instructor');
        $problem = $this->makeProblem($owner);
        $unrelated = $this->makeUser('instructor', ['problem.create']);

        Sanctum::actingAs($unrelated);
        $this->getJson("/api/problems/{$problem->id}")->assertStatus(403);
    }

    // ───────────────────────── chạy thử SQL cho thư viện ─────────────────────────

    public function test_library_manage_alone_can_use_the_library_test_run(): void
    {
        $user = $this->makeUser('student', ['library.manage']);
        Sanctum::actingAs($user);

        $this->postJson('/api/teacher/database-library/test-run', [
            'createTableSql' => 'CREATE TABLE PermLib (id INT PRIMARY KEY);',
            'insertIntoSql'  => 'INSERT INTO PermLib VALUES (1);',
        ])->assertOk();
    }
}
