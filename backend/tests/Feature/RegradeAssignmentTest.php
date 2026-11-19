<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\Examination;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
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
 * Test đặc tả cho /api/regrade-assignments/* (RegradeAssignmentController), viết TRƯỚC khi tách logic sang service.
 *
 * Giáo vụ (examination.manage) phân công người chấm phúc khảo; người chấm là tài khoản Active giữ results.grade và
 * không phải giảng viên thực hành đứng lớp của chính sinh viên đó. DB dev có dữ liệu sẵn nên mọi ca lọc theo
 * session_id của dữ liệu mẫu do test tạo, và so sánh theo id.
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
        'problem', 'score', 'maxScore', 'status', 'submittedAt', 'appealRequested', 'appealReviewed', 'appealStatus',
        'appealReason', 'appealCreatedAt', 'appealResolvedAt', 'reviewerId', 'reviewerName', 'assignedInstructorId',
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
            $role = AccessRole::create(['name' => 'RG role ' . uniqid(), 'is_super' => false]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    private function makeReviewer(string $name = null, string $status = 'Active'): User
    {
        return $this->makeUser('student', ['results.grade'], $name ?? 'RG Reviewer ' . uniqid(), $status);
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
            'exam_mode' => true, 'opens' => now()->subDay(), 'closes' => now()->addDay(),
        ], $attrs));
        $exam->problems()->attach(($problem ?? $this->problem)->id, ['points' => $points, 'order_index' => 1]);

        return $exam;
    }

    private function makeSubmission(User $student, ?Assignment $exam = null, array $attrs = [], ?Problem $problem = null): Submission
    {
        return Submission::create(array_merge([
            'id' => (string) Str::uuid(), 'user_id' => $student->id, 'problem_id' => ($problem ?? $this->problem)->id,
            'source' => 'Assignments', 'context' => ($exam ?? $this->exam)->id, 'result' => 'Pending', 'score' => 0,
            'submitted_at' => now()->subHour(), 'query_text' => 'SELECT 1',
        ], $attrs));
    }

    private function makeClass(User $student, ?User $practical = null, ?Assignment $exam = null, bool $viaPivot = false): ClassModel
    {
        $class = ClassModel::create([
            'id' => 'RG_' . strtoupper(uniqid()), 'course' => 'RG Course ' . uniqid(), 'term' => 'RG Term', 'status' => 'Active',
            'instructor_id' => $viaPivot ? null : $practical?->id, 'practice_instructor_id' => $viaPivot ? null : $practical?->id,
        ]);
        if ($practical && $viaPivot) {
            DB::table('class_instructors')->insert([
                'class_id' => $class->id, 'instructor_id' => $practical->id, 'assignment_type' => 'practical', 'assigned_at' => now(),
            ]);
        }
        $class->students()->attach($student->id, ['role' => 'Member', 'joined_at' => now()]);
        if ($exam) {
            DB::table('assignment_classes')->insert(['id' => (string) Str::uuid(), 'assignment_id' => $exam->id, 'class_id' => $class->id]);
        }

        return $class;
    }

    private function makeAppeal(Submission $sub, array $attrs = []): SubmissionAppeal
    {
        return SubmissionAppeal::create(array_merge([
            'submission_id' => $sub->id, 'student_id' => $sub->user_id, 'reason' => 'Chấm sai', 'status' => 'pending',
            'created_at' => now()->subMinutes(30),
        ], $attrs));
    }

    private function assignRow(Submission $sub, User $reviewer, array $attrs = []): SubmissionRegradeAssignment
    {
        return SubmissionRegradeAssignment::create(array_merge([
            'submission_id' => $sub->id, 'assigned_instructor_id' => $reviewer->id, 'assigned_by_id' => $this->manager->id,
            'note' => null, 'assigned_at' => now()->subMinutes(10), 'completed_at' => null,
        ], $attrs));
    }

    private function candidates(array $query = []): array
    {
        return $this->getJson(self::BASE . '/candidates?' . http_build_query($query))->assertOk()->json();
    }

    /** @return array<string, array> dòng ứng viên theo submissionId */
    private function rowsById(array $query): array
    {
        return collect($this->candidates($query)['rows'])->keyBy('submissionId')->all();
    }

    private function notificationsFor(User $user, string $titleStart): int
    {
        return DB::table('notifications')->where('user_id', $user->id)->where('title', 'like', $titleStart . '%')->count();
    }

    // ───────────────────────── truy cập ─────────────────────────

    public function test_guest_gets_401_and_assigners_only_pass(): void
    {
        $this->app['auth']->forgetGuards();
        foreach ([['getJson', '/candidates'], ['getJson', '/reviewers'], ['getJson', '/mine'], ['putJson', '/x'], ['deleteJson', '/x']] as [$method, $path]) {
            $this->withHeaders([])->$method(self::BASE . $path)->assertStatus(401);
        }

        $grader = $this->makeReviewer();
        $sub = $this->makeSubmission($this->makeUser('student'));
        Sanctum::actingAs($grader);
        $this->getJson(self::BASE . '/candidates')->assertStatus(403);
        $this->getJson(self::BASE . '/reviewers')->assertStatus(403);
        $this->putJson(self::BASE . "/{$sub->id}", ['reviewerId' => $grader->id])->assertStatus(403);
        $this->deleteJson(self::BASE . "/{$sub->id}")->assertStatus(403);
        $this->getJson(self::BASE . '/mine')->assertOk();

        Sanctum::actingAs($this->makeUser('student', ['results.view_all', 'exam.proctor']));
        $this->getJson(self::BASE . '/candidates')->assertStatus(403);
        $this->getJson(self::BASE . '/mine')->assertStatus(403);
    }

    // ───────────────────────── candidates ─────────────────────────

    public function test_candidate_row_has_the_frontend_shape_and_values(): void
    {
        $student = $this->makeUser('student', null, 'RG Học Viên ' . uniqid());
        $practical = $this->makeUser('instructor');
        $viaPivot = $this->makeUser('instructor');
        $class = $this->makeClass($student, $practical, $this->exam);
        DB::table('class_instructors')->insert([
            'class_id' => $class->id, 'instructor_id' => $viaPivot->id, 'assignment_type' => 'practical', 'assigned_at' => now(),
        ]);
        $sub = $this->makeSubmission($student, null, ['score' => 80, 'evaluated_score' => 7.5, 'result' => 'Accepted']);
        $appeal = $this->makeAppeal($sub, ['status' => 'resolved', 'resolved_at' => now()->subMinutes(5)]);
        $reviewer = $this->makeReviewer('RG Người Chấm ' . uniqid());
        $this->assignRow($sub, $reviewer, ['note' => 'Xem lại câu 2', 'completed_at' => now()->subMinute()]);

        $data = $this->candidates(['session_id' => $this->exam->id]);
        $this->assertSame(['rows', 'truncated', 'activities'], array_keys($data));
        $this->assertFalse($data['truncated']);
        $this->assertCount(1, $data['rows']);

        $row = $data['rows'][0];
        $this->assertSame(self::ROW_KEYS, array_keys($row));
        $this->assertSame($sub->id, $row['submissionId']);
        $this->assertSame($this->exam->id, $row['sessionId']);
        $this->assertSame($this->exam->id, $row['activityId']);
        $this->assertSame($this->exam->title, $row['sessionTitle']);
        $this->assertNull($row['examinationId']);
        $this->assertTrue($row['isExamSession']);
        $this->assertSame($student->id, $row['studentId']);
        $this->assertSame($student->name, $row['studentName']);
        $this->assertSame($student->name, $row['student']);
        $this->assertSame($student->email, $row['studentEmail']);
        $this->assertSame($class->course, $row['className']);
        $this->assertSame($this->problem->id, $row['problemId']);
        $this->assertSame($this->problem->title, $row['problemTitle']);
        $this->assertSame(7.5, $row['score'], 'điểm đã chấm lại ưu tiên hơn điểm tự động');
        $this->assertEquals(12.0, $row['maxScore'], 'điểm tối đa lấy từ điểm của câu trong đề');
        $this->assertSame('Accepted', $row['status']);
        $this->assertTrue($row['appealRequested']);
        $this->assertTrue($row['appealReviewed']);
        $this->assertSame('resolved', $row['appealStatus']);
        $this->assertSame('Chấm sai', $row['appealReason']);
        $this->assertNotNull($row['appealCreatedAt']);
        $this->assertNotNull($row['appealResolvedAt']);
        $this->assertSame($reviewer->id, $row['reviewerId']);
        $this->assertSame($reviewer->name, $row['reviewerName']);
        $this->assertSame($reviewer->id, $row['assignedInstructorId']);
        $this->assertSame($reviewer->name, $row['assignedInstructor']);
        $this->assertSame($reviewer->email, $row['assignedInstructorEmail']);
        $this->assertNotNull($row['assignedAt']);
        $this->assertNotNull($row['completedAt']);
        $this->assertSame('Xem lại câu 2', $row['assignmentNote']);
        $this->assertSame('Xem lại câu 2', $row['note']);
        $this->assertEqualsCanonicalizing([$practical->id, $viaPivot->id], $row['excludedReviewerIds'], 'giảng viên thực hành đứng lớp (cột cũ và bảng nối)');
        $this->assertSame(1, $appeal->count());
    }

    public function test_candidate_defaults_for_an_unscored_row_without_appeal_assignment_or_class(): void
    {
        $student = $this->makeUser('student');
        $plainExam = Assignment::create([
            'title' => 'RG no points ' . uniqid(), 'instructor_id' => $this->owner->id, 'published' => true, 'exam_mode' => true,
            'opens' => now()->subDay(), 'closes' => now()->addDay(),
        ]);
        $sub = $this->makeSubmission($student, $plainExam, ['result' => '', 'score' => 33, 'evaluated_score' => null]);

        $row = $this->candidates(['session_id' => $plainExam->id])['rows'][0];
        $this->assertEquals(33.0, $row['score']);
        $this->assertEquals(10.0, $row['maxScore'], 'ca thi không có điểm cho câu: mặc định 10');
        $this->assertSame('Submitted', $row['status'], 'chưa có kết quả');
        $this->assertFalse($row['appealRequested']);
        $this->assertFalse($row['appealReviewed']);
        foreach (['appealStatus', 'appealReason', 'appealCreatedAt', 'appealResolvedAt', 'reviewerId', 'reviewerName', 'assignedAt', 'completedAt', 'assignmentNote', 'className'] as $key) {
            $this->assertNull($row[$key], $key);
        }
        $this->assertSame([], $row['excludedReviewerIds']);
        $this->assertSame($sub->id, $row['submissionId']);
    }

    public function test_only_the_class_of_the_activity_counts_for_class_name_and_exclusions(): void
    {
        $student = $this->makeUser('student');
        $inActivity = $this->makeUser('instructor');
        $elsewhere = $this->makeUser('instructor');
        $this->makeClass($student, $inActivity, $this->exam);
        $this->makeClass($student, $elsewhere);          // lớp khác của sinh viên, không gắn với ca thi
        $this->makeSubmission($student);

        $row = $this->candidates(['session_id' => $this->exam->id])['rows'][0];
        $this->assertSame([$inActivity->id], $row['excludedReviewerIds']);
    }

    public function test_without_an_activity_class_every_class_of_the_student_is_used(): void
    {
        $student = $this->makeUser('student');
        $a = $this->makeUser('instructor');
        $b = $this->makeUser('instructor');
        $this->makeClass($student, $a);
        $this->makeClass($student, $b, null, true);
        $this->makeSubmission($student);

        $row = $this->candidates(['session_id' => $this->exam->id])['rows'][0];
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $row['excludedReviewerIds']);
        $this->assertNotNull($row['className']);
    }

    public function test_duplicates_collapse_to_the_latest_unless_they_have_an_appeal_or_assignment(): void
    {
        $student = $this->makeUser('student');
        $reviewer = $this->makeReviewer();
        $old = $this->makeSubmission($student, null, ['submitted_at' => now()->subHours(5)]);
        $mid = $this->makeSubmission($student, null, ['submitted_at' => now()->subHours(4)]);
        $new = $this->makeSubmission($student, null, ['submitted_at' => now()->subHours(3)]);
        $this->makeAppeal($old);
        $this->assignRow($mid, $reviewer);

        $ids = array_keys($this->rowsById(['session_id' => $this->exam->id]));
        $this->assertEqualsCanonicalizing([$old->id, $mid->id, $new->id], $ids, 'bản có đơn phúc khảo/đã phân công được giữ lại');

        $other = $this->makeUser('student');
        $x1 = $this->makeSubmission($other, null, ['submitted_at' => now()->subHours(5)]);
        $x2 = $this->makeSubmission($other, null, ['submitted_at' => now()->subHours(2)]);
        $ids = array_keys($this->rowsById(['session_id' => $this->exam->id]));
        $this->assertContains($x2->id, $ids);
        $this->assertNotContains($x1->id, $ids, 'chỉ giữ lần nộp mới nhất');
    }

    public function test_rows_are_ordered_appeal_first_then_assigned_then_newest(): void
    {
        $reviewer = $this->makeReviewer();
        $plainNew = $this->makeSubmission($this->makeUser('student'), null, ['submitted_at' => now()->subMinutes(1)]);
        $plainOld = $this->makeSubmission($this->makeUser('student'), null, ['submitted_at' => now()->subHours(9)]);
        $assigned = $this->makeSubmission($this->makeUser('student'), null, ['submitted_at' => now()->subHours(8)]);
        $appealed = $this->makeSubmission($this->makeUser('student'), null, ['submitted_at' => now()->subHours(20)]);
        $this->assignRow($assigned, $reviewer);
        $this->makeAppeal($appealed);

        $order = array_column($this->candidates(['session_id' => $this->exam->id])['rows'], 'submissionId');
        $this->assertSame([$appealed->id, $assigned->id, $plainNew->id, $plainOld->id], $order);
    }

    public function test_filters_appeal_only_and_keyword(): void
    {
        $alice = $this->makeUser('student', null, 'Alice ' . uniqid());
        $bob = $this->makeUser('student', null, 'Bob ' . uniqid());
        $bobProblem = $this->makeProblem('Truy van dac biet ' . uniqid());
        $this->exam->problems()->attach($bobProblem->id, ['points' => 5, 'order_index' => 2]);
        $aliceSub = $this->makeSubmission($alice);
        $bobSub = $this->makeSubmission($bob, null, [], $bobProblem);
        $this->makeAppeal($aliceSub);
        $session = ['session_id' => $this->exam->id];

        $this->assertEqualsCanonicalizing([$aliceSub->id, $bobSub->id], array_keys($this->rowsById($session)));
        $this->assertSame([$aliceSub->id], array_keys($this->rowsById($session + ['appeal_only' => 'true'])));
        $this->assertSame([$aliceSub->id], array_keys($this->rowsById($session + ['appeal_only' => '1'])));
        $this->assertEqualsCanonicalizing([$aliceSub->id, $bobSub->id], array_keys($this->rowsById($session + ['appeal_only' => 'false'])));

        $this->assertSame([$aliceSub->id], array_keys($this->rowsById($session + ['q' => strtoupper($alice->name)])), 'không phân biệt hoa thường');
        $this->assertSame([$bobSub->id], array_keys($this->rowsById($session + ['q' => $bob->email])));
        $this->assertSame([$bobSub->id], array_keys($this->rowsById($session + ['q' => 'dac biet'])), 'tìm theo tên bài');
        $this->assertEqualsCanonicalizing([$aliceSub->id, $bobSub->id], array_keys($this->rowsById($session + ['q' => $this->exam->title])), 'tìm theo tên ca thi khớp cả hai');
        $this->assertSame([], $this->candidates($session + ['q' => 'khong-ton-tai-' . uniqid()])['rows']);
    }

    public function test_examination_filter_and_aliases_and_session_wins(): void
    {
        $examination = Examination::create(['id' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'title' => 'RG kỳ thi', 'exam_date' => '2026-12-01']);
        $inside = $this->makeExam(['examination_id' => $examination->id, 'exam_mode' => false, 'title' => 'RG ca ' . uniqid()]);
        $student = $this->makeUser('student');
        $subInside = $this->makeSubmission($student, $inside, ['source' => 'ExamSession']);
        $subStandalone = $this->makeSubmission($student, $this->exam);

        $byExamination = $this->candidates(['examination_id' => $examination->id]);
        $this->assertSame([$subInside->id], array_column($byExamination['rows'], 'submissionId'));
        $row = $byExamination['rows'][0];
        $this->assertSame($examination->id, $row['examinationId']);
        $this->assertTrue($row['isExamSession']);
        $this->assertSame($inside->title, $row['sessionTitle']);
        $this->assertSame([$subInside->id], array_column($this->candidates(['examinationId' => $examination->id])['rows'], 'submissionId'), 'bí danh camelCase');
        $this->assertSame([$subStandalone->id], array_column($this->candidates(['sessionId' => $this->exam->id])['rows'], 'submissionId'));
        $this->assertSame([$subStandalone->id], array_column($this->candidates(['session_id' => $this->exam->id, 'examination_id' => $examination->id])['rows'], 'submissionId'), 'session_id thắng');

        $unknown = $this->candidates(['examination_id' => (string) Str::uuid()]);
        $this->assertSame([], $unknown['rows']);
        $this->assertFalse($unknown['truncated']);
        $this->assertArrayHasKey('activities', $unknown);
    }

    public function test_unfiltered_list_covers_exam_activities_only_and_skips_practice_and_question_submissions(): void
    {
        $student = $this->makeUser('student');
        $examSub = $this->makeSubmission($student);
        $plain = Assignment::create(['title' => 'RG plain ' . uniqid(), 'instructor_id' => $this->owner->id, 'published' => true, 'exam_mode' => false]);
        $plain->problems()->attach($this->problem->id, ['points' => 10, 'order_index' => 1]);
        $plainSub = $this->makeSubmission($student, $plain);
        $practiceSub = $this->makeSubmission($student, null, ['source' => 'Practice', 'context' => 'Practice']);
        $wrongSource = $this->makeSubmission($student, null, ['source' => 'Practice']);
        $questionSub = $this->makeSubmission($this->makeUser('student'));
        DB::table('workspace_submission_results')->insert([
            'submission_id' => $questionSub->id,
            'sections' => json_encode([['_questionSubmission' => ['part' => 'ddl']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ids = array_keys($this->rowsById([]));
        $this->assertContains($examSub->id, $ids);
        foreach ([$plainSub, $practiceSub, $wrongSource, $questionSub] as $skipped) {
            $this->assertNotContains($skipped->id, $ids, "{$skipped->source}/{$skipped->context}");
        }
    }

    public function test_standalone_exam_activities_are_listed_for_the_picker(): void
    {
        $examination = Examination::create(['id' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'title' => 'RG', 'exam_date' => '2026-12-02']);
        $inExamination = $this->makeExam(['examination_id' => $examination->id]);
        $plain = Assignment::create(['title' => 'RG plain ' . uniqid(), 'instructor_id' => $this->owner->id, 'published' => true, 'exam_mode' => false]);

        $activities = collect($this->candidates(['session_id' => 'khong-co'])['activities'])->keyBy('id');
        $this->assertSame(['id', 'title', 'examMode'], array_keys($activities[$this->exam->id]));
        $this->assertSame($this->exam->title, $activities[$this->exam->id]['title']);
        $this->assertTrue($activities[$this->exam->id]['examMode']);
        $this->assertFalse($activities->has($inExamination->id), 'ca thi thuộc kỳ thi không nằm trong ô chọn độc lập');
        $this->assertFalse($activities->has($plain->id), 'bài tập không phải ca thi');
    }

    public function test_the_list_is_cut_only_beyond_500_rows(): void
    {
        $users = [];
        $problems = [];
        for ($i = 0; $i < 25; $i++) {
            $users[] = $this->makeUser('student');
        }
        for ($i = 0; $i < 20; $i++) {
            $problems[] = $this->makeProblem();
            $this->exam->problems()->attach($problems[$i]->id, ['points' => 1, 'order_index' => $i + 2]);
        }
        $count = 0;
        foreach ($users as $user) {
            foreach ($problems as $problem) {
                $this->makeSubmission($user, null, ['submitted_at' => now()->subSeconds($count++)], $problem);
            }
        }
        $this->assertSame(500, $count);

        $exact = $this->candidates(['session_id' => $this->exam->id]);
        $this->assertFalse($exact['truncated'], 'đúng 500 dòng thì chưa bị cắt');
        $this->assertCount(500, $exact['rows']);

        $this->makeSubmission($this->makeUser('student'), null, [], $problems[0]);
        $over = $this->candidates(['session_id' => $this->exam->id]);
        $this->assertTrue($over['truncated']);
        $this->assertCount(500, $over['rows'], '501 dòng bị cắt còn 500');
    }

    // ───────────────────────── reviewers ─────────────────────────

    public function test_reviewers_are_active_results_grade_holders_sorted_and_without_class_practical_instructors(): void
    {
        $zed = $this->makeReviewer('RG Zed ' . uniqid());
        $amy = $this->makeReviewer('RG Amy ' . uniqid());
        $inactive = $this->makeReviewer(null, 'Inactive');
        $notGrader = $this->makeUser('student', ['problem.create']);
        $practical = $this->makeUser('student', ['results.grade'], 'RG Practical ' . uniqid());
        $admin = $this->makeUser('admin');
        $direct = $this->makeUser('student', []);
        DB::table('user_permissions')->insert(['user_id' => $direct->id, 'permission' => 'results.grade']);
        app(AccessControlService::class)->forget();
        $student = $this->makeUser('student');
        $this->makeClass($student, $practical, $this->exam);
        $sub = $this->makeSubmission($student);

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

        foreach (['submission_id', 'submissionId'] as $key) {
            $filtered = array_column($this->getJson(self::BASE . "/reviewers?{$key}={$sub->id}")->assertOk()->json(), 'id');
            $this->assertNotContains($practical->id, $filtered, $key);
            $this->assertContains($amy->id, $filtered);
        }
        $unknown = array_column($this->getJson(self::BASE . '/reviewers?submission_id=' . Str::uuid())->assertOk()->json(), 'id');
        $this->assertContains($practical->id, $unknown, 'bài nộp không tồn tại: không loại ai');
    }

    // ───────────────────────── assign ─────────────────────────

    public function test_assign_creates_the_assignment_notifies_the_reviewer_and_returns_the_row(): void
    {
        $student = $this->makeUser('student');
        $sub = $this->makeSubmission($student, null, ['evaluated_score' => 6.5, 'feedback' => 'Tạm được']);
        $reviewer = $this->makeReviewer();

        $res = $this->putJson(self::BASE . "/{$sub->id}", ['reviewerId' => $reviewer->id, 'note' => '  Kiểm tra lại  '])->assertOk();

        $this->assertTrue($res->json('ok'));
        $this->assertSame(array_merge(self::ROW_KEYS, ['ok']), array_keys($res->json()));
        $this->assertSame($reviewer->id, $res->json('reviewerId'));
        $this->assertSame('Kiểm tra lại', $res->json('note'));
        $stored = SubmissionRegradeAssignment::findOrFail($sub->id);
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
        $student = $this->makeUser('student');
        foreach (['reviewerId', 'reviewer_id', 'instructorId', 'instructor_id'] as $key) {
            $sub = $this->makeSubmission($student, null, [], $this->makeProblem());
            $reviewer = $this->makeReviewer();

            $this->putJson(self::BASE . "/{$sub->id}", [$key => $reviewer->id, 'note' => '   '])->assertOk()->assertJsonPath('reviewerId', $reviewer->id);

            $this->assertNull(SubmissionRegradeAssignment::find($sub->id)->note, $key);
            $this->assertSame(
                'Giáo vụ khoa đã phân công một bài nộp cần chấm phúc khảo cho bạn.',
                DB::table('notifications')->where('user_id', $reviewer->id)->value('message'),
                $key
            );
        }
    }

    public function test_reassigning_notifies_the_previous_reviewer_and_keeps_the_first_snapshot(): void
    {
        $sub = $this->makeSubmission($this->makeUser('student'), null, ['evaluated_score' => 4.0, 'feedback' => 'Gốc']);
        $first = $this->makeReviewer();
        $second = $this->makeReviewer();
        $this->putJson(self::BASE . "/{$sub->id}", ['reviewerId' => $first->id])->assertOk();
        Submission::where('id', $sub->id)->update(['evaluated_score' => 9.0, 'feedback' => 'Đã đổi']);

        $this->putJson(self::BASE . "/{$sub->id}", ['reviewerId' => $second->id, 'note' => 'Chuyển người'])->assertOk()->assertJsonPath('reviewerId', $second->id);

        $stored = SubmissionRegradeAssignment::findOrFail($sub->id);
        $this->assertSame($second->id, $stored->assigned_instructor_id);
        $this->assertSame(4.0, $stored->previous_evaluated_score, 'ảnh chụp điểm trước phúc khảo được giữ từ lần phân công đầu');
        $this->assertSame('Gốc', $stored->previous_feedback);
        $this->assertSame(1, SubmissionRegradeAssignment::where('submission_id', $sub->id)->count());
        $this->assertSame(1, $this->notificationsFor($first, 'Thay đổi phân công chấm phúc khảo:'));
        $this->assertSame(0, $this->notificationsFor($second, 'Thay đổi phân công'));
        $this->assertSame(1, $this->notificationsFor($second, 'Phân công chấm phúc khảo:'));

        // Gán lại đúng người cũ: không báo "thay đổi".
        $this->putJson(self::BASE . "/{$sub->id}", ['reviewerId' => $second->id])->assertOk();
        $this->assertSame(0, $this->notificationsFor($second, 'Thay đổi phân công'));
        $this->assertSame(1, $this->notificationsFor($first, 'Thay đổi phân công chấm phúc khảo:'), 'người cũ không bị báo thêm');
    }

    public function test_assign_rejects_unknown_submissions_and_non_regradable_ones(): void
    {
        $reviewer = $this->makeReviewer();
        $payload = ['reviewerId' => $reviewer->id];
        $student = $this->makeUser('student');

        $this->putJson(self::BASE . '/' . Str::uuid(), $payload)->assertStatus(404);

        $practice = $this->makeSubmission($student, null, ['source' => 'Practice', 'context' => 'Practice']);
        $this->putJson(self::BASE . "/{$practice->id}", $payload)->assertStatus(422)->assertJsonStructure(['message', 'detail']);

        $plain = Assignment::create(['title' => 'RG plain ' . uniqid(), 'instructor_id' => $this->owner->id, 'published' => true, 'exam_mode' => false]);
        $plainSub = $this->makeSubmission($student, $plain, [], $this->makeProblem());
        $this->putJson(self::BASE . "/{$plainSub->id}", $payload)->assertStatus(422);
        $this->assertNull(SubmissionRegradeAssignment::find($plainSub->id));

        // Có đơn phúc khảo thì bài tập thường cũng được phân công.
        $this->makeAppeal($plainSub);
        $this->putJson(self::BASE . "/{$plainSub->id}", $payload)->assertOk();

        // Nguồn ExamSession luôn đủ điều kiện, kể cả khi không tìm thấy ca thi.
        $orphan = $this->makeSubmission($student, null, ['source' => 'ExamSession', 'context' => (string) Str::uuid()], $this->makeProblem());
        $this->putJson(self::BASE . "/{$orphan->id}", $payload)->assertOk();

        $question = $this->makeSubmission($student, null, [], $this->makeProblem());
        DB::table('workspace_submission_results')->insert([
            'submission_id' => $question->id, 'sections' => json_encode([['_questionSubmission' => ['part' => 'query']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->putJson(self::BASE . "/{$question->id}", $payload)->assertStatus(422);
        $this->assertNull(SubmissionRegradeAssignment::find($question->id));
    }

    public function test_assign_rejects_completed_assignments_and_bad_reviewers(): void
    {
        $student = $this->makeUser('student');
        $sub = $this->makeSubmission($student);
        $practical = $this->makeUser('student', ['results.grade']);
        $this->makeClass($student, $practical, $this->exam);
        $okReviewer = $this->makeReviewer();
        $notGrader = $this->makeUser('student', ['problem.create']);
        $inactive = $this->makeReviewer(null, 'Inactive');
        $admin = $this->makeUser('admin');

        foreach ([null, '', 'khong-co-user', $notGrader->id, $inactive->id, $admin->id] as $bad) {
            $this->putJson(self::BASE . "/{$sub->id}", ['reviewerId' => $bad])->assertStatus(404)->assertJsonPath('detail', 'Không tìm thấy giảng viên chấm phúc khảo.');
        }
        $this->putJson(self::BASE . "/{$sub->id}", [])->assertStatus(404);
        $this->putJson(self::BASE . "/{$sub->id}", ['reviewerId' => $practical->id])->assertStatus(422)
            ->assertJsonPath('detail', 'Không được chọn giảng viên thực hành đứng lớp của sinh viên này để chấm phúc khảo.');
        $this->assertNull(SubmissionRegradeAssignment::find($sub->id), 'không tạo phân công khi bị từ chối');
        $this->assertSame(0, DB::table('notifications')->where('user_id', $practical->id)->count());

        $this->assignRow($sub, $okReviewer, ['completed_at' => now()]);
        $this->putJson(self::BASE . "/{$sub->id}", ['reviewerId' => $this->makeReviewer()->id])->assertStatus(422)
            ->assertJsonPath('detail', 'Bài nộp này đã được chấm phúc khảo xong, không thể phân công lại.');
        $this->assertSame($okReviewer->id, SubmissionRegradeAssignment::find($sub->id)->assigned_instructor_id);
    }

    // ───────────────────────── unassign ─────────────────────────

    public function test_unassign_removes_the_assignment_and_notifies_the_reviewer(): void
    {
        $sub = $this->makeSubmission($this->makeUser('student'));
        $reviewer = $this->makeReviewer();
        $this->assignRow($sub, $reviewer);

        $res = $this->deleteJson(self::BASE . "/{$sub->id}")->assertOk();

        $this->assertSame(array_merge(self::ROW_KEYS, ['ok']), array_keys($res->json()));
        $this->assertTrue($res->json('ok'));
        $this->assertNull($res->json('reviewerId'));
        $this->assertNull(SubmissionRegradeAssignment::find($sub->id));
        $notice = DB::table('notifications')->where('user_id', $reviewer->id)->where('title', 'like', 'Hủy phân công chấm phúc khảo:%')->first();
        $this->assertNotNull($notice);
        $this->assertSame('Info', $notice->type);
        $this->assertSame('/teacher/regrade', $notice->link);

        // Hủy lần nữa: vẫn OK, không báo thêm.
        $this->deleteJson(self::BASE . "/{$sub->id}")->assertOk();
        $this->assertSame(1, $this->notificationsFor($reviewer, 'Hủy phân công'));
    }

    public function test_unassign_rejects_unknown_practice_question_and_completed_submissions(): void
    {
        $student = $this->makeUser('student');
        $this->deleteJson(self::BASE . '/' . Str::uuid())->assertStatus(404);

        $practice = $this->makeSubmission($student, null, ['source' => 'Practice', 'context' => 'Practice']);
        $this->deleteJson(self::BASE . "/{$practice->id}")->assertStatus(422);

        $question = $this->makeSubmission($student, null, [], $this->makeProblem());
        DB::table('workspace_submission_results')->insert([
            'submission_id' => $question->id, 'sections' => json_encode([['_questionSubmission' => ['part' => 'ddl']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->deleteJson(self::BASE . "/{$question->id}")->assertStatus(422);

        $done = $this->makeSubmission($student, null, [], $this->makeProblem());
        $reviewer = $this->makeReviewer();
        $this->assignRow($done, $reviewer, ['completed_at' => now()]);
        $this->deleteJson(self::BASE . "/{$done->id}")->assertStatus(422)
            ->assertJsonPath('detail', 'Bài nộp này đã được chấm phúc khảo xong, không thể phân công lại.');
        $this->assertNotNull(SubmissionRegradeAssignment::find($done->id));
        $this->assertSame(0, $this->notificationsFor($reviewer, 'Hủy phân công'));
    }

    // ───────────────────────── mine ─────────────────────────

    public function test_mine_lists_only_my_assignments_newest_first_in_the_row_shape(): void
    {
        $me = $this->makeReviewer('RG Me ' . uniqid());
        $other = $this->makeReviewer();
        $mine = [];
        foreach ([5, 1, 4, 2, 3] as $hoursAgo) {   // thứ tự phân công khác thứ tự khóa chính
            $sub = $this->makeSubmission($this->makeUser('student'), null, [], $this->makeProblem());
            $this->assignRow($sub, $me, ['assigned_at' => now()->subHours($hoursAgo)]);
            $mine[$hoursAgo] = $sub->id;
        }
        $theirs = $this->makeSubmission($this->makeUser('student'), null, [], $this->makeProblem());
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
