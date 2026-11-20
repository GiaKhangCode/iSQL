<?php

namespace Tests\Feature;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GradingFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả GET /api/student/profile (SubmissionController::studentProfile): hồ sơ, lớp đang học,
 * số bài luyện tập và lịch sử bài tập / kỳ thi kèm điểm.
 */
class StudentProfileTest extends TestCase
{
    use DatabaseTransactions;
    use GradingFixtures;
    use UsesDefaultRolePermissions;

    protected User $student;
    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        $this->teacher = $this->makeAccount('instructor');
        $this->student = $this->makeStudent('2199' . random_int(1000, 9999));
        Sanctum::actingAs($this->student);
    }

    private function profile(): array
    {
        return $this->getJson('/api/student/profile')->assertOk()->json();
    }

    private function historyOf(Assignment $activity): ?array
    {
        foreach ($this->profile()['history'] as $row) {
            if ($row['id'] === $activity->id) {
                return $row;
            }
        }

        return null;
    }

    private function attempt(Assignment $activity, string $status = 'submitted', array $o = []): ActivityAttempt
    {
        return ActivityAttempt::create(array_merge([
            'assignment_id' => $activity->id, 'user_id' => $this->student->id, 'status' => $status,
            'drafts' => [], 'violations' => [], 'started_at' => now(),
        ], $o));
    }

    public function test_requires_the_practice_capability(): void
    {
        Sanctum::actingAs($this->makeAccount('instructor', ['results.grade']));
        $this->getJson('/api/student/profile')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/student/profile', ['Authorization' => ''])->assertStatus(401);
    }

    public function test_identity_fields_are_duplicated_into_the_profile_block(): void
    {
        $json = $this->profile();

        $identity = \App\Models\StudentIdentity::find($this->student->id);
        foreach ([$json, $json['profile']] as $block) {
            $this->assertSame($this->student->id, $block['id']);
            $this->assertSame($this->student->name, $block['name']);
            $this->assertSame($this->student->email, $block['email']);
            $this->assertSame($identity->student_code, $block['studentCode']);
            $this->assertSame('Khoa Hệ thống Thông tin', $block['department']);
            $this->assertSame(0, $block['practiceSubmissions']);
            $this->assertSame([], $block['classes']);
        }
        $this->assertSame([], $json['history']);
    }

    public function test_the_student_code_falls_back_to_the_email_prefix(): void
    {
        $noCode = $this->makeStudent();
        Sanctum::actingAs($noCode);

        $this->assertSame(strtolower(explode('@', $noCode->email)[0]), $this->profile()['studentCode']);
    }

    public function test_classes_are_listed_by_course_with_fallbacks(): void
    {
        $lecturer = $this->makeAccount('instructor');
        $zeta = $this->makeClass(['course' => 'Z Môn', 'course_code' => 'IT001', 'theory_class' => 'IT001.1', 'practice_class' => 'IT001.1.1', 'term' => 'HK1', 'instructor_id' => $lecturer->id]);
        $alpha = $this->makeClass(['course' => 'A Môn', 'theory_class' => null, 'practice_class' => null, 'term' => 'HK2']);
        $notMine = $this->makeClass(['course' => 'B Môn']);
        $this->enroll($zeta, $this->student);
        $this->enroll($alpha, $this->student);
        $this->enroll($notMine, $this->makeStudent());

        $classes = $this->profile()['classes'];

        $this->assertSame(['A Môn', 'Z Môn'], array_column($classes, 'course'));
        $this->assertSame([
            'id' => $alpha->id, 'course' => 'A Môn', 'courseCode' => '', 'theoryClass' => $alpha->id,
            'practiceClass' => $alpha->id, 'term' => 'HK2', 'lecturer' => '',
        ], $classes[0]);
        $this->assertSame([
            'id' => $zeta->id, 'course' => 'Z Môn', 'courseCode' => 'IT001', 'theoryClass' => 'IT001.1',
            'practiceClass' => 'IT001.1.1', 'term' => 'HK1', 'lecturer' => $lecturer->name,
        ], $classes[1]);
    }

    public function test_practice_submissions_count_official_practice_attempts_only(): void
    {
        $problem = $this->makeProblem();
        $activity = $this->makeActivity($this->teacher);
        $this->makeSubmission($this->student, $problem);
        $this->makeSubmission($this->student, $problem, null, ['source' => 'Assignments', 'context' => 'Practice']);
        $this->makeSubmission($this->student, $problem, $activity, ['source' => 'Practice']);
        $this->questionOnly($this->makeSubmission($this->student, $problem));
        $this->makeSubmission($this->student, $problem, $activity);
        $this->makeSubmission($this->makeStudent(), $problem);

        $this->assertSame(3, $this->profile()['practiceSubmissions']);
    }

    public function test_history_scores_use_the_best_available_score_capped_per_problem(): void
    {
        $activity = $this->makeActivity($this->teacher, ['title' => 'Bài tập 1', 'room' => 'P1', 'session_index' => 2]);
        $p1 = $this->makeProblem();
        $p2 = $this->makeProblem();
        $p3 = $this->makeProblem();
        $this->attachProblem($activity, $p1, 10, 1);
        $this->attachProblem($activity, $p2, 4, 2);
        $this->attachProblem($activity, $p3, 6, 3);
        $base = now()->subDays(2)->startOfSecond();
        // p1: có điểm chính thức 7 (ưu tiên hơn điểm tự động 9); chỉ lần nộp mới nhất tính.
        $this->makeSubmission($this->student, $p1, $activity, ['score' => 1, 'evaluated_score' => 2, 'submitted_at' => $base]);
        $this->makeSubmission($this->student, $p1, $activity, ['score' => 9, 'evaluated_score' => 7, 'submitted_at' => $base->copy()->addHour()]);
        // p2: chỉ điểm tự động 9, bị chặn bởi điểm tối đa 4.
        $this->makeSubmission($this->student, $p2, $activity, ['score' => 9, 'submitted_at' => $base->copy()->addHours(2)]);
        // p3 chưa nộp.
        $this->attempt($activity);

        $row = $this->historyOf($activity);

        $this->assertSame('Bài tập 1', $row['title']);
        $this->assertSame('Assignment', $row['kind']);
        $this->assertNull($row['examinationId']);
        $this->assertSame(2, $row['sessionIndex']);
        $this->assertSame('P1', $row['room']);
        $this->assertEquals(11, $row['score']);
        $this->assertEquals(20, $row['maxScore']);
        $this->assertTrue($row['scorePublished']);
        $this->assertSame(2, $row['submittedProblems']);
        $this->assertSame(3, $row['totalProblems']);
        $this->assertSame('graded', $row['status']);
    }

    public function test_hidden_scores_are_not_reported_and_the_status_says_so(): void
    {
        $activity = $this->makeActivity($this->teacher, ['show_scores_after_submit' => false]);
        $problem = $this->makeProblem();
        $this->attachProblem($activity, $problem, 10);
        $this->makeSubmission($this->student, $problem, $activity, ['evaluated_score' => 8]);

        $row = $this->historyOf($activity);

        $this->assertNull($row['score']);
        $this->assertFalse($row['scorePublished']);
        $this->assertSame('unpublished', $row['status']);
        $this->assertEquals(10, $row['maxScore']);
    }

    public function test_status_follows_the_attempt_and_submissions(): void
    {
        $problem = $this->makeProblem();
        $active = $this->makeActivity($this->teacher);
        $submittedEmpty = $this->makeActivity($this->teacher);
        $withWork = $this->makeActivity($this->teacher);
        $activeWithWork = $this->makeActivity($this->teacher);
        foreach ([$active, $submittedEmpty, $withWork, $activeWithWork] as $a) {
            $this->attachProblem($a, $problem);
        }
        $this->attempt($active, 'active');
        $this->attempt($submittedEmpty, 'submitted');
        $this->makeSubmission($this->student, $problem, $withWork);
        $this->attempt($activeWithWork, 'active');
        $this->makeSubmission($this->student, $problem, $activeWithWork);

        $this->assertSame('in_progress', $this->historyOf($active)['status']);
        $this->assertSame('graded', $this->historyOf($submittedEmpty)['status']);
        $this->assertSame(0, $this->historyOf($submittedEmpty)['submittedProblems']);
        $this->assertSame('graded', $this->historyOf($withWork)['status']);
        $this->assertSame('graded', $this->historyOf($activeWithWork)['status']);
    }

    public function test_other_attempt_states_are_not_submitted(): void
    {
        $activity = $this->makeActivity($this->teacher);
        $this->attachProblem($activity, $this->makeProblem());
        $this->attempt($activity, 'in_progress');

        $this->assertSame('not_submitted', $this->historyOf($activity)['status']);
    }

    public function test_submitted_time_prefers_the_attempt_then_the_latest_submission(): void
    {
        $problem = $this->makeProblem();
        $withAttempt = $this->makeActivity($this->teacher);
        $withoutAttempt = $this->makeActivity($this->teacher);
        $this->attachProblem($withAttempt, $problem);
        $this->attachProblem($withoutAttempt, $problem);
        $at = now()->subDays(3)->startOfSecond();
        $this->attempt($withAttempt, 'submitted', ['submitted_at' => $at, 'finish_reason' => 'timeout']);
        $this->makeSubmission($this->student, $problem, $withAttempt, ['submitted_at' => now()->subDay()->startOfSecond()]);
        $second = now()->subHours(5)->startOfSecond();
        $this->makeSubmission($this->student, $problem, $withoutAttempt, ['submitted_at' => now()->subDays(9)->startOfSecond()]);
        $this->makeSubmission($this->student, $this->makeProblem(), $withoutAttempt, ['submitted_at' => $second]);

        $a = $this->historyOf($withAttempt);
        $b = $this->historyOf($withoutAttempt);

        $this->assertSame($at->toISOString(), $a['submittedAt']);
        $this->assertSame($a['submittedAt'], $a['date']);
        $this->assertSame('timeout', $a['finishReason']);
        $this->assertNull($b['finishReason']);
        $this->assertNotNull($b['submittedAt']);
    }

    public function test_exam_sessions_are_labelled_as_examinations(): void
    {
        $session = $this->makeExamSession(['room' => 'Phòng 7']);
        $this->attachProblem($session, $this->makeProblem());
        $this->attempt($session, 'submitted');

        $row = $this->historyOf($session);

        $this->assertSame('Examination', $row['kind']);
        $this->assertSame($session->examination_id, $row['examinationId']);
        $this->assertSame('Phòng 7', $row['room']);
    }

    public function test_history_is_ordered_by_closing_time_newest_first(): void
    {
        $problem = $this->makeProblem();
        $base = now()->addYears(3)->startOfHour();
        $ids = [];
        foreach ([3, 1, 5, 2, 4] as $d) {
            $a = $this->makeActivity($this->teacher, ['title' => "đóng {$d}", 'closes' => $base->copy()->addDays($d)]);
            $this->attachProblem($a, $problem);
            $this->makeSubmission($this->student, $problem, $a);
            $ids[$d] = $a->id;
        }

        $titles = array_column($this->profile()['history'], 'title');

        $this->assertSame(['đóng 5', 'đóng 4', 'đóng 3', 'đóng 2', 'đóng 1'], $titles);
    }

    public function test_history_ignores_other_users_practice_context_question_updates_and_other_sources(): void
    {
        $problem = $this->makeProblem();
        $other = $this->makeActivity($this->teacher);
        $onlyQuestion = $this->makeActivity($this->teacher);
        $unrelated = $this->makeActivity($this->teacher);
        foreach ([$other, $onlyQuestion, $unrelated] as $a) {
            $this->attachProblem($a, $problem);
        }
        $this->makeSubmission($this->makeStudent(), $problem, $other);
        $this->questionOnly($this->makeSubmission($this->student, $problem, $onlyQuestion));
        $this->makeSubmission($this->student, $problem, $unrelated, ['source' => 'Practice']);

        $ids = array_column($this->profile()['history'], 'id');

        $this->assertNotContains($other->id, $ids);
        $this->assertNotContains($onlyQuestion->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_an_activity_with_only_an_attempt_still_appears(): void
    {
        $activity = $this->makeActivity($this->teacher, ['title' => 'Chỉ có lượt làm']);
        $this->attachProblem($activity, $this->makeProblem());
        $this->attempt($activity, 'active');

        $row = $this->historyOf($activity);

        $this->assertSame('Chỉ có lượt làm', $row['title']);
        $this->assertSame(0, $row['submittedProblems']);
        $this->assertEquals(0, $row['score']);
    }
}
