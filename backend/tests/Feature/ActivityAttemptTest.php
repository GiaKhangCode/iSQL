<?php

namespace Tests\Feature;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả hành vi của /api/student/activity-attempts/* (lượt làm bài tập / ca thi của sinh viên).
 *
 * Các test ở đây KHÔNG cần SQL Server: nộp bài với bản nháp rỗng cho kết quả "Not Answered" mà không chạy SQL.
 * Chạy trên DB dev với DatabaseTransactions nên mọi dữ liệu được rollback.
 */
class ActivityAttemptTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
    }

    // ───────────── dựng dữ liệu ─────────────

    private function makeStudent(): User
    {
        return User::create([
            'name'            => 'AA ' . uniqid(),
            'email'           => 'aa_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for('student'),
            'status'          => 'Active',
            'initials'        => 'AA',
        ]);
    }

    /** Hoạt động đã công bố, đang mở, gán cho sinh viên qua assignment_students (trừ khi $assign = false). */
    private function makeActivity(?User $student, array $overrides = [], bool $assign = true): Assignment
    {
        $activity = Assignment::create(array_merge([
            'title'                    => 'AA activity ' . uniqid(),
            'published'                => true,
            'opens'                    => now()->subHour(),
            'closes'                   => now()->addHour(),
            'exam_mode'                => true,
            'exam_violation_limit'     => 2,
            'show_scores_after_submit' => true,
        ], $overrides));

        if ($student && $assign) {
            DB::table('assignment_students')->insert(['assignment_id' => $activity->id, 'student_id' => $student->id]);
        }

        return $activity;
    }

    private function makeProblem(string $title = 'AA problem', ?array $parts = ['SQL']): Problem
    {
        return Problem::create([
            'number'          => 'AA-' . strtoupper(uniqid()),
            'title'           => $title . ' ' . uniqid(),
            'difficulty'      => 'Easy',
            'description'     => 'AA description',
            'requirements'    => 'AA requirements',
            'workspace_parts' => $parts,
        ]);
    }

    private function addMember(Assignment $activity, Problem $problem, int $order = 0, float $points = 10, ?array $parts = null): AssignmentProblem
    {
        return AssignmentProblem::create([
            'assignment_id'   => $activity->id,
            'problem_id'      => $problem->id,
            'points'          => $points,
            'order_index'     => $order,
            'workspace_parts' => $parts,
        ]);
    }

    private function addSolution(Problem $problem, string $name = 'q1', int $order = 0): ProblemSqlSolution
    {
        // Chấm SQL chạy theo từng test case: bài không có test case nào thì không bao giờ qua.
        if (!\App\Models\TestCase::where('problem_id', $problem->id)->exists()) {
            \App\Models\TestCase::create(['problem_id' => $problem->id, 'is_hidden' => false, 'order_index' => 0]);
        }

        return ProblemSqlSolution::create([
            'problem_id'  => $problem->id,
            'name'        => $name,
            'query'       => 'SELECT 1 AS x',
            'enabled'     => true,
            'order_index' => $order,
        ]);
    }

    private function url(Assignment $activity, string $tail = ''): string
    {
        return "/api/student/activity-attempts/{$activity->id}" . $tail;
    }

    private function attemptOf(Assignment $activity, User $student): ?ActivityAttempt
    {
        return ActivityAttempt::where('assignment_id', $activity->id)->where('user_id', $student->id)->first();
    }

    // ───────────── truy cập ─────────────

    public function test_requires_authentication(): void
    {
        $activity = $this->makeActivity(null);

        $this->getJson($this->url($activity))->assertStatus(401);
        $this->postJson($this->url($activity, '/start'), [])->assertStatus(401);
        $this->postJson($this->url($activity, '/save'), [])->assertStatus(401);
    }

    public function test_unknown_activity_is_404(): void
    {
        Sanctum::actingAs($this->makeStudent());

        $this->getJson('/api/student/activity-attempts/does-not-exist')->assertStatus(404);
    }

    public function test_unpublished_activity_is_forbidden(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['published' => false]);
        Sanctum::actingAs($student);

        $this->getJson($this->url($activity))->assertStatus(403)->assertJsonPath('detail', 'This activity is not available.');
    }

    public function test_activity_not_yet_open_is_forbidden(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['opens' => now()->addMinutes(10)]);
        Sanctum::actingAs($student);

        $this->getJson($this->url($activity))->assertStatus(403)->assertJsonPath('detail', 'This activity has not opened yet.');
    }

    public function test_student_not_assigned_is_forbidden(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, assign: false);
        Sanctum::actingAs($student);

        $this->getJson($this->url($activity))->assertStatus(403)->assertJsonPath('detail', 'You are not assigned to this activity.');
    }

    public function test_assignment_through_class_enrollment_grants_access(): void
    {
        $student = $this->makeStudent();
        $other = $this->makeStudent();
        $activity = $this->makeActivity($student, assign: false);
        $class = ClassModel::create(['id' => 'aa-' . uniqid(), 'course' => 'AA', 'theory_class' => 'AA.1', 'term' => 'AA']);
        ClassEnrollment::create(['class_id' => $class->id, 'student_id' => $student->id, 'role' => 'student', 'joined_at' => now()]);
        DB::table('assignment_classes')->insert(['id' => 'aac-' . uniqid(), 'assignment_id' => $activity->id, 'class_id' => $class->id]);

        Sanctum::actingAs($student);
        $this->getJson($this->url($activity))->assertOk();

        Sanctum::actingAs($other);
        $this->getJson($this->url($activity))->assertStatus(403);
    }

    public function test_non_revoked_admission_grants_access_but_revoked_does_not(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, assign: false);
        $admission = ExamAdmission::create([
            'session_id' => $activity->id, 'student_id' => $student->id, 'username' => 'aa_' . uniqid(),
            'hashed_password' => Hash::make('x'), 'status' => 'issued', 'generation' => 1,
        ]);
        Sanctum::actingAs($student);

        $this->getJson($this->url($activity))->assertOk();

        $admission->update(['status' => 'revoked']);
        $this->getJson($this->url($activity))->assertStatus(403)->assertJsonPath('detail', 'You are not assigned to this activity.');
    }

    public function test_admission_required_blocks_until_claimed_session(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['admission_required' => true]);
        Sanctum::actingAs($student);

        $this->getJson($this->url($activity))->assertStatus(403);
        $this->postJson($this->url($activity, '/start'), [])->assertStatus(403);
    }

    public function test_locked_student_cannot_work_but_can_still_read_state_and_submit(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        ExamAccessState::create(['session_id' => $activity->id, 'student_id' => $student->id, 'locked' => true]);

        // Ghi/bắt đầu/vi phạm bị chặn khi đang khóa...
        $this->postJson($this->url($activity, '/start'), [])->assertStatus(403)
            ->assertJsonPath('detail', 'Tài khoản ca thi đang tạm khóa. Vui lòng báo giám thị phòng thi.');
        $this->postJson($this->url($activity, '/save'), [])->assertStatus(403);
        $this->postJson($this->url($activity, '/violation'), [])->assertStatus(403);
        $this->postJson($this->url($activity, '/event'), [])->assertStatus(403);

        // ...nhưng đọc trạng thái (frontend hỏi dò xem giám thị đã mở khóa chưa) và nộp bài vẫn được.
        $this->getJson($this->url($activity))->assertOk()->assertJsonPath('status', 'active');
        $this->postJson($this->url($activity, '/submit'), [])->assertOk()->assertJsonPath('status', 'submitted');
    }

    public function test_unlocked_access_state_does_not_block(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        ExamAccessState::create(['session_id' => $activity->id, 'student_id' => $student->id, 'locked' => false]);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/start'), [])->assertOk();
    }

    public function test_unknown_action_is_404(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/explode'), [])->assertStatus(404);
    }

    public function test_closed_window_blocks_show_start_and_save_but_allows_submit_and_timeout(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['closes' => now()->subMinute()]);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/start'), [])->assertStatus(403)
            ->assertJsonPath('detail', 'The submission window for this activity has closed.');
        $this->postJson($this->url($activity, '/save'), [])->assertStatus(403);
        $this->postJson($this->url($activity, '/event'), [])->assertStatus(403);

        // show cho phép hoạt động đã đóng: chưa có lượt làm thì trả "closed", không tạo lượt làm.
        $this->getJson($this->url($activity))->assertOk()->assertJsonPath('status', 'closed');
        $this->assertNull($this->attemptOf($activity, $student));

        $this->postJson($this->url($activity, '/timeout'), [])->assertOk()->assertJsonPath('status', 'submitted');
    }

    // ───────────── show / start ─────────────

    public function test_show_in_exam_mode_before_start_is_not_started_and_creates_nothing(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);

        $this->getJson($this->url($activity))
            ->assertOk()
            ->assertJsonPath('activityId', (string) $activity->id)
            ->assertJsonPath('examMode', true)
            ->assertJsonPath('examViolationLimit', 2)
            ->assertJsonPath('status', 'not_started')
            ->assertJsonPath('problemIds', [(string) $problem->id])
            ->assertJsonPath('violationCount', 0)
            ->assertJsonPath('departureCount', 0)
            ->assertJsonPath('startedAt', null)
            ->assertJsonPath('submittedAt', null)
            ->assertJsonPath('finishReason', null)
            ->assertJsonPath('showScoresAfterSubmit', true)
            ->assertJsonPath('maxSubmissions', null);

        $this->assertNull($this->attemptOf($activity, $student));
    }

    public function test_show_response_shape_is_stable(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['max_submissions' => 3, 'exam_violation_limit' => 0]);
        Sanctum::actingAs($student);

        $response = $this->getJson($this->url($activity))->assertOk();
        $json = $response->json();

        $this->assertEqualsCanonicalizing([
            'activityId', 'examMode', 'examViolationLimit', 'problemIds', 'closesAt', 'serverNow', 'showScoresAfterSubmit',
            'maxSubmissions', 'submissionCounts', 'status', 'violationCount', 'departureCount', 'drafts', 'startedAt',
            'submittedAt', 'finishReason',
        ], array_keys($json));
        $this->assertSame(3, $json['maxSubmissions']);
        // Giới hạn vi phạm luôn >= 1, kể cả khi cấu hình 0.
        $this->assertSame(1, $json['examViolationLimit']);
        // Hợp đồng frontend: các map phải là object JSON ({}), không phải mảng rỗng ([]).
        // json() đã giải mã thành mảng nên phải kiểm tra trên chuỗi thô.
        $raw = $response->getContent();
        $this->assertStringContainsString('"submissionCounts":{}', $raw);
        $this->assertStringContainsString('"drafts":{}', $raw);
    }

    public function test_show_non_exam_activity_creates_active_attempt(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_mode' => false]);
        Sanctum::actingAs($student);

        $this->getJson($this->url($activity))->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('examMode', false);

        $attempt = $this->attemptOf($activity, $student);
        $this->assertNotNull($attempt);
        $this->assertSame('active', $attempt->status);
    }

    public function test_start_requires_exam_mode(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_mode' => false]);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/start'), [])->assertStatus(400)
            ->assertJsonPath('detail', 'Activity is not in exam mode.');
    }

    public function test_start_creates_active_attempt_and_is_idempotent(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);

        $first = $this->postJson($this->url($activity, '/start'), [])->assertOk()->assertJsonPath('status', 'active')->json();
        $this->assertNotNull($first['startedAt']);

        $second = $this->postJson($this->url($activity, '/start'), [])->assertOk()->assertJsonPath('status', 'active')->json();
        $this->assertSame($first['startedAt'], $second['startedAt']);
        $this->assertSame(1, ActivityAttempt::where('assignment_id', $activity->id)->where('user_id', $student->id)->count());
    }

    public function test_start_after_submit_returns_submitted_without_reactivating(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/submit'), [])->assertOk();

        $this->postJson($this->url($activity, '/start'), [])->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertSame('submitted', $this->attemptOf($activity, $student)->status);
    }

    public function test_attempts_are_per_student(): void
    {
        $a = $this->makeStudent();
        $b = $this->makeStudent();
        $activity = $this->makeActivity($a);
        DB::table('assignment_students')->insert(['assignment_id' => $activity->id, 'student_id' => $b->id]);

        Sanctum::actingAs($a);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        Sanctum::actingAs($b);
        $this->getJson($this->url($activity))->assertOk()->assertJsonPath('status', 'not_started');
    }

    public function test_in_progress_status_is_reported_as_active(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        ActivityAttempt::create([
            'assignment_id' => $activity->id, 'user_id' => $student->id, 'status' => 'in_progress',
            'drafts' => [], 'violations' => [], 'started_at' => now(),
        ]);
        Sanctum::actingAs($student);

        $this->getJson($this->url($activity))->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_show_past_deadline_finishes_active_attempt_with_timeout(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['closes' => now()->addHour()]);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $activity->update(['closes' => now()->subSecond()]);

        $this->getJson($this->url($activity))
            ->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('finishReason', 'timeout');
        $this->assertSame(1, Submission::where('user_id', $student->id)->where('context', $activity->id)->count());
    }

    public function test_start_past_deadline_is_blocked_by_the_closed_window(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $activity->update(['closes' => now()->subSecond()]);

        $this->postJson($this->url($activity, '/start'), [])->assertStatus(403);
    }

    public function test_activity_without_deadline_never_times_out(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['closes' => null]);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/start'), [])->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('closesAt', null);
        $this->postJson($this->url($activity, '/save'), [])->assertOk()->assertJsonPath('status', 'active');
    }

    // ───────────── save / nháp ─────────────

    public function test_save_before_start_in_exam_mode_conflicts(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/save'), [])->assertStatus(409)
            ->assertJsonPath('detail', 'Start the exam before continuing.');
        $this->assertNull($this->attemptOf($activity, $student));
    }

    public function test_save_snapshot_stores_draft_for_member_problem_only(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $member = $this->makeProblem('member');
        $outsider = $this->makeProblem('outsider');
        $this->addMember($activity, $member);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/save'), [
            'problemId' => $member->id, 'ddl' => 'CREATE TABLE a(x int)', 'dml' => 'INSERT', 'query' => 'SELECT 1', 'revision' => 5,
        ])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'problemId' => $outsider->id, 'query' => 'SELECT 2', 'revision' => 1,
        ])->assertOk();

        $drafts = $this->attemptOf($activity, $student)->drafts;
        $this->assertArrayHasKey($member->id, $drafts);
        $this->assertArrayNotHasKey($outsider->id, $drafts);
        $this->assertSame('CREATE TABLE a(x int)', $drafts[$member->id]['ddl']);
        $this->assertSame('SELECT 1', $drafts[$member->id]['query']);
        $this->assertSame('', $drafts[$member->id]['trigger']);
        $this->assertSame('SQL Server', $drafts[$member->id]['database']);
        $this->assertSame(5, $drafts[$member->id]['revision']);
    }

    public function test_save_snapshot_accepts_snake_case_problem_id_and_ignores_empty_payloads(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        // Không có trường kịch bản nào: không tạo nháp.
        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id])->assertOk();
        $this->assertSame([], $this->attemptOf($activity, $student)->drafts);

        $this->postJson($this->url($activity, '/save'), ['problem_id' => $problem->id, 'query' => 'SELECT 9'])->assertOk();
        $this->assertSame('SELECT 9', $this->attemptOf($activity, $student)->drafts[$problem->id]['query']);
    }

    public function test_save_with_older_revision_does_not_overwrite_newer_draft(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id, 'query' => 'NEW', 'revision' => 10])->assertOk();
        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id, 'query' => 'OLD', 'revision' => 9])->assertOk();
        $this->assertSame('NEW', $this->attemptOf($activity, $student)->drafts[$problem->id]['query']);

        // Cùng revision thì ghi đè (>=).
        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id, 'query' => 'SAME', 'revision' => 10])->assertOk();
        $this->assertSame('SAME', $this->attemptOf($activity, $student)->drafts[$problem->id]['query']);
    }

    public function test_save_snapshot_keeps_unsent_fields_from_previous_draft(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id, 'ddl' => 'D1', 'query' => 'Q1', 'revision' => 1])->assertOk();
        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id, 'query' => 'Q2', 'revision' => 2])->assertOk();

        $draft = $this->attemptOf($activity, $student)->drafts[$problem->id];
        $this->assertSame('D1', $draft['ddl']);
        $this->assertSame('Q2', $draft['query']);
    }

    public function test_save_merges_per_question_drafts_without_losing_other_questions(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['query' => 'RAW', 'questions' => ['SQL' => ['a' => 'SELECT A', 'b' => 'SELECT B']]]],
        ])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => ['b' => 'SELECT B2'], 'DDL' => ['d' => 'CREATE']]]],
        ])->assertOk();

        $draft = $this->attemptOf($activity, $student)->drafts[$problem->id];
        $this->assertSame('RAW', $draft['query']);
        $this->assertSame('SELECT A', $draft['questions']['SQL']['a']);
        $this->assertSame('SELECT B2', $draft['questions']['SQL']['b']);
        $this->assertSame('CREATE', $draft['questions']['DDL']['d']);
    }

    public function test_save_ignores_non_array_draft_entries_and_empty_drafts(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/save'), ['drafts' => [$problem->id => 'oops']])->assertOk();
        $this->postJson($this->url($activity, '/save'), ['drafts' => []])->assertOk();

        $this->assertSame([], $this->attemptOf($activity, $student)->drafts);
    }

    public function test_save_exposes_drafts_through_show(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id, 'query' => 'SELECT 7', 'revision' => 1])->assertOk();

        $this->getJson($this->url($activity))->assertOk()->assertJsonPath("drafts.{$problem->id}.query", 'SELECT 7');
    }

    // ───────────── vi phạm / sự kiện ─────────────

    public function test_violation_is_recorded_as_counted_with_default_reason(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 5]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/violation'), [])->assertOk()
            ->assertJsonPath('violationCount', 1)
            ->assertJsonPath('departureCount', 1)
            ->assertJsonPath('status', 'active');

        $event = $this->attemptOf($activity, $student)->violations[0];
        $this->assertSame('focus-lost', $event['reason']);
        $this->assertTrue($event['counted']);
        $this->assertArrayNotHasKey('eventId', $event);
        $this->assertArrayNotHasKey('kind', $event);
    }

    public function test_violation_stores_kind_event_id_duration_and_truncated_detail(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 5]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/violation'), [
            'eventId' => str_repeat('e', 100), 'kind' => 'external_paste', 'durationMs' => '1500', 'detail' => str_repeat('d', 300),
        ])->assertOk();

        $event = $this->attemptOf($activity, $student)->violations[0];
        $this->assertSame('external_paste', $event['reason']);
        $this->assertSame('external_paste', $event['kind']);
        $this->assertSame(64, strlen($event['eventId']));
        $this->assertSame(1500, $event['durationMs']);
        $this->assertSame(240, strlen($event['detail']));
    }

    public function test_violation_accepts_snake_case_aliases_and_reason(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 5]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/violation'), ['event_id' => 'ev-1', 'duration_ms' => 20, 'reason' => 'tab-hidden'])->assertOk();

        $event = $this->attemptOf($activity, $student)->violations[0];
        $this->assertSame('ev-1', $event['eventId']);
        $this->assertSame(20, $event['durationMs']);
        $this->assertSame('tab-hidden', $event['reason']);
    }

    public function test_violation_ignores_non_numeric_duration(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 5]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/violation'), ['durationMs' => 'abc'])->assertOk();

        $this->assertArrayNotHasKey('durationMs', $this->attemptOf($activity, $student)->violations[0]);
    }

    public function test_same_event_id_is_recorded_only_once(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 5]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/violation'), ['eventId' => 'dup'])->assertOk()->assertJsonPath('violationCount', 1);
        $this->postJson($this->url($activity, '/violation'), ['eventId' => 'dup'])->assertOk()->assertJsonPath('violationCount', 1);
        $this->postJson($this->url($activity, '/violation'), ['eventId' => 'other'])->assertOk()->assertJsonPath('violationCount', 2);

        $this->assertCount(2, $this->attemptOf($activity, $student)->violations);
    }

    public function test_event_action_is_never_counted(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 1]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/event'), ['kind' => 'focus_lost'])->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('violationCount', 0);

        $event = $this->attemptOf($activity, $student)->violations[0];
        $this->assertFalse($event['counted']);
        $this->assertSame('focus_lost', $event['reason']);

        // Không có kind/reason: lý do mặc định của sự kiện không đếm là activity-event.
        $this->postJson($this->url($activity, '/event'), [])->assertOk();
        $this->assertSame('activity-event', $this->attemptOf($activity, $student)->violations[1]['reason']);
    }

    public function test_focus_returned_and_clipboard_panel_violations_are_not_counted(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 1]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        foreach (['focus_returned', 'clipboard_panel'] as $kind) {
            $this->postJson($this->url($activity, '/violation'), ['kind' => $kind])->assertOk()
                ->assertJsonPath('violationCount', 0)
                ->assertJsonPath('status', 'active');
        }
    }

    public function test_departure_count_includes_uncounted_departure_events(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 9]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/event'), ['reason' => 'tab-hidden'])->assertOk();     // rời đi, không đếm
        $this->postJson($this->url($activity, '/event'), ['reason' => 'something-else'])->assertOk(); // không phải rời đi
        $this->postJson($this->url($activity, '/violation'), ['kind' => 'external_paste'])->assertOk(); // đếm

        $this->postJson($this->url($activity, '/event'), ['reason' => 'page-leave'])->assertOk()
            ->assertJsonPath('violationCount', 1)
            ->assertJsonPath('departureCount', 3);
    }

    public function test_reaching_the_violation_limit_submits_the_attempt(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 2]);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/violation'), ['eventId' => 'a'])->assertOk()->assertJsonPath('status', 'active');
        $this->postJson($this->url($activity, '/violation'), ['eventId' => 'b'])->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('finishReason', 'violations')
            ->assertJsonPath('violationCount', 2);

        $this->assertSame(1, Submission::where('user_id', $student->id)->where('context', $activity->id)->count());
    }

    public function test_violation_limit_does_not_apply_outside_exam_mode(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_mode' => false, 'exam_violation_limit' => 1]);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/violation'), ['eventId' => 'a'])->assertOk()->assertJsonPath('status', 'active');
        $this->postJson($this->url($activity, '/violation'), ['eventId' => 'b'])->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('violationCount', 2);
    }

    public function test_violation_response_does_not_include_submissions(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 1]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $json = $this->postJson($this->url($activity, '/violation'), [])->assertOk()->json();

        $this->assertArrayNotHasKey('submissions', $json);
    }

    public function test_actions_after_submit_return_the_submitted_attempt_untouched(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 9]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/submit'), [])->assertOk();

        foreach (['save', 'violation', 'event', 'submit', 'timeout'] as $action) {
            $json = $this->postJson($this->url($activity, "/{$action}"), ['query' => 'x', 'kind' => 'external_paste'])->assertOk()->json();
            $this->assertSame('submitted', $json['status']);
            // Trả thẳng bản đã nộp: không có khóa submissions kể cả với submit/timeout lặp lại.
            $this->assertArrayNotHasKey('submissions', $json);
        }
        $this->assertSame([], $this->attemptOf($activity, $student)->violations);
    }

    // ───────────── submit / timeout ─────────────

    public function test_submit_without_attempt_in_non_exam_activity_creates_and_submits(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_mode' => false]);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/submit'), [])->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('finishReason', 'submitted')
            ->assertJsonPath('submissions', []);
    }

    public function test_submit_in_exam_mode_without_start_creates_attempt_and_submits(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);

        $this->postJson($this->url($activity, '/submit'), [])->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertNotNull($this->attemptOf($activity, $student));
    }

    public function test_timeout_action_records_timeout_reason(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/timeout'), [])->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('finishReason', 'timeout');
    }

    public function test_submit_after_deadline_is_recorded_as_timeout(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $activity->update(['closes' => now()->subSecond()]);

        $this->postJson($this->url($activity, '/submit'), [])->assertOk()->assertJsonPath('finishReason', 'timeout');
    }

    public function test_save_after_deadline_is_blocked_before_it_can_finish_the_attempt(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $activity->update(['closes' => now()->subSecond()]);

        $this->postJson($this->url($activity, '/save'), [])->assertStatus(403);
        $this->assertSame('active', $this->attemptOf($activity, $student)->status);
    }

    public function test_submit_stores_drafts_sent_in_the_request(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/submit'), [
            'drafts' => [$problem->id => ['query' => 'SELECT final']],
        ])->assertOk()->assertJsonPath("drafts.{$problem->id}.query", 'SELECT final');

        $submission = Submission::where('user_id', $student->id)->where('problem_id', $problem->id)->first();
        $this->assertSame('SELECT final', json_decode($submission->query_text, true)['query']);
    }

    public function test_submit_grades_every_member_problem_in_order_with_not_answered_when_blank(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $p1 = $this->makeProblem('first');
        $p2 = $this->makeProblem('second');
        $this->addSolution($p1);
        $this->addSolution($p2, 'q1');
        $this->addSolution($p2, 'q2', 1);
        $this->addMember($activity, $p2, 1, 4);
        $this->addMember($activity, $p1, 0, 6);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $json = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json();

        $this->assertSame([(string) $p1->id, (string) $p2->id], array_column($json['submissions'], 'problemId'));
        $this->assertEquals([6, 4], array_column($json['submissions'], 'maxScore'));
        foreach ($json['submissions'] as $row) {
            $this->assertSame('Wrong Answer', $row['status']);
            $this->assertEquals(0, $row['score']);
            $this->assertTrue($row['scoreVisible']);
            $this->assertNotEmpty($row['submissionId']);
        }

        $sub = Submission::find($json['submissions'][1]['submissionId']);
        $this->assertSame($student->id, $sub->user_id);
        $this->assertSame($p2->id, $sub->problem_id);
        $this->assertSame('Assignments', $sub->source);
        $this->assertSame($activity->id, $sub->context);
        $this->assertSame('SQL Server', $sub->database_type);
        $this->assertNotEmpty($sub->schema_name);
        $this->assertNotNull($sub->submitted_at);

        $sections = $sub->workspaceResult->sections;
        $this->assertCount(1, $sections);
        $this->assertSame('SQL', $sections[0]['part']);
        $this->assertSame('Wrong Answer', $sections[0]['status']);
        $this->assertSame('0/2 câu đúng.', $sections[0]['message']);
        $this->assertSame(0, $sections[0]['passed']);
        $this->assertSame(2, $sections[0]['total']);
        $this->assertSame(['Not Answered', 'Not Answered'], array_column($sections[0]['questions'], 'status'));
        $this->assertSame('No query submitted for this question.', $sections[0]['questions'][0]['message']);
    }

    public function test_submit_hides_scores_when_not_shown_after_submit(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['show_scores_after_submit' => false]);
        $problem = $this->makeProblem();
        $this->addSolution($problem);
        $this->addMember($activity, $problem, 0, 7);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $this->assertSame('Submitted', $row['status']);
        $this->assertNull($row['score']);
        $this->assertFalse($row['scoreVisible']);
        $this->assertEquals(7, $row['maxScore']);
    }

    public function test_submit_uses_member_workspace_parts_then_problem_parts_then_sql(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $withMember = $this->makeProblem('m', ['SQL']);
        $withProblem = $this->makeProblem('p', ['SQL', 'DDL']);
        $fallback = $this->makeProblem('f', null);
        $this->addMember($activity, $withMember, 0, 10, ['DDL']);
        $this->addMember($activity, $withProblem, 1);
        $this->addMember($activity, $fallback, 2);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $json = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json();

        $partsOf = fn (int $i) => array_column(Submission::find($json['submissions'][$i]['submissionId'])->workspaceResult->sections, 'part');
        $this->assertSame(['DDL'], $partsOf(0));
        $this->assertSame(['SQL', 'DDL'], $partsOf(1));
        $this->assertSame(['SQL'], $partsOf(2));
    }

    public function test_submit_skips_member_whose_problem_was_deleted(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        // Bỏ ràng buộc khoá ngoại tạm thời để dựng "bài đã bị xóa" (rollback cùng transaction của test).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('problems')->where('id', $problem->id)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->postJson($this->url($activity, '/submit'), [])->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('submissions', []);
    }

    public function test_per_question_drafts_and_marker_scripts_are_read_when_grading(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $s1 = $this->addSolution($problem, 'q1', 0);
        $s2 = $this->addSolution($problem, 'q2', 1);
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        // Câu 1 trả lời qua questions.SQL, câu 2 chỉ có trong query gộp bằng dấu "-- solution:<id>"
        // nhưng đoạn đó rỗng => vẫn là "Not Answered" mà không chạm SQL Server.
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['query' => "-- solution:{$s2->id}\n   \n", 'questions' => ['SQL' => [$s1->id => '']]]],
        ])->assertOk();

        $json = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json();
        $questions = Submission::find($json['submissions'][0]['submissionId'])->workspaceResult->sections[0]['questions'];

        $this->assertSame([$s1->id, $s2->id], array_column($questions, 'id'));
        $this->assertSame(['Not Answered', 'Not Answered'], array_column($questions, 'status'));
    }

    public function test_submission_counts_reflect_graded_submissions_per_problem(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $p1 = $this->makeProblem();
        $p2 = $this->makeProblem();
        $this->addMember($activity, $p1, 0);
        $this->addMember($activity, $p2, 1);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/submit'), [])->assertOk();

        $json = $this->getJson($this->url($activity))->assertOk()->json();

        $this->assertSame(1, $json['submissionCounts'][$p1->id]);
        $this->assertSame(1, $json['submissionCounts'][$p2->id]);
        $this->assertSame(['submitted'], [$json['status']]);
    }

    public function test_submission_counts_ignore_other_users_other_contexts_and_other_sources(): void
    {
        $student = $this->makeStudent();
        $other = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        $base = ['problem_id' => $problem->id, 'result' => 'Accepted', 'score' => 1, 'query_text' => '{}', 'submitted_at' => now()];

        Submission::create($base + ['user_id' => $student->id, 'source' => 'ExamSession', 'context' => $activity->id]);
        Submission::create($base + ['user_id' => $student->id, 'source' => 'Assignments', 'context' => $activity->id]);
        Submission::create($base + ['user_id' => $student->id, 'source' => 'Practice', 'context' => $activity->id]);
        Submission::create($base + ['user_id' => $student->id, 'source' => 'Assignments', 'context' => 'another-activity']);
        Submission::create($base + ['user_id' => $other->id, 'source' => 'Assignments', 'context' => $activity->id]);

        Sanctum::actingAs($student);
        $json = $this->getJson($this->url($activity))->assertOk()->json();

        $this->assertSame(2, $json['submissionCounts'][$problem->id]);
    }

    public function test_source_defaults_to_assignments_and_can_be_exam_session(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), ['source' => 'ExamSession'])->assertOk();

        $json = $this->postJson($this->url($activity, '/submit'), ['source' => 'ExamSession'])->assertOk()->json();

        $this->assertSame('ExamSession', Submission::find($json['submissions'][0]['submissionId'])->source);
    }

    // ───────────── chấm thật trên SQL Server (cần kết nối sqlsrv) ─────────────

    /** Bài có một câu SQL đáp án "SELECT 1 AS x" và một test case rỗng. */
    private function gradedSqlActivity(User $student, array $activityOverrides = []): array
    {
        $activity = $this->makeActivity($student, $activityOverrides);
        $problem = $this->makeProblem();
        $solution = $this->addSolution($problem, 'q1');
        $this->addMember($activity, $problem, 0, 10);

        return [$activity, $problem, $solution];
    }

    public function test_sql_part_accepts_matching_query_and_scores_full_marks(): void
    {
        $student = $this->makeStudent();
        [$activity, $problem, $solution] = $this->gradedSqlActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => [$solution->id => 'SELECT 1 AS x']]]],
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $this->assertSame('Accepted', $row['status']);
        $this->assertEquals(10, $row['score']);
        $sub = Submission::find($row['submissionId']);
        $this->assertSame('Accepted', $sub->result);
        $section = $sub->workspaceResult->sections[0];
        $this->assertSame('Accepted', $section['status']);
        $this->assertSame('1/1 câu đúng.', $section['message']);
        $this->assertSame('Accepted', $section['questions'][0]['status']);
        $this->assertSame('Passed all checks.', $section['questions'][0]['message']);
        $this->assertSame('SELECT 1 AS x', $section['questions'][0]['query']);
    }

    public function test_sql_part_marks_a_different_result_as_wrong_answer_with_zero_score(): void
    {
        $student = $this->makeStudent();
        [$activity, $problem, $solution] = $this->gradedSqlActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => [$solution->id => 'SELECT 2 AS x']]]],
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $this->assertSame('Wrong Answer', $row['status']);
        $this->assertEquals(0, $row['score']);
        $this->assertSame('Wrong Answer', Submission::find($row['submissionId'])->workspaceResult->sections[0]['questions'][0]['status']);
    }

    public function test_sql_part_scores_partially_when_only_some_questions_pass(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $s1 = $this->addSolution($problem, 'q1', 0);
        $s2 = $this->addSolution($problem, 'q2', 1);
        $this->addMember($activity, $problem, 0, 10);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => [$s1->id => 'SELECT 1 AS x', $s2->id => 'SELECT 5 AS x']]]],
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $this->assertSame('Partial', $row['status']);
        $this->assertEquals(5, $row['score']);
        $section = Submission::find($row['submissionId'])->workspaceResult->sections[0];
        $this->assertSame('Partial', $section['status']);
        $this->assertSame('1/2 câu đúng.', $section['message']);
        $this->assertSame(['Accepted', 'Wrong Answer'], array_column($section['questions'], 'status'));
    }

    public function test_sql_part_reports_runtime_error_for_invalid_sql(): void
    {
        $student = $this->makeStudent();
        [$activity, $problem, $solution] = $this->gradedSqlActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => [$solution->id => 'SELEC nothing FROM nowhere']]]],
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $this->assertSame('Wrong Answer', $row['status']);
        $this->assertSame('Runtime Error', Submission::find($row['submissionId'])->workspaceResult->sections[0]['questions'][0]['status']);
    }

    public function test_sql_part_reads_marker_scripts_from_combined_query(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $s1 = $this->addSolution($problem, 'q1', 0);
        $s2 = $this->addSolution($problem, 'q2', 1);
        $this->addMember($activity, $problem, 0, 10);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'problemId' => $problem->id,
            'query'     => "-- solution:{$s1->id}\nSELECT 1 AS x\n-- solution:{$s2->id}\nSELECT 5 AS x",
            'revision'  => 1,
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $questions = Submission::find($row['submissionId'])->workspaceResult->sections[0]['questions'];
        $this->assertSame(['Accepted', 'Wrong Answer'], array_column($questions, 'status'));
    }

    public function test_unmarked_combined_query_is_attributed_to_the_first_question(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addSolution($problem, 'q1', 0);
        $this->addSolution($problem, 'q2', 1);
        $this->addMember($activity, $problem, 0, 10);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id, 'query' => 'SELECT 1 AS x', 'revision' => 1])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $questions = Submission::find($row['submissionId'])->workspaceResult->sections[0]['questions'];
        $this->assertSame(['Accepted', 'Not Answered'], array_column($questions, 'status'));
    }

    public function test_hidden_scores_still_store_the_real_score_on_the_submission(): void
    {
        $student = $this->makeStudent();
        [$activity, $problem, $solution] = $this->gradedSqlActivity($student, ['show_scores_after_submit' => false]);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => [$solution->id => 'SELECT 1 AS x']]]],
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $this->assertSame('Submitted', $row['status']);
        $this->assertNull($row['score']);
        $this->assertEquals(10, Submission::find($row['submissionId'])->score);
    }

    public function test_ddl_part_grades_a_matching_create_table_against_the_reference(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem('ddl', ['DDL']);
        $case = \App\Models\TestCase::create(['problem_id' => $problem->id, 'is_hidden' => false, 'order_index' => 0]);
        \App\Models\TestCaseScript::create([
            'test_case_id'  => $case->id,
            'table_name'    => 'aa_t',
            'create_script' => 'CREATE TABLE aa_t (id INT PRIMARY KEY, name NVARCHAR(50));',
            'insert_script' => '',
            'is_expected'   => true,
        ]);
        $this->addMember($activity, $problem, 0, 10, ['DDL']);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'problemId' => $problem->id,
            'ddl'       => 'CREATE TABLE aa_t (id INT PRIMARY KEY, name NVARCHAR(50));',
            'revision'  => 1,
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $section = Submission::find($row['submissionId'])->workspaceResult->sections[0];
        $this->assertSame('DDL', $section['part']);
        $this->assertSame('__base_ddl__', $section['questions'][0]['id']);
        $this->assertSame('Accepted', $section['questions'][0]['status']);
        $this->assertEquals(10, $row['score']);
    }

    public function test_ddl_part_with_a_different_schema_is_wrong_answer(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem('ddl', ['DDL']);
        $case = \App\Models\TestCase::create(['problem_id' => $problem->id, 'is_hidden' => false, 'order_index' => 0]);
        \App\Models\TestCaseScript::create([
            'test_case_id'  => $case->id,
            'table_name'    => 'aa_t',
            'create_script' => 'CREATE TABLE aa_t (id INT PRIMARY KEY, name NVARCHAR(50));',
            'insert_script' => '',
            'is_expected'   => true,
        ]);
        $this->addMember($activity, $problem, 0, 10, ['DDL']);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'problemId' => $problem->id,
            'ddl'       => 'CREATE TABLE aa_other (x INT);',
            'revision'  => 1,
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $question = Submission::find($row['submissionId'])->workspaceResult->sections[0]['questions'][0];
        $this->assertSame('Wrong Answer', $question['status']);
        $this->assertSame('DDL result differs from expected state.', $question['message']);
        $this->assertEquals(0, $row['score']);
    }

    public function test_grading_stores_exactly_one_submission_per_problem(): void
    {
        $student = $this->makeStudent();
        [$activity, $problem, $solution] = $this->gradedSqlActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => [$solution->id => 'SELECT 1 AS x']]]],
        ])->assertOk();

        $json = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json();

        // Chạy từng câu để chấm không được tự lưu thêm bài nộp lẻ (sẽ làm sai số lần nộp).
        $this->assertSame(1, Submission::where('user_id', $student->id)->count());
        $this->assertSame(1, $json['submissionCounts'][$problem->id]);
    }

    public function test_member_with_zero_points_is_graded_out_of_ten(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $solution = $this->addSolution($problem);
        $this->addMember($activity, $problem, 0, 0);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => [$solution->id => 'SELECT 1 AS x']]]],
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $this->assertEquals(10, Submission::find($row['submissionId'])->score);
        $this->assertEquals(0, $row['maxScore']);
    }

    public function test_save_snapshot_keeps_per_question_answers_saved_earlier(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addMember($activity, $problem);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['questions' => ['SQL' => ['a' => 'SELECT A']]]],
        ])->assertOk();

        $this->postJson($this->url($activity, '/save'), ['problemId' => $problem->id, 'query' => 'RAW', 'revision' => 1])->assertOk();

        $draft = $this->attemptOf($activity, $student)->drafts[$problem->id];
        $this->assertSame('RAW', $draft['query']);
        $this->assertSame('SELECT A', $draft['questions']['SQL']['a']);
    }

    private function dmlProblem(Assignment $activity): Problem
    {
        $problem = $this->makeProblem('dml', ['DML']);
        $case = \App\Models\TestCase::create(['problem_id' => $problem->id, 'is_hidden' => false, 'order_index' => 0]);
        \App\Models\TestCaseScript::create([
            'test_case_id'  => $case->id,
            'table_name'    => 'aa_d',
            'create_script' => 'CREATE TABLE aa_d (id INT PRIMARY KEY, name NVARCHAR(50));',
            'insert_script' => "INSERT INTO aa_d (id, name) VALUES (1, N'a');",
            'is_expected'   => true,
        ]);
        $this->addMember($activity, $problem, 0, 10, ['DML']);

        return $problem;
    }

    public function test_dml_part_accepts_the_same_data_changes_as_the_reference(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->dmlProblem($activity);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'problemId' => $problem->id, 'dml' => "INSERT INTO aa_d (id, name) VALUES (1, N'a');", 'revision' => 1,
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $section = Submission::find($row['submissionId'])->workspaceResult->sections[0];
        $this->assertSame('DML', $section['part']);
        $this->assertSame('__base_dml__', $section['questions'][0]['id']);
        $this->assertSame('Accepted', $section['questions'][0]['status']);
        $this->assertEquals(10, $row['score']);
    }

    public function test_dml_part_with_different_data_changes_is_wrong_answer(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->dmlProblem($activity);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'problemId' => $problem->id, 'dml' => "INSERT INTO aa_d (id, name) VALUES (2, N'b');", 'revision' => 1,
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $question = Submission::find($row['submissionId'])->workspaceResult->sections[0]['questions'][0];
        $this->assertSame('Wrong Answer', $question['status']);
        $this->assertSame('DML result differs from expected state.', $question['message']);
        $this->assertEquals(0, $row['score']);
    }

    public function test_legacy_violation_events_without_counted_flag_are_counted(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student, ['exam_violation_limit' => 9]);
        ActivityAttempt::create([
            'assignment_id' => $activity->id, 'user_id' => $student->id, 'status' => 'active', 'started_at' => now(),
            'drafts'        => [],
            'violations'    => [['reason' => 'old-event'], ['reason' => 'page-leave', 'counted' => false], 'garbage'],
        ]);
        Sanctum::actingAs($student);

        // Sự kiện cũ thiếu cờ counted tính là vi phạm; "page-leave" không đếm nhưng vẫn là một lần rời đi.
        $this->getJson($this->url($activity))->assertOk()
            ->assertJsonPath('violationCount', 1)
            ->assertJsonPath('departureCount', 2);
    }

    public function test_per_question_draft_wins_over_the_combined_query_markers(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $solution = $this->addSolution($problem, 'q1');
        $this->addMember($activity, $problem, 0, 10);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => [
                'query'     => "-- solution:{$solution->id}\nSELECT 1 AS x",
                'questions' => ['SQL' => [$solution->id => 'SELECT 2 AS x']],
            ]],
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $question = Submission::find($row['submissionId'])->workspaceResult->sections[0]['questions'][0];
        $this->assertSame('Wrong Answer', $question['status']);
        $this->assertSame('SELECT 2 AS x', $question['query']);
    }

    public function test_stage_part_reports_runtime_error_for_invalid_script(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->dmlProblem($activity);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'problemId' => $problem->id, 'dml' => 'INSERT INTO table_that_does_not_exist VALUES (1)', 'revision' => 1,
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $question = Submission::find($row['submissionId'])->workspaceResult->sections[0]['questions'][0];
        $this->assertSame('Runtime Error', $question['status']);
        // Thông báo là lỗi thực thi của SQL Server, không phải "khác trạng thái mong đợi".
        $this->assertStringNotContainsString('differs from expected state', $question['message']);
        $this->assertNotSame('Passed all checks.', $question['message']);
        $this->assertEquals(0, $row['score']);
    }

    public function test_unknown_workspace_part_is_ignored_when_grading(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $this->addSolution($problem);
        $this->addMember($activity, $problem, 0, 10, ['SQL', 'WHATEVER']);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $sections = Submission::find($row['submissionId'])->workspaceResult->sections;
        $this->assertSame(['SQL'], array_column($sections, 'part'));
    }

    public function test_submission_keeps_the_submitted_drafts_as_query_text(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        $problem = $this->makeProblem();
        $solution = $this->addSolution($problem);
        $this->addMember($activity, $problem, 0, 10);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();
        $this->postJson($this->url($activity, '/save'), [
            'drafts' => [$problem->id => ['ddl' => 'D', 'dml' => 'M', 'trigger' => 'T', 'questions' => ['SQL' => [$solution->id => 'SELECT 1 AS x']]]],
        ])->assertOk();

        $row = $this->postJson($this->url($activity, '/submit'), [])->assertOk()->json('submissions.0');

        $payload = json_decode(Submission::find($row['submissionId'])->query_text, true);
        $this->assertSame('D', $payload['ddl']);
        $this->assertSame('M', $payload['dml']);
        $this->assertSame('T', $payload['trigger']);
        $this->assertSame('SELECT 1 AS x', $payload['questions']['SQL'][$solution->id]);
    }

    public function test_activity_without_problems_submits_with_empty_results(): void
    {
        $student = $this->makeStudent();
        $activity = $this->makeActivity($student);
        Sanctum::actingAs($student);
        $this->postJson($this->url($activity, '/start'), [])->assertOk();

        $this->postJson($this->url($activity, '/submit'), [])->assertOk()
            ->assertJsonPath('submissions', [])
            ->assertJsonPath('problemIds', []);
        $this->assertNotNull($this->attemptOf($activity, $student)->submitted_at);
    }
}
