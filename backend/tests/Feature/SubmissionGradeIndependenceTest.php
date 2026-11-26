<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\ExamRegrade;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionGrade;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
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
 * Giảng viên phúc khảo chấm trên bản riêng: điểm tổng, điểm từng câu và nhận xét của lần chấm đầu không bị ghi đè
 * (submission_grades). Điểm chính thức (submissions.evaluated_score / feedback) là điểm phúc khảo khi có.
 */
class SubmissionGradeIndependenceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private User $owner;      // chủ bài, chấm lần đầu
    private User $reviewer;   // phúc khảo
    private User $student;
    private Assignment $exam;
    private Submission $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        $this->owner = $this->makeUser('student', ['results.grade', 'assignment.create']);
        $this->reviewer = $this->makeUser('student', ['results.grade', 'results.regrade']);
        $this->student = $this->makeUser('student');

        $problem = Problem::create([
            'number' => 'GI-' . strtoupper(substr(uniqid(), -8)), 'title' => 'GI problem', 'difficulty' => 'Easy',
            'description' => 'd', 'requirements' => 'r', 'practice_listed' => false, 'creator_id' => $this->owner->id,
        ]);
        $this->exam = Assignment::create([
            'title' => 'GI exam ' . uniqid(), 'instructor_id' => $this->owner->id, 'published' => true, 'exam_mode' => true,
            'opens' => now()->subDay(), 'closes' => now()->addDay(), 'show_scores_after_submit' => true,
        ]);
        $this->exam->problems()->attach($problem->id, ['points' => 10, 'order_index' => 1]);
        // Bài thi: người chấm lần đầu phải là giảng viên lớp của sinh viên.
        $class = ClassModel::create([
            'id' => 'GI_' . strtoupper(uniqid()), 'course' => 'GI Course ' . uniqid(), 'term' => 'GI Term',
            'status' => 'Active', 'instructor_id' => $this->owner->id,
        ]);
        $class->students()->attach($this->student->id, ['role' => 'Member', 'joined_at' => now()]);
        DB::table('assignment_classes')->insert(['id' => (string) Str::uuid(), 'assignment_id' => $this->exam->id, 'class_id' => $class->id]);
        $this->sub = Submission::create([
            'id' => (string) Str::uuid(), 'user_id' => $this->student->id, 'problem_id' => $problem->id,
            'source' => 'Assignments', 'context' => $this->exam->id, 'result' => 'Pending', 'score' => 4,
            'submitted_at' => now(), 'query_text' => 'SELECT 1',
        ]);
        WorkspaceSubmissionResult::create(['submission_id' => $this->sub->id, 'sections' => [[
            'part' => 'SQL', 'status' => 'Partial', 'questions' => [
                ['id' => 'q1', 'status' => 'Accepted'], ['id' => 'q2', 'status' => 'Wrong Answer'],
            ],
        ]]]);
    }

    private function makeUser(string $kind, ?array $permissions = null): User
    {
        $user = User::create([
            'name' => 'GI ' . uniqid(), 'email' => 'gi_' . uniqid() . '@uit.edu.vn', 'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($kind), 'status' => 'Active', 'initials' => 'GI',
        ]);
        if ($permissions !== null) {
            DB::table('user_access_roles')->where('user_id', $user->id)->delete();
            $role = AccessRole::create(['name' => 'GI role ' . uniqid()]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    /** Payload chấm theo câu: điểm cuối = $q1 + $q2, nhận xét đặt ở câu q1. */
    private function graded(float $q1, float $q2, string $comment = ''): array
    {
        return ['questionScores' => ['SQL:q1' => $q1, 'SQL:q2' => $q2], 'questionFeedback' => ['SQL:q1' => $comment]];
    }

    private function review(User $as, array $payload)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($as);

        return $this->putJson("/api/submissions/{$this->sub->id}/review", $payload);
    }

    private function assignRegrade(): ExamRegrade
    {
        return ExamRegrade::create([
            'activity_id' => $this->exam->id, 'student_id' => $this->student->id, 'assigned_instructor_id' => $this->reviewer->id,
            'assigned_at' => now(), 'completed_at' => null,
        ]);
    }

    private function questionManualScores(): array
    {
        $sections = WorkspaceSubmissionResult::find($this->sub->id)->sections;

        return collect($sections[0]['questions'])->mapWithKeys(fn ($q) => [$q['id'] => $q['manualScore'] ?? null])->all();
    }

    public function test_regrade_does_not_overwrite_the_first_graders_score_note_or_question_scores(): void
    {
        $this->review($this->owner, $this->graded(5, 3, 'Nhận xét lần đầu'))->assertOk();
        $this->assignRegrade();

        $this->review($this->reviewer, $this->graded(4, 2, 'Nhận xét phúc khảo'))->assertOk();

        $initial = SubmissionGrade::where('submission_id', $this->sub->id)->where('kind', 'initial')->first();
        $regrade = SubmissionGrade::where('submission_id', $this->sub->id)->where('kind', 'regrade')->first();
        $this->assertSame(8.0, $initial->score);
        $this->assertEquals(['SQL:q1' => 'Nhận xét lần đầu'], $initial->question_feedback);
        $this->assertEquals(['SQL:q1' => 5, 'SQL:q2' => 3], $initial->question_scores);
        $this->assertSame(6.0, $regrade->score);
        $this->assertEquals(['SQL:q1' => 'Nhận xét phúc khảo'], $regrade->question_feedback);
        $this->assertEquals(['SQL:q1' => 4, 'SQL:q2' => 2], $regrade->question_scores);
        $this->assertSame($this->reviewer->id, $regrade->grader_id);

        // Điểm chính thức (sinh viên thấy) là điểm phúc khảo, kể cả điểm từng câu.
        $this->assertSame(6.0, (float) $this->sub->fresh()->evaluated_score);
        $this->assertNull($this->sub->fresh()->feedback);
        $this->assertEquals(['q1' => 4.0, 'q2' => 2.0], $this->questionManualScores());
    }

    public function test_grading_done_before_the_table_existed_is_snapshotted_as_the_initial_grade(): void
    {
        // Bài đã chấm theo cách cũ: chỉ có điểm/nhận xét trên bài nộp và điểm tay trong kết quả, chưa có dòng submission_grades.
        $this->sub->update(['evaluated_score' => 9, 'feedback' => 'Chấm từ trước', 'result' => 'Partial']);
        $ws = WorkspaceSubmissionResult::find($this->sub->id);
        $sections = $ws->sections;
        $sections[0]['questions'][0]['manualScore'] = 7;
        $ws->sections = $sections;
        $ws->save();
        $this->assignRegrade();

        $this->review($this->reviewer, $this->graded(5, 0))->assertOk();

        $initial = SubmissionGrade::where('submission_id', $this->sub->id)->where('kind', 'initial')->first();
        $this->assertSame(9.0, $initial->score);
        $this->assertSame('Chấm từ trước', $initial->feedback);
        $this->assertEquals(['SQL:q1' => 7], $initial->question_scores);
        $this->assertNull($initial->grader_id, 'không biết ai đã chấm lần đầu');
        // Điểm tay chính thức là của người phúc khảo, bản chụp lần đầu giữ nguyên.
        $this->assertEquals(['q1' => 5.0, 'q2' => 0.0], $this->questionManualScores());
    }

    public function test_regrading_again_updates_only_the_regrade_and_the_first_grader_cannot_override_it(): void
    {
        $this->review($this->owner, $this->graded(5, 3, 'Đầu'))->assertOk();
        $this->assignRegrade();
        $this->review($this->reviewer, $this->graded(4, 2, 'PK 1'))->assertOk();
        $this->review($this->reviewer, $this->graded(4, 3, 'PK 2'))->assertOk();

        $this->assertSame(8.0, SubmissionGrade::where('submission_id', $this->sub->id)->where('kind', 'initial')->value('score'));
        $this->assertEquals(['SQL:q1' => 'Đầu'], SubmissionGrade::where('submission_id', $this->sub->id)->where('kind', 'initial')->first()->question_feedback);
        $this->assertSame(7.0, SubmissionGrade::where('submission_id', $this->sub->id)->where('kind', 'regrade')->value('score'));
        $this->assertSame(2, SubmissionGrade::where('submission_id', $this->sub->id)->count());

        $this->review($this->owner, $this->graded(1, 0, 'Sửa lại'))->assertForbidden();
        $this->assertSame(7.0, (float) $this->sub->fresh()->evaluated_score);
    }

    public function test_every_save_is_kept_in_the_grade_history_and_hidden_from_students(): void
    {
        $this->review($this->owner, $this->graded(5, 3, 'Đầu'))->assertOk();
        $this->review($this->owner, $this->graded(5, 4, 'Đầu sửa'))->assertOk();
        $this->assignRegrade();
        $this->review($this->reviewer, $this->graded(4, 2, 'PK'))->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->reviewer);
        $history = $this->getJson("/api/submissions/{$this->sub->id}")->assertOk()->json('gradeHistory');
        $this->assertCount(3, $history);
        $this->assertSame('regrade', $history[0]['kind']);
        $this->assertEqualsCanonicalizing([8.0, 9.0], [(float) $history[1]['score'], (float) $history[2]['score']]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->student);
        $student = $this->getJson("/api/submissions/{$this->sub->id}")->assertOk();
        $this->assertEmpty($student->json('gradeHistory'));
        $this->assertEmpty($student->json('questionSubmissions'));
    }

    public function test_every_question_must_be_graded_within_its_maximum_and_the_total_is_their_sum(): void
    {
        $this->review($this->owner, ['finalScore' => 8])->assertStatus(422);
        $this->review($this->owner, ['questionScores' => ['SQL:q1' => 5]])->assertStatus(422);
        $this->review($this->owner, $this->graded(6, 1))->assertStatus(422);
        $this->review($this->owner, $this->graded(-1, 1))->assertStatus(422);
        $this->assertNull($this->sub->fresh()->evaluated_score);

        // finalScore và nhận xét chung gửi kèm bị bỏ qua.
        $this->review($this->owner, $this->graded(2.5, 1.25) + ['finalScore' => 10, 'feedback' => 'chung'])->assertOk();
        $this->assertSame(3.75, (float) $this->sub->fresh()->evaluated_score);
        $this->assertNull($this->sub->fresh()->feedback);
    }

    public function test_staff_see_both_grades_and_the_student_only_the_official_one(): void
    {
        $this->review($this->owner, $this->graded(5, 3, 'Đầu'))->assertOk();
        $this->assignRegrade();
        $this->review($this->reviewer, $this->graded(4, 2, 'Phúc khảo'))->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->reviewer);
        $teacher = $this->getJson("/api/submissions/{$this->sub->id}")->assertOk();
        $this->assertSame(8.0, (float) $teacher->json('grades.initial.score'));
        $this->assertSame('Đầu', $teacher->json('grades.initial.questionFeedback')['SQL:q1']);
        $this->assertSame(5.0, (float) $teacher->json('questionMaxScores')['SQL:q1']);
        $this->assertSame(6.0, (float) $teacher->json('grades.regrade.score'));

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->student);
        $student = $this->getJson("/api/submissions/{$this->sub->id}")->assertOk();
        $this->assertSame(6.0, (float) $student->json('evaluatedScore'));
        $this->assertSame('Phúc khảo', $student->json('sections.0.questions.0.manualFeedback'));
        $this->assertNull($student->json('grades'));
    }
}
