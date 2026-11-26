<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\ExamRegrade;
use App\Models\Examination;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Test đặc tả cho /api/regrade-assignments/* (RegradeAssignmentController).
 *
 * Phúc khảo gắn với cả bài thi (một sinh viên trong một ca thi), không gắn với một lượt nộp. Giáo vụ
 * (examination.manage) phân công người chấm khi bài thi đã có điểm chính thức và điểm đã công bố; người chấm là tài
 * khoản Active giữ results.regrade và không phải giảng viên thực hành đứng lớp của chính sinh viên đó. DB dev có dữ
 * liệu sẵn nên mọi ca dùng dữ liệu mẫu do test tạo và so sánh theo id.
 */
class RegradeAssignmentTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private const BASE = '/api/regrade-assignments';

    /** Khóa của một dòng ứng viên, đúng thứ tự frontend đang nhận. */
    private const ROW_KEYS = [
        'submissionId', 'sessionId', 'sessionTitle', 'examinationId', 'activityId', 'activityTitle', 'isExamSession',
        'studentId', 'studentName', 'student', 'studentCode', 'studentEmail', 'className', 'problemId', 'problemTitle',
        'problem', 'score', 'maxScore', 'status', 'submittedAt', 'reviewerId', 'reviewerName', 'assignedInstructorId',
        'assignedInstructor', 'assignedInstructorEmail', 'assignedAt', 'completedAt', 'assignmentNote', 'note',
        'excludedReviewerIds',
    ];

    private User $manager;      // giáo vụ: examination.manage
    private User $owner;        // chủ đề thi
    private Assignment $exam;   // ca thi độc lập (exam_mode)
    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        $this->manager = $this->makeUser('student', ['examination.manage'], 'RG Manager ' . uniqid());
        $this->owner = $this->makeUser('instructor');
        $this->problem = $this->makeProblem();
        $this->exam = $this->makeExam();
        Sanctum::actingAs($this->manager);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @param string[]|null $permissions null = vai trò mặc định; mảng = đúng các quyền đó, bất kể loại tài khoản */
    private function makeUser(string $kind, ?array $permissions = null, ?string $name = null, string $status = 'Active'): User
    {
        $user = User::create(array_merge([
            'name'            => $name ?? 'RG ' . $kind . ' ' . uniqid(),
            'email'           => 'rg_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'initials'        => 'RG',
            'status'          => $status,
        ], AccountFixture::for($kind)));

        if ($permissions !== null) {
            DB::table('user_access_roles')->where('user_id', $user->id)->delete();
            $role = AccessRole::create(['name' => 'RG role ' . uniqid()]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    private function makeReviewer(string $name = null, string $status = 'Active'): User
    {
        return $this->makeUser('student', ['results.grade', 'results.regrade'], $name ?? 'RG Reviewer ' . uniqid(), $status);
    }

    private function makeProblem(string $title = null): Problem
    {
        return Problem::create([
            'number' => 'RG-' . strtoupper(substr(uniqid(), -8)), 'title' => $title ?? 'RG problem ' . uniqid(),
            'difficulty' => 'Easy', 'description' => 'd', 'requirements' => 'r', 'practice_listed' => false,
            'creator_id' => $this->owner->id ?? null,
        ]);
    }

    private function makeExam(array $attrs = [], ?Problem $problem = null, float $points = 12.0): Assignment
    {
        $exam = Assignment::create(array_merge([
            'title' => 'RG exam ' . uniqid(), 'instructor_id' => $this->owner->id, 'published' => true,
            'exam_mode' => true, 'opens' => now()->subDay(), 'closes' => now()->addDay(), 'show_scores_after_submit' => true,
        ], $attrs));
        $exam->problems()->attach(($problem ?? $this->problem)->id, ['points' => $points, 'order_index' => 1]);

        return $exam;
    }

    private function makeSubmission(User $student, ?Assignment $exam = null, array $attrs = [], ?Problem $problem = null): Submission
    {
        return Submission::create(array_merge([
            'id' => (string) Str::uuid(), 'user_id' => $student->id, 'problem_id' => ($problem ?? $this->problem)->id,
            'source' => 'Assignments', 'context' => ($exam ?? $this->exam)->id, 'result' => 'Pending', 'score' => 0,
            'evaluated_score' => 5.0, 'submitted_at' => now()->subHour(), 'query_text' => 'SELECT 1',
        ], $attrs));
    }

    private function makeClass(User $student, ?User $instructor = null, ?Assignment $exam = null): ClassModel
    {
        $class = ClassModel::create([
            'id' => 'RG_' . strtoupper(uniqid()), 'course' => 'RG Course ' . uniqid(), 'term' => 'RG Term', 'status' => 'Active',
            'instructor_id' => $instructor?->id,
        ]);
        $class->students()->attach($student->id, ['role' => 'Member', 'joined_at' => now()]);
        if ($exam) {
            DB::table('assignment_classes')->insert(['id' => (string) Str::uuid(), 'assignment_id' => $exam->id, 'class_id' => $class->id]);
        }

        return $class;
    }

    private function assignRow(Submission $sub, User $reviewer, array $attrs = []): ExamRegrade
    {
        return ExamRegrade::create(array_merge([
            'activity_id' => $sub->context, 'student_id' => $sub->user_id, 'assigned_instructor_id' => $reviewer->id,
            'assigned_by_id' => $this->manager->id, 'note' => null, 'assigned_at' => now()->subMinutes(10), 'completed_at' => null,
        ], $attrs));
    }

    /** Đường dẫn phân công của một sinh viên trong ca thi. */
    private function path(User $student, ?Assignment $exam = null): string
    {
        return self::BASE . '/' . ($exam ?? $this->exam)->id . '/' . $student->id;
    }

    private function notificationsFor(User $user, string $titleStart): int
    {
        return DB::table('notifications')->where('user_id', $user->id)->where('title', 'like', $titleStart . '%')->count();
    }

    // ───────────────────────── truy cập ─────────────────────────

    public function test_guest_gets_401_and_assigners_only_pass(): void
    {
        $this->app['auth']->forgetGuards();
        foreach ([['getJson', '/reviewers'], ['getJson', '/mine'], ['putJson', '/x/y'], ['deleteJson', '/x/y']] as [$method, $path]) {
            $this->withHeaders([])->$method(self::BASE . $path)->assertStatus(401);
        }

        $grader = $this->makeReviewer();
        $student = $this->makeUser('student');
        $this->makeSubmission($student);
        Sanctum::actingAs($grader);
        $this->getJson(self::BASE . '/reviewers')->assertStatus(403);
        $this->putJson($this->path($student), ['reviewerId' => $grader->id])->assertStatus(403);
        $this->deleteJson($this->path($student))->assertStatus(403);
        $this->getJson(self::BASE . '/mine')->assertOk();

        Sanctum::actingAs($this->makeUser('student', ['results.view_all', 'exam.proctor']));
        $this->getJson(self::BASE . '/reviewers')->assertStatus(403);
        $this->getJson(self::BASE . '/mine')->assertStatus(403);
    }

    // ───────────────────────── reviewers ─────────────────────────

    public function test_reviewers_are_active_results_regrade_holders_sorted_and_without_class_practical_instructors(): void
    {
        $zed = $this->makeReviewer('RG Zed ' . uniqid());
        $amy = $this->makeReviewer('RG Amy ' . uniqid());
        $inactive = $this->makeReviewer(null, 'Inactive');
        $notGrader = $this->makeUser('student', ['problem.create']);
        $practical = $this->makeUser('student', ['results.regrade'], 'RG Practical ' . uniqid());
        $admin = $this->makeUser('admin');
        $direct = $this->makeUser('student', []);
        DB::table('user_permissions')->insert(['user_id' => $direct->id, 'permission' => 'results.regrade']);
        app(AccessControlService::class)->forget();
        $student = $this->makeUser('student');
        $this->makeClass($student, $practical, $this->exam);
        $this->makeSubmission($student);

        $all = $this->getJson(self::BASE . '/reviewers')->assertOk()->json();
        $ids = array_column($all, 'id');
        $this->assertSame(['id', 'name', 'email'], array_keys($all[0]));
        foreach ([$zed, $amy, $practical, $direct] as $u) {
            $this->assertContains($u->id, $ids);
        }
        foreach ([$inactive, $notGrader, $admin, $this->manager] as $u) {
            $this->assertNotContains($u->id, $ids);
        }
        $this->assertLessThan(array_search($zed->id, $ids, true), array_search($amy->id, $ids, true), 'sắp theo tên');
        $this->assertSame(count($ids), count(array_unique($ids)));

        foreach (['activity_id' => 'student_id', 'activityId' => 'studentId'] as $activityKey => $studentKey) {
            $filtered = array_column($this->getJson(self::BASE . "/reviewers?{$activityKey}={$this->exam->id}&{$studentKey}={$student->id}")->assertOk()->json(), 'id');
            $this->assertNotContains($practical->id, $filtered, $activityKey);
            $this->assertContains($amy->id, $filtered);
        }
        $unknown = array_column($this->getJson(self::BASE . '/reviewers?activity_id=' . Str::uuid() . '&student_id=' . $student->id)->assertOk()->json(), 'id');
        $this->assertContains($practical->id, $unknown, 'ca thi không tồn tại: không loại ai');
    }

    // ───────────────────────── assign ─────────────────────────

    public function test_assign_creates_the_assignment_notifies_the_reviewer_and_returns_the_row(): void
    {
        $student = $this->makeUser('student');
        $sub = $this->makeSubmission($student, null, ['evaluated_score' => 6.5, 'feedback' => 'Tạm được']);
        $reviewer = $this->makeReviewer();

        $res = $this->putJson($this->path($student), ['reviewerId' => $reviewer->id, 'note' => '  Kiểm tra lại  '])->assertOk();

        $this->assertTrue($res->json('ok'));
        $this->assertSame(array_merge(self::ROW_KEYS, ['ok']), array_keys($res->json()));
        $this->assertSame($reviewer->id, $res->json('reviewerId'));
        $this->assertSame($sub->id, $res->json('submissionId'));
        $this->assertSame('Kiểm tra lại', $res->json('note'));
        $stored = ExamRegrade::where('activity_id', $this->exam->id)->where('student_id', $student->id)->firstOrFail();
        $this->assertSame($reviewer->id, $stored->assigned_instructor_id);
        $this->assertSame($this->manager->id, $stored->assigned_by_id);
        $this->assertSame('Kiểm tra lại', $stored->note);
        $this->assertSame(6.5, $stored->previous_evaluated_score);
        $this->assertSame('Tạm được', $stored->previous_feedback);
        $this->assertNotNull($stored->assigned_at);
        $this->assertNull($stored->completed_at);

        $notice = DB::table('notifications')->where('user_id', $reviewer->id)->where('title', 'like', 'Phân công chấm phúc khảo:%')->first();
        $this->assertNotNull($notice);
        $this->assertSame('Kiểm tra lại', $notice->message);
        $this->assertSame('Review', $notice->type);
        $this->assertSame("/teacher/results/review/{$sub->id}", $notice->link);
        $this->assertStringContainsString($this->problem->title, $notice->title);
    }

    public function test_assign_without_a_note_uses_the_default_message_and_accepts_every_reviewer_key(): void
    {
        foreach (['reviewerId', 'reviewer_id', 'instructorId', 'instructor_id'] as $key) {
            $student = $this->makeUser('student');
            $this->makeSubmission($student);
            $reviewer = $this->makeReviewer();

            $this->putJson($this->path($student), [$key => $reviewer->id, 'note' => '   '])->assertOk()->assertJsonPath('reviewerId', $reviewer->id);

            $this->assertNull(ExamRegrade::where('student_id', $student->id)->first()->note, $key);
            $this->assertSame(
                'Giáo vụ khoa đã phân công một bài thi cần chấm phúc khảo cho bạn.',
                DB::table('notifications')->where('user_id', $reviewer->id)->value('message'),
                $key
            );
        }
    }

    public function test_reassigning_notifies_the_previous_reviewer_and_keeps_the_first_snapshot(): void
    {
        $student = $this->makeUser('student');
        $sub = $this->makeSubmission($student, null, ['evaluated_score' => 4.0, 'feedback' => 'Gốc']);
        $first = $this->makeReviewer();
        $second = $this->makeReviewer();
        $this->putJson($this->path($student), ['reviewerId' => $first->id])->assertOk();
        Submission::where('id', $sub->id)->update(['evaluated_score' => 9.0, 'feedback' => 'Đã đổi']);

        $this->putJson($this->path($student), ['reviewerId' => $second->id, 'note' => 'Chuyển người'])->assertOk()->assertJsonPath('reviewerId', $second->id);

        $stored = ExamRegrade::where('activity_id', $this->exam->id)->where('student_id', $student->id)->firstOrFail();
        $this->assertSame($second->id, $stored->assigned_instructor_id);
        $this->assertSame(4.0, $stored->previous_evaluated_score, 'ảnh chụp điểm trước phúc khảo được giữ từ lần phân công đầu');
        $this->assertSame('Gốc', $stored->previous_feedback);
        $this->assertSame(1, ExamRegrade::where('activity_id', $this->exam->id)->where('student_id', $student->id)->count());
        $this->assertSame(1, $this->notificationsFor($first, 'Thay đổi phân công chấm phúc khảo:'));
        $this->assertSame(0, $this->notificationsFor($second, 'Thay đổi phân công'));
        $this->assertSame(1, $this->notificationsFor($second, 'Phân công chấm phúc khảo:'));

        // Gán lại đúng người cũ: không báo "thay đổi".
        $this->putJson($this->path($student), ['reviewerId' => $second->id])->assertOk();
        $this->assertSame(0, $this->notificationsFor($second, 'Thay đổi phân công'));
        $this->assertSame(1, $this->notificationsFor($first, 'Thay đổi phân công chấm phúc khảo:'), 'người cũ không bị báo thêm');
    }

    public function test_assign_rejects_unknown_ids_non_exams_and_exams_that_cannot_be_regraded_yet(): void
    {
        $reviewer = $this->makeReviewer();
        $payload = ['reviewerId' => $reviewer->id];
        $student = $this->makeUser('student');

        $this->putJson(self::BASE . '/' . Str::uuid() . '/' . $student->id, $payload)->assertStatus(404);
        $this->putJson(self::BASE . '/' . $this->exam->id . '/' . Str::uuid(), $payload)->assertStatus(404);

        // Bài tập thường (không phải bài thi) không có phúc khảo.
        $plain = Assignment::create(['title' => 'RG plain ' . uniqid(), 'instructor_id' => $this->owner->id, 'published' => true, 'exam_mode' => false, 'show_scores_after_submit' => true]);
        $this->makeSubmission($student, $plain, [], $this->makeProblem());
        $this->putJson($this->path($student, $plain), $payload)->assertStatus(422)->assertJsonStructure(['message', 'detail']);
        $this->assertSame(0, ExamRegrade::where('activity_id', $plain->id)->count());

        // Sinh viên chưa nộp bài thi.
        $this->putJson($this->path($student), $payload)->assertStatus(422);

        // Chỉ có bài nộp từng câu thì chưa tính là đã nộp bài thi.
        $question = $this->makeSubmission($student);
        DB::table('workspace_submission_results')->insert([
            'submission_id' => $question->id, 'sections' => json_encode([['_questionSubmission' => ['part' => 'query']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->putJson($this->path($student), $payload)->assertStatus(422);
        $this->assertSame(0, ExamRegrade::where('activity_id', $this->exam->id)->where('student_id', $student->id)->count());

        // Chưa có điểm chính thức.
        $ungraded = $this->makeUser('student');
        $this->makeSubmission($ungraded, null, ['evaluated_score' => null]);
        $this->putJson($this->path($ungraded), $payload)->assertStatus(422);

    }

    public function test_assign_does_not_depend_on_whether_scores_are_shown_to_students(): void
    {
        // Điểm ẩn với sinh viên không còn chặn phúc khảo: chỉ cần bài đã có điểm chính thức.
        $problem = $this->makeProblem();
        $exam = $this->makeExam(['show_scores_after_submit' => false, 'examination_id' => 'HIDE-' . uniqid()], $problem);
        $student = $this->makeUser('student');
        $this->makeSubmission($student, $exam, [], $problem);
        $reviewer = $this->makeReviewer();

        $this->putJson($this->path($student, $exam), ['reviewerId' => $reviewer->id])->assertOk();
        $this->assertSame(1, ExamRegrade::where('activity_id', $exam->id)->count());
    }

    public function test_assign_rejects_completed_assignments_and_bad_reviewers(): void
    {
        $student = $this->makeUser('student');
        $sub = $this->makeSubmission($student);
        $practical = $this->makeUser('student', ['results.regrade']);
        $this->makeClass($student, $practical, $this->exam);
        $okReviewer = $this->makeReviewer();
        $notGrader = $this->makeUser('student', ['problem.create']);
        $inactive = $this->makeReviewer(null, 'Inactive');
        $admin = $this->makeUser('admin');

        foreach ([null, '', 'khong-co-user', $notGrader->id, $inactive->id, $admin->id] as $bad) {
            // Người chấm sai nằm trong dữ liệu gửi lên: 422 gắn vào reviewerId, không phải 404.
            $this->putJson($this->path($student), ['reviewerId' => $bad])->assertStatus(422)
                ->assertJsonPath('detail', 'Không tìm thấy giảng viên chấm phúc khảo.')
                ->assertJsonValidationErrors('reviewerId', 'errors');
        }
        $this->putJson($this->path($student), [])->assertStatus(422)->assertJsonValidationErrors('reviewerId', 'errors');
        $this->putJson($this->path($student), ['reviewerId' => $practical->id])->assertStatus(422)
            ->assertJsonPath('detail', 'Không được chọn giảng viên đứng lớp của sinh viên này để chấm phúc khảo.')
            ->assertJsonValidationErrors('reviewerId', 'errors');
        $this->assertSame(0, ExamRegrade::where('student_id', $student->id)->count(), 'không tạo phân công khi bị từ chối');
        $this->assertSame(0, DB::table('notifications')->where('user_id', $practical->id)->count());

        $this->assignRow($sub, $okReviewer, ['completed_at' => now()]);
        // Yêu cầu hợp lệ nhưng bài thi đã chấm phúc khảo xong: xung đột trạng thái (409), không phải lỗi dữ liệu (422).
        $this->putJson($this->path($student), ['reviewerId' => $this->makeReviewer()->id])->assertStatus(409)
            ->assertJsonPath('detail', 'Bài thi này đã được chấm phúc khảo xong, không thể phân công lại hoặc hủy.');
        $this->assertSame($okReviewer->id, ExamRegrade::where('student_id', $student->id)->first()->assigned_instructor_id);
    }

    // ───────────────────────── unassign ─────────────────────────

    public function test_unassign_removes_the_assignment_and_notifies_the_reviewer(): void
    {
        $student = $this->makeUser('student');
        $sub = $this->makeSubmission($student);
        $reviewer = $this->makeReviewer();
        $this->assignRow($sub, $reviewer);

        $res = $this->deleteJson($this->path($student))->assertOk();

        $this->assertSame(array_merge(self::ROW_KEYS, ['ok']), array_keys($res->json()));
        $this->assertTrue($res->json('ok'));
        $this->assertNull($res->json('reviewerId'));
        $this->assertSame(0, ExamRegrade::where('student_id', $student->id)->count());
        $notice = DB::table('notifications')->where('user_id', $reviewer->id)->where('title', 'like', 'Hủy phân công chấm phúc khảo:%')->first();
        $this->assertNotNull($notice);
        $this->assertSame('Info', $notice->type);
        $this->assertSame('/teacher/regrade', $notice->link);

        // Hủy lần nữa: vẫn OK, không báo thêm.
        $this->deleteJson($this->path($student))->assertOk();
        $this->assertSame(1, $this->notificationsFor($reviewer, 'Hủy phân công'));
    }

    public function test_unassign_rejects_unknown_non_exam_and_completed_assignments(): void
    {
        $student = $this->makeUser('student');
        $this->makeSubmission($student);
        $this->deleteJson(self::BASE . '/' . Str::uuid() . '/' . $student->id)->assertStatus(404);
        $this->deleteJson(self::BASE . '/' . $this->exam->id . '/' . Str::uuid())->assertStatus(404);

        $plain = Assignment::create(['title' => 'RG plain ' . uniqid(), 'instructor_id' => $this->owner->id, 'published' => true, 'exam_mode' => false]);
        $this->deleteJson($this->path($student, $plain))->assertStatus(422);

        $done = $this->makeUser('student');
        $doneSub = $this->makeSubmission($done);
        $reviewer = $this->makeReviewer();
        $this->assignRow($doneSub, $reviewer, ['completed_at' => now()]);
        $this->deleteJson($this->path($done))->assertStatus(409)
            ->assertJsonPath('detail', 'Bài thi này đã được chấm phúc khảo xong, không thể phân công lại hoặc hủy.');
        $this->assertSame(1, ExamRegrade::where('student_id', $done->id)->count());
        $this->assertSame(0, $this->notificationsFor($reviewer, 'Hủy phân công'));
    }

    // ───────────────────────── mine ─────────────────────────

    public function test_mine_lists_only_my_assignments_newest_first_in_the_row_shape(): void
    {
        $me = $this->makeReviewer('RG Me ' . uniqid());
        $other = $this->makeReviewer();
        $mine = [];
        foreach ([5, 1, 4, 2, 3] as $hoursAgo) {   // thứ tự phân công khác thứ tự khóa chính
            $sub = $this->makeSubmission($this->makeUser('student'));
            $this->assignRow($sub, $me, ['assigned_at' => now()->subHours($hoursAgo)]);
            $mine[$hoursAgo] = $sub->id;
        }
        $theirs = $this->makeSubmission($this->makeUser('student'));
        $this->assignRow($theirs, $other);

        Sanctum::actingAs($other);
        $this->assertSame([$theirs->id], array_column($this->getJson(self::BASE . '/mine')->assertOk()->json(), 'submissionId'));

        Sanctum::actingAs($me);
        $rows = $this->getJson(self::BASE . '/mine')->assertOk()->json();
        ksort($mine);   // 1 giờ trước (mới nhất) đứng đầu
        $this->assertSame(array_values($mine), array_column($rows, 'submissionId'));
        $this->assertSame(self::ROW_KEYS, array_keys($rows[0]));
        $this->assertSame($me->id, $rows[0]['reviewerId']);

        Sanctum::actingAs($this->makeReviewer());
        $this->assertSame([], $this->getJson(self::BASE . '/mine')->assertOk()->json());
    }
}
