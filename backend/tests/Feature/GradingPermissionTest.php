<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Quyền của nhóm chấm bài, kết quả, phúc khảo, kỳ thi, coi thi và thống kê theo vai trò mới (capability):
 * results.grade, results.view_all, examination.manage, exam.credentials, exam.proctor.
 * Mọi tài khoản dưới đây có loại tài khoản KHÔNG ăn khớp với quyền được gán, để chứng minh code không đọc loại tài khoản.
 */
class GradingPermissionTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private function makeUser(string $legacyRole, ?array $permissions = null, string $name = null): User
    {
        $user = User::create([
            'name'            => $name ?? 'GP ' . uniqid(),
            'email'           => 'gp_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($legacyRole),
            'status'          => 'Active',
            'initials'        => 'GP',
        ]);

        if ($permissions !== null) {
            DB::table('user_access_roles')->where('user_id', $user->id)->delete();
            $role = AccessRole::create(['name' => 'Role ' . uniqid(), 'is_super' => false]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    /** @return array{0: Assignment, 1: Submission, 2: User} bài tập của $owner, bài nộp của một sinh viên */
    private function submissionFixture(User $owner): array
    {
        $problem = Problem::create([
            'number' => 'GP-' . strtoupper(substr(uniqid(), -8)), 'title' => 'GP problem', 'difficulty' => 'Easy',
            'description' => 'd', 'requirements' => 'r', 'practice_listed' => false, 'creator_id' => $owner->id,
        ]);
        $assignment = Assignment::create([
            'title' => 'GP assignment ' . uniqid(), 'instructor_id' => $owner->id, 'published' => true,
            'opens' => now()->subDay(), 'closes' => now()->addDays(2),
        ]);
        $assignment->problems()->attach($problem->id, ['points' => 10, 'order_index' => 1]);
        $student = $this->makeUser('student');
        $sub = Submission::create([
            'id' => (string) Str::uuid(), 'user_id' => $student->id, 'problem_id' => $problem->id,
            'source' => 'Assignments', 'context' => $assignment->id, 'result' => 'Pending', 'score' => 0,
            'submitted_at' => now(), 'query_text' => 'SELECT 1',
        ]);

        return [$assignment, $sub, $student];
    }

    // ───────────── chấm bài: results.grade ─────────────

    public function test_review_needs_results_grade_and_the_owners_scope(): void
    {
        $owner = $this->makeUser('student', ['results.grade', 'assignment.create']);   // loại tài khoản không phải giảng viên
        [$assignment, $sub] = $this->submissionFixture($owner);
        $payload = ['finalScore' => 7.5, 'feedback' => 'Tốt'];

        Sanctum::actingAs($owner);
        $this->putJson("/api/submissions/{$sub->id}/review", $payload)->assertOk();
        $this->assertSame(7.5, (float) $sub->fresh()->evaluated_score);

        $stripped = $this->makeUser('instructor', ['practice.solve']);                  // loại tài khoản là giảng viên nhưng hết quyền
        Sanctum::actingAs($stripped);
        $this->putJson("/api/submissions/{$sub->id}/review", $payload)->assertStatus(403);

        $strangerGrader = $this->makeUser('instructor', ['results.grade']);              // có quyền nhưng bài tập không thuộc về họ
        Sanctum::actingAs($strangerGrader);
        $this->putJson("/api/submissions/{$sub->id}/review", ['finalScore' => 1])->assertStatus(403);
        $this->assertSame(7.5, (float) $sub->fresh()->evaluated_score);
    }

    public function test_admin_never_grades_but_a_person_who_is_also_a_grader_does(): void
    {
        $owner = $this->makeUser('instructor');
        [, $sub] = $this->submissionFixture($owner);

        $admin = $this->makeUser('admin');
        Sanctum::actingAs($admin);
        $this->putJson("/api/submissions/{$sub->id}/review", ['finalScore' => 5])->assertStatus(403);

        // Admin đồng thời giữ vai trò có results.grade: quyền được cộng gộp, chấm được (xem mọi bài nhờ siêu quản trị).
        $role = AccessRole::create(['name' => 'Chấm ' . uniqid(), 'is_super' => false]);
        $role->syncPermissions(['results.grade']);
        $admin->accessRoles()->attach($role->id);
        app(AccessControlService::class)->forget();
        $this->putJson("/api/submissions/{$sub->id}/review", ['finalScore' => 5])->assertOk();
    }

    public function test_viewers_without_grade_but_with_view_all_can_open_but_not_edit(): void
    {
        $owner = $this->makeUser('instructor');
        [, $sub] = $this->submissionFixture($owner);
        $viewer = $this->makeUser('student', ['results.view_all']);

        Sanctum::actingAs($viewer);
        $res = $this->getJson("/api/submissions/{$sub->id}")->assertOk();
        $this->assertFalse($res->json('canEditReview'));
        $this->putJson("/api/submissions/{$sub->id}/review", ['finalScore' => 5])->assertStatus(403);
    }

    public function test_default_faculty_office_role_can_view_results_but_never_grade(): void
    {
        $this->useDefaultRolePermissions();
        $owner = $this->makeUser('instructor');
        [$assignment, $sub] = $this->submissionFixture($owner);
        $office = $this->makeUser('faculty_office');
        $this->assertFalse(app(AccessControlService::class)->can($office, 'results.grade'));

        Sanctum::actingAs($office);
        $res = $this->getJson("/api/submissions/{$sub->id}")->assertOk();
        $this->assertFalse($res->json('canEditReview'));
        $this->putJson("/api/submissions/{$sub->id}/review", ['finalScore' => 5])->assertStatus(403);
        $this->assertSame('Pending', $sub->fresh()->result, 'điểm không đổi');
        $this->getJson('/api/office/statistics')->assertOk();
    }

    public function test_a_submission_is_only_visible_to_its_owner_or_staff(): void
    {
        $owner = $this->makeUser('instructor');
        [, $sub, $student] = $this->submissionFixture($owner);
        $classmate = $this->makeUser('student');
        $teacherOwner = $owner;

        Sanctum::actingAs($student);
        $this->getJson("/api/submissions/{$sub->id}")->assertOk();

        Sanctum::actingAs($classmate);
        $this->getJson("/api/submissions/{$sub->id}")->assertStatus(403);

        Sanctum::actingAs($teacherOwner);
        $this->getJson("/api/submissions/{$sub->id}")->assertOk();

        // loại tài khoản là student nhưng được cấp quyền nhân sự: xem được bài của lớp mình (bài tập do mình soạn)
        $author = $this->makeUser('student', ['assignment.create', 'results.grade']);
        [, $sub2] = $this->submissionFixture($author);
        Sanctum::actingAs($author);
        $this->getJson("/api/submissions/{$sub2->id}")->assertOk();
    }

    public function test_student_work_view_is_for_staff_only(): void
    {
        $owner = $this->makeUser('instructor');
        [$assignment, $sub, $student] = $this->submissionFixture($owner);
        $url = "/api/submissions/activity/{$assignment->id}/students/{$student->id}/problems/{$sub->problem_id}";

        Sanctum::actingAs($this->makeUser('instructor', ['practice.solve']));
        $this->getJson($url)->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->getJson($url)->assertOk();

        Sanctum::actingAs($this->makeUser('student', ['results.view_all']));
        $this->getJson($url)->assertOk();
    }

    public function test_publishing_results_is_limited_to_staff_and_their_own_activities(): void
    {
        $owner = $this->makeUser('student', ['assignment.create', 'results.grade']);
        [$assignment] = $this->submissionFixture($owner);
        $other = $this->makeUser('instructor', ['assignment.create', 'results.grade']);
        $viewer = $this->makeUser('student', ['practice.solve']);
        $manager = $this->makeUser('student', ['examination.manage']);

        Sanctum::actingAs($viewer);
        $this->postJson("/api/results/{$assignment->id}/publish", ['published' => false])->assertStatus(403);
        Sanctum::actingAs($other);
        $this->postJson("/api/results/{$assignment->id}/publish", ['published' => false])->assertStatus(403);
        Sanctum::actingAs($owner);
        $this->postJson("/api/results/{$assignment->id}/publish", ['published' => false])->assertOk()->assertJson(['published' => false]);
        Sanctum::actingAs($manager);
        $this->postJson("/api/results/{$assignment->id}/publish", ['published' => true])->assertOk()->assertJson(['published' => true]);
    }

    // ───────────── phúc khảo ─────────────

    public function test_regrade_assignment_needs_examination_manage_and_reviewers_hold_results_grade(): void
    {
        $owner = $this->makeUser('instructor');
        [, $sub] = $this->submissionFixture($owner);
        $reviewer = $this->makeUser('student', ['results.grade'], 'Reviewer ' . uniqid());
        $notReviewer = $this->makeUser('instructor', ['problem.create'], 'NotReviewer ' . uniqid());
        $manager = $this->makeUser('student', ['examination.manage']);

        Sanctum::actingAs($notReviewer);
        $this->getJson('/api/regrade-assignments/reviewers')->assertStatus(403);
        $this->putJson("/api/regrade-assignments/{$sub->id}", ['instructor_id' => $reviewer->id])->assertStatus(403);

        Sanctum::actingAs($manager);
        $ids = collect($this->getJson('/api/regrade-assignments/reviewers')->assertOk()->json())->pluck('id')->all();
        $this->assertContains($reviewer->id, $ids);
        $this->assertNotContains($notReviewer->id, $ids);
        $this->assertNotContains($manager->id, $ids);

        Sanctum::actingAs($reviewer);
        $this->getJson('/api/regrade-assignments/mine')->assertOk();
        Sanctum::actingAs($manager);
        $this->getJson('/api/regrade-assignments/mine')->assertStatus(403);
    }

    // ───────────── kỳ thi ─────────────

    public function test_examinations_need_examination_manage_or_exam_credentials(): void
    {
        Sanctum::actingAs($this->makeUser('instructor', ['practice.solve', 'problem.create', 'results.grade']));
        $this->getJson('/api/examinations')->assertStatus(403);

        Sanctum::actingAs($this->makeUser('student', ['examination.manage']));
        $this->getJson('/api/examinations')->assertOk();

        Sanctum::actingAs($this->makeUser('student', ['exam.credentials']));
        $this->getJson('/api/examinations')->assertOk();

        Sanctum::actingAs($this->makeUser('admin'));
        $this->getJson('/api/examinations')->assertOk();
    }

    public function test_examination_visibility_comes_from_examination_manage_not_the_owner_role(): void
    {
        $owner = $this->makeUser('student', ['examination.manage']);
        $examId = (string) Str::uuid();
        DB::table('examinations')->insert([
            'id' => $examId, 'title' => 'GP exam ' . uniqid(), 'exam_date' => '2026-12-01', 'owner_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $onlyCredentials = $this->makeUser('instructor', ['exam.credentials']);
        $manager = $this->makeUser('student', ['examination.manage']);

        Sanctum::actingAs($onlyCredentials);
        $ids = collect($this->getJson('/api/examinations')->assertOk()->json())->pluck('id')->all();
        $this->assertNotContains($examId, $ids, 'chỉ có exam.credentials: chỉ thấy kỳ thi của mình');

        Sanctum::actingAs($manager);
        $ids = collect($this->getJson('/api/examinations')->assertOk()->json())->pluck('id')->all();
        $this->assertContains($examId, $ids, 'examination.manage thấy mọi kỳ thi');
    }

    public function test_exam_session_proctor_list_contains_exam_proctor_holders_only(): void
    {
        $proctor = $this->makeUser('student', ['exam.proctor'], 'Proctor ' . uniqid());
        $nonProctor = $this->makeUser('instructor', ['practice.solve'], 'NonProctor ' . uniqid());
        $admin = $this->makeUser('admin', null, 'AdminOnly ' . uniqid());

        Sanctum::actingAs($this->makeUser('student', ['examination.manage']));
        $ids = collect($this->getJson('/api/exam-sessions/proctors')->assertOk()->json())->pluck('id')->all();
        $this->assertContains($proctor->id, $ids);
        $this->assertNotContains($nonProctor->id, $ids);
        $this->assertNotContains($admin->id, $ids);

        Sanctum::actingAs($nonProctor);
        $this->getJson('/api/exam-sessions/proctors')->assertStatus(403);
    }

    // ───────────── coi thi ─────────────

    public function test_proctor_endpoints_need_exam_proctor_or_examination_manage(): void
    {
        Sanctum::actingAs($this->makeUser('instructor', ['practice.solve', 'results.grade']));
        $this->getJson('/api/proctor/sessions')->assertStatus(403);

        Sanctum::actingAs($this->makeUser('student', ['exam.proctor']));
        $this->getJson('/api/proctor/sessions')->assertOk();

        Sanctum::actingAs($this->makeUser('student', ['examination.manage']));
        $this->getJson('/api/proctor/sessions')->assertOk();
    }

    // ───────────── thống kê ─────────────

    public function test_statistics_access(): void
    {
        $student = $this->makeUser('instructor', ['practice.solve']);
        Sanctum::actingAs($student);
        $this->getJson('/api/teacher/statistics')->assertStatus(403);
        $this->getJson('/api/office/statistics')->assertStatus(403);

        Sanctum::actingAs($this->makeUser('student', ['results.grade']));
        $this->getJson('/api/teacher/statistics')->assertOk();
        $this->getJson('/api/office/statistics')->assertStatus(403);

        Sanctum::actingAs($this->makeUser('student', ['results.view_all']));
        $this->getJson('/api/teacher/statistics')->assertOk();
        $this->getJson('/api/office/statistics')->assertOk();

        Sanctum::actingAs($this->makeUser('admin'));
        $this->getJson('/api/office/statistics')->assertOk();
    }
}
