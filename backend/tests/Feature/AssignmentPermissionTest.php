<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Quyền của module bài tập và khu vực sinh viên theo vai trò mới (capability):
 * assignment.create (soạn), results.view_all / examination.manage (xem mọi bài), practice.solve (khu vực sinh viên).
 */
class AssignmentPermissionTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $legacyRole, ?array $permissions = null): User
    {
        $user = User::create([
            'name'            => 'AP ' . uniqid(),
            'email'           => 'ap_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($legacyRole),
            'status'          => 'Active',
            'initials'        => 'AP',
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

    private function makeAssignment(User $owner, bool $published = true): Assignment
    {
        return Assignment::create([
            'title'         => 'AP assignment ' . uniqid(),
            'instructor_id' => $owner->id,
            'published'     => $published,
            'opens'         => now()->subDay(),
            'closes'        => now()->addDays(3),
        ]);
    }

    private function listedIds(): array
    {
        return collect($this->getJson('/api/assignments')->assertOk()->json())->pluck('id')->all();
    }

    // ───────────── soạn bài tập: assignment.create ─────────────

    public function test_creating_updating_and_deleting_assignments_needs_assignment_create(): void
    {
        $author = $this->makeUser('student', ['assignment.create']);
        $denied = $this->makeUser('instructor', ['practice.solve']);

        Sanctum::actingAs($author);
        $id = $this->postJson('/api/assignments', ['title' => 'Bài của người soạn', 'status' => 'draft'])->assertStatus(201)->json('id');
        $this->putJson("/api/assignments/{$id}", ['title' => 'Đã sửa', 'status' => 'draft'])->assertOk();
        $this->assertContains($id, $this->listedIds());

        Sanctum::actingAs($denied);
        $this->postJson('/api/assignments', ['title' => 'X', 'status' => 'draft'])->assertStatus(403);
        $this->putJson("/api/assignments/{$id}", ['title' => 'X', 'status' => 'draft'])->assertStatus(403);
        $this->deleteJson("/api/assignments/{$id}")->assertStatus(403);

        Sanctum::actingAs($author);
        $this->deleteJson("/api/assignments/{$id}")->assertOk();
    }

    public function test_an_author_only_sees_and_edits_their_own_assignments(): void
    {
        $author = $this->makeUser('instructor', ['assignment.create']);
        $other = $this->makeUser('instructor', ['assignment.create']);
        $theirs = $this->makeAssignment($other);
        $mine = $this->makeAssignment($author);

        Sanctum::actingAs($author);
        $ids = $this->listedIds();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->putJson("/api/assignments/{$theirs->id}", ['title' => 'Chiếm quyền', 'status' => 'draft'])->assertStatus(403);
        $this->deleteJson("/api/assignments/{$theirs->id}")->assertStatus(403);
        $this->getJson("/api/assignments/{$theirs->id}")->assertStatus(403);
    }

    // ───────────── xem mọi bài tập ─────────────

    public function test_view_all_permissions_see_every_assignment(): void
    {
        $owner = $this->makeUser('instructor');
        $assignment = $this->makeAssignment($owner);

        foreach ([
            $this->makeUser('student', ['results.view_all']),
            $this->makeUser('student', ['examination.manage']),
        ] as $viewer) {
            Sanctum::actingAs($viewer);
            $this->assertContains($assignment->id, $this->listedIds());
            $this->getJson("/api/assignments/{$assignment->id}")->assertOk();
        }
    }

    public function test_the_admin_role_has_no_implicit_view_of_assignments(): void
    {
        $assignment = $this->makeAssignment($this->makeUser('instructor'));

        Sanctum::actingAs($this->makeUser('admin'));
        $this->assertNotContains($assignment->id, $this->listedIds());
        $this->getJson("/api/assignments/{$assignment->id}")->assertStatus(403);
    }

    public function test_view_all_does_not_allow_authoring_without_assignment_create(): void
    {
        $viewer = $this->makeUser('instructor', ['results.view_all']);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/assignments', ['title' => 'X', 'status' => 'draft'])->assertStatus(403);
    }

    public function test_examination_manager_can_edit_and_delete_any_assignment(): void
    {
        $owner = $this->makeUser('instructor');
        $assignment = $this->makeAssignment($owner, false);
        $manager = $this->makeUser('student', ['examination.manage', 'assignment.create']);

        Sanctum::actingAs($manager);
        $this->putJson("/api/assignments/{$assignment->id}", ['title' => 'Do giáo vụ sửa', 'status' => 'draft'])->assertOk();
        $this->deleteJson("/api/assignments/{$assignment->id}")->assertOk();
    }

    // ───────────── khu vực sinh viên: practice.solve ─────────────

    public function test_student_area_endpoints_need_their_tab_permission_not_users_role(): void
    {
        $allowed = $this->makeUser('instructor', ['practice.solve', 'assignment.do', 'submission.view']);
        $practiceOnly = $this->makeUser('instructor', ['practice.solve']);
        $blocked = $this->makeUser('student', ['problem.create']);

        Sanctum::actingAs($allowed);
        $this->getJson('/api/student/assignments')->assertOk();
        $this->getJson('/api/student/dashboard')->assertOk();
        $this->getJson('/api/student/preferences')->assertOk();
        $this->getJson('/api/submissions')->assertOk();

        // Tắt tab Bài tập / Bài nộp: chỉ còn luyện tập.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($practiceOnly);
        $this->getJson('/api/student/dashboard')->assertOk();
        $this->getJson('/api/student/assignments')->assertStatus(403);
        $this->getJson('/api/submissions')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($blocked);
        $this->getJson('/api/student/assignments')->assertStatus(403);
        $this->getJson('/api/student/dashboard')->assertStatus(403);
        $this->getJson('/api/student/preferences')->assertStatus(403);
    }

    public function test_a_student_sees_only_published_assignments_of_their_classes(): void
    {
        $owner = $this->makeUser('instructor');
        $student = $this->makeUser('student');
        $class = ClassModel::create(['id' => 'AP_' . strtoupper(uniqid()), 'course' => 'C', 'term' => 'T', 'instructor_id' => $owner->id, 'status' => 'Active']);
        $class->students()->attach($student->id, ['role' => 'Member', 'joined_at' => now()]);
        $visible = $this->makeAssignment($owner);
        $visible->classes()->attach($class->id);
        $draft = $this->makeAssignment($owner, false);
        $draft->classes()->attach($class->id);
        $elsewhere = $this->makeAssignment($owner);

        Sanctum::actingAs($student);
        $ids = $this->listedIds();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
    }

    public function test_enrolled_classes_listing_is_available_to_any_signed_in_account_and_only_shows_own_enrollment(): void
    {
        $student = $this->makeUser('student');
        $owner = $this->makeUser('instructor');
        $mine = ClassModel::create(['id' => 'AP_' . strtoupper(uniqid()), 'course' => 'Mine', 'term' => 'T', 'instructor_id' => $owner->id, 'status' => 'Active']);
        $mine->students()->attach($student->id, ['role' => 'Member', 'joined_at' => now()]);
        $other = ClassModel::create(['id' => 'AP_' . strtoupper(uniqid()), 'course' => 'Other', 'term' => 'T', 'instructor_id' => $owner->id, 'status' => 'Active']);

        Sanctum::actingAs($student);
        $ids = collect($this->getJson('/api/student/classes')->assertOk()->json())->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);

        Sanctum::actingAs($owner);
        $this->assertSame([], $this->getJson('/api/student/classes')->assertOk()->json());
    }
}
