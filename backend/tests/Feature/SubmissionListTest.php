<?php

namespace Tests\Feature;

use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GradingFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả GET /api/submissions: danh sách bài nộp của CHÍNH người đang đăng nhập, kèm bộ lọc và che điểm
 * khi hoạt động ẩn điểm (SubmissionController::index / formatStudentSubmission).
 */
class SubmissionListTest extends TestCase
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
        $this->student = $this->makeStudent();
        $this->teacher = $this->makeAccount('instructor');
        Sanctum::actingAs($this->student);
    }

    private function list(string $query = ''): array
    {
        return $this->getJson('/api/submissions' . $query)->assertOk()->json();
    }

    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/submissions')->assertStatus(401);
    }

    // ══ phạm vi và thứ tự ═══════════════════════════════════════════════════

    public function test_lists_only_the_callers_own_submissions_newest_first(): void
    {
        $problem = $this->makeProblem();
        $other = $this->makeStudent();
        $this->makeSubmission($other, $problem);
        $base = now()->subDays(10)->startOfSecond();
        $subs = [];
        foreach ([3, 1, 5, 2, 4] as $i) {
            $subs[$i] = $this->makeSubmission($this->student, $problem, null, ['submitted_at' => $base->copy()->addHours($i)]);
        }

        $rows = $this->list();

        $this->assertSame([$subs[5]->id, $subs[4]->id, $subs[3]->id, $subs[2]->id, $subs[1]->id], $this->ids($rows));
    }

    public function test_equal_submission_times_are_ordered_by_id_descending(): void
    {
        $problem = $this->makeProblem();
        $at = now()->subDay()->startOfSecond();
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->makeSubmission($this->student, $problem, null, ['submitted_at' => $at])->id;
        }
        rsort($ids);

        $this->assertSame($ids, $this->ids($this->list()));
    }

    public function test_question_updates_are_hidden_unless_requested(): void
    {
        $problem = $this->makeProblem();
        $official = $this->makeSubmission($this->student, $problem);
        $single = $this->questionOnly($this->makeSubmission($this->student, $problem, null, ['query_text' => 'SELECT 2']));

        $this->assertSame([$official->id], $this->ids($this->list()));
        $this->assertSame([$official->id], $this->ids($this->list('?include_question_updates=false')));
        foreach (['true', '1', 'on'] as $flag) {
            $this->assertEqualsCanonicalizing([$official->id, $single->id], $this->ids($this->list("?include_question_updates={$flag}")), $flag);
        }
        $this->assertSame([$official->id], $this->ids($this->list('?include_question_updates=khong')));
    }

    // ══ bộ lọc ═════════════════════════════════════════════════════════════

    public function test_source_filter_groups_assignment_spellings(): void
    {
        $problem = $this->makeProblem();
        $activity = $this->makeActivity($this->teacher);
        $practice = $this->makeSubmission($this->student, $problem);
        $assignments = $this->makeSubmission($this->student, $problem, $activity, ['source' => 'Assignments']);
        $assignment = $this->makeSubmission($this->student, $problem, $activity, ['source' => 'Assignment']);
        $exam = $this->makeSubmission($this->student, $problem, $activity, ['source' => 'ExamSession']);

        $this->assertEqualsCanonicalizing([$assignments->id, $assignment->id], $this->ids($this->list('?source=Assignments')));
        $this->assertEqualsCanonicalizing([$assignments->id, $assignment->id], $this->ids($this->list('?source=Assignment')));
        $this->assertSame([$exam->id], $this->ids($this->list('?source=ExamSession')));
        $this->assertSame([$practice->id], $this->ids($this->list('?source=Practice')));
        $this->assertSame([], $this->list('?source=Khac'));
        $this->assertCount(4, $this->list('?source='));
    }

    public function test_result_filter_applies_to_the_displayed_result(): void
    {
        $problem = $this->makeProblem();
        $visible = $this->makeActivity($this->teacher);
        $hidden = $this->makeActivity($this->teacher, ['show_scores_after_submit' => false]);
        $accepted = $this->makeSubmission($this->student, $problem, $visible, ['result' => 'Accepted']);
        $partial = $this->makeSubmission($this->student, $problem, $visible, ['result' => 'Partial']);
        $masked = $this->makeSubmission($this->student, $problem, $hidden, ['result' => 'Accepted']);

        $this->assertSame([$accepted->id], $this->ids($this->list('?result=Accepted')));
        $this->assertSame([$partial->id], $this->ids($this->list('?result=Partial')));
        // Ca ẩn điểm hiển thị "Submitted" nên lọc theo kết quả thật không thấy nó.
        $this->assertSame([$masked->id], $this->ids($this->list('?result=Submitted')));
        $this->assertCount(3, $this->list('?result=%20'));
    }

    public function test_search_matches_problem_or_activity_title_ignoring_case(): void
    {
        $alpha = $this->makeProblem(['title' => 'Truy vấn ALPHA']);
        $beta = $this->makeProblem(['title' => 'Bài beta']);
        $activity = $this->makeActivity($this->teacher, ['title' => 'Kiểm tra Giữa Kỳ']);
        $a = $this->makeSubmission($this->student, $alpha);
        $b = $this->makeSubmission($this->student, $beta, $activity);

        $this->assertSame([$a->id], $this->ids($this->list('?search=alpha')));
        $this->assertSame([$a->id], $this->ids($this->list('?search=%20ALPHA%20')));
        $this->assertSame([$b->id], $this->ids($this->list('?search=' . rawurlencode('giữa kỳ'))));
        $this->assertSame([$b->id], $this->ids($this->list('?search=BETA')));
        $this->assertSame([], $this->list('?search=khong-co'));
        $this->assertCount(2, $this->list('?search='));
    }

    // ══ định dạng một dòng ═════════════════════════════════════════════════

    public function test_practice_submission_row(): void
    {
        $problem = $this->makeProblem(['title' => 'Bài luyện', 'topic' => null]);
        $sub = $this->makeSubmission($this->student, $problem, null, ['result' => 'Accepted', 'score' => 80, 'feedback' => 'Tốt', 'database_type' => null]);

        $row = $this->list()[0];

        $this->assertSame($sub->id, $row['id']);
        $this->assertSame($problem->id, $row['problemId']);
        $this->assertSame('Bài luyện', $row['problem']);
        $this->assertSame('General', $row['topic']);
        $this->assertSame('Practice', $row['source']);
        $this->assertSame('Practice', $row['context']);
        $this->assertSame('Practice', $row['contextTitle']);
        $this->assertSame('Accepted', $row['result']);
        $this->assertEquals(80, $row['score']);
        $this->assertEquals(100, $row['maxScore']);
        $this->assertNull($row['evaluatedScore']);
        $this->assertSame('Tốt', $row['feedback']);
        $this->assertSame('SELECT 1', $row['query']);
        $this->assertSame('SQL Server', $row['database']);
        $this->assertTrue($row['scoreVisible']);
        $this->assertFalse($row['questionOnly']);
        $this->assertNull($row['questionPart']);
        $this->assertNull($row['submittedScript']);
        $this->assertFalse($row['canAppeal']);
        $this->assertNull($row['appealStatus']);
        $this->assertNull($row['appealReason']);
        $this->assertNotEmpty($row['time']);
        $this->assertNotNull($row['submittedAt']);
    }

    public function test_deleted_problem_and_empty_fields_fall_back_to_defaults(): void
    {
        $problem = $this->makeProblem();
        $sub = $this->makeSubmission($this->student, $problem, null, ['result' => '', 'source' => '', 'context' => '', 'query_text' => null]);
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        \Illuminate\Support\Facades\DB::table('problems')->where('id', $problem->id)->delete();
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $row = $this->list()[0];

        $this->assertSame('Unknown Problem', $row['problem']);
        $this->assertSame('General', $row['topic']);
        $this->assertSame('Practice', $row['source']);
        $this->assertSame('Practice', $row['context']);
        $this->assertSame('Submitted', $row['result']);
        $this->assertSame('', $row['query']);
    }

    public function test_activity_submission_uses_the_assignment_points_and_title(): void
    {
        $problem = $this->makeProblem();
        $activity = $this->makeActivity($this->teacher, ['title' => 'Bài tập tuần 3']);
        $this->attachProblem($activity, $problem, 7.5);
        $this->makeSubmission($this->student, $problem, $activity, ['evaluated_score' => 6, 'feedback' => 'Khá']);

        $row = $this->list()[0];

        $this->assertSame('Bài tập tuần 3', $row['contextTitle']);
        $this->assertSame($activity->id, $row['context']);
        $this->assertSame(7.5, $row['maxScore']);
        $this->assertEquals(6, $row['evaluatedScore']);
        $this->assertSame('Khá', $row['feedback']);
    }

    public function test_activity_without_a_matching_problem_row_defaults_the_max_score_to_100(): void
    {
        $activity = $this->makeActivity($this->teacher);
        $this->makeSubmission($this->student, $this->makeProblem(), $activity);

        $this->assertEquals(100, $this->list()[0]['maxScore']);
    }

    public function test_hidden_scores_are_masked(): void
    {
        $problem = $this->makeProblem();
        $activity = $this->makeActivity($this->teacher, ['show_scores_after_submit' => false]);
        $sub = $this->makeSubmission($this->student, $problem, $activity, [
            'result' => 'Accepted', 'score' => 9, 'evaluated_score' => 8, 'feedback' => 'Bí mật',
        ]);
        $this->withSections($sub, [['part' => 'SQL', 'status' => 'Accepted', 'questions' => []]]);

        $row = $this->list()[0];

        $this->assertFalse($row['scoreVisible']);
        $this->assertSame('Submitted', $row['result']);
        $this->assertNull($row['score']);
        $this->assertNull($row['evaluatedScore']);
        $this->assertNull($row['feedback']);
        $this->assertNull($row['sections']);
        $this->assertNull($row['questionScore']);
        $this->assertSame('SELECT 1', $row['query']);
    }

    public function test_visible_sections_get_default_datasets_and_ratio(): void
    {
        $problem = $this->makeProblem();
        $sub = $this->makeSubmission($this->student, $problem);
        $this->withSections($sub, [[
            'part' => 'SQL', 'status' => 'Partial',
            'questions' => [
                ['id' => 'a', 'passed' => 1, 'total' => 4],
                ['id' => 'b', 'passed' => 0, 'total' => 0],
                ['id' => 'c', 'ratio' => 0.5, 'datasets' => [['dataset' => 1]], 'passed' => 9, 'total' => 9],
                'rac',
            ],
        ]]);

        $questions = $this->list()[0]['sections'][0]['questions'];

        $this->assertSame([], $questions[0]['datasets']);
        $this->assertSame(0.25, $questions[0]['ratio']);
        $this->assertSame(0, $questions[1]['ratio']);
        $this->assertSame(0.5, $questions[2]['ratio']);
        $this->assertSame([['dataset' => 1]], $questions[2]['datasets']);
        $this->assertSame('rac', $questions[3]);
    }

    public function test_question_only_submission_exposes_its_metadata(): void
    {
        $problem = $this->makeProblem();
        $sub = $this->makeSubmission($this->student, $problem, null, ['query_text' => 'SELECT q']);
        $this->questionOnly($sub, 'DDL', 'q9', 75);

        $row = $this->list('?include_question_updates=true')[0];

        $this->assertTrue($row['questionOnly']);
        $this->assertSame('DDL', $row['questionPart']);
        $this->assertSame('q9', $row['questionId']);
        $this->assertEquals(75, $row['questionScore']);
        $this->assertSame('SELECT q', $row['submittedScript']);
        $this->assertFalse($row['canAppeal']);
    }

    // ══ trạng thái phúc khảo ═══════════════════════════════════════════════

    private function examSubmission(array $activityOverrides = [], array $subOverrides = [])
    {
        $problem = $this->makeProblem();
        $activity = $this->makeActivity($this->teacher, $activityOverrides + ['exam_mode' => true]);
        $this->attachProblem($activity, $problem);

        return $this->makeSubmission($this->student, $problem, $activity, $subOverrides + ['source' => 'ExamSession']);
    }

    public function test_appeal_status_follows_the_grading_state(): void
    {
        $ungraded = $this->examSubmission();
        $this->assertNull($this->list()[0]['appealStatus']);

        $ungraded->update(['evaluated_score' => 7]);
        $row = $this->list()[0];
        $this->assertSame('Graded', $row['appealStatus']);
        $this->assertTrue($row['canAppeal']);

        SubmissionRegradeAssignment::create(['submission_id' => $ungraded->id, 'assigned_instructor_id' => $this->teacher->id, 'assigned_at' => now()]);
        $row = $this->list()[0];
        $this->assertSame('Awaiting regrade', $row['appealStatus']);
        $this->assertFalse($row['canAppeal']);

        SubmissionRegradeAssignment::where('submission_id', $ungraded->id)->update(['completed_at' => now()]);
        $this->assertSame('Regraded', $this->list()[0]['appealStatus']);
    }

    public function test_an_appeal_decides_the_status_before_the_regrade_assignment(): void
    {
        $sub = $this->examSubmission([], ['evaluated_score' => 7]);
        $appeal = SubmissionAppeal::create([
            'submission_id' => $sub->id, 'student_id' => $this->student->id, 'reason' => 'Chấm sai câu 2',
            'status' => 'pending', 'created_at' => now()->subHour(), 'resolved_at' => null,
        ]);

        $row = $this->list()[0];
        $this->assertSame('Awaiting regrade', $row['appealStatus']);
        $this->assertSame('Chấm sai câu 2', $row['appealReason']);
        $this->assertNotNull($row['appealCreatedAt']);
        $this->assertNull($row['appealResolvedAt']);
        $this->assertFalse($row['canAppeal']);

        $appeal->update(['status' => 'resolved', 'resolved_at' => now()]);
        $row = $this->list()[0];
        $this->assertSame('Regraded', $row['appealStatus']);
        $this->assertNotNull($row['appealResolvedAt']);
    }

    public function test_regrade_and_grade_status_are_hidden_while_scores_are_hidden(): void
    {
        $sub = $this->examSubmission(['show_scores_after_submit' => false], ['evaluated_score' => 7]);
        $this->assertNull($this->list()[0]['appealStatus']);

        SubmissionRegradeAssignment::create(['submission_id' => $sub->id, 'assigned_instructor_id' => $this->teacher->id, 'assigned_at' => now()]);
        $this->assertNull($this->list()[0]['appealStatus']);

        // Đơn phúc khảo của chính sinh viên thì luôn hiện.
        SubmissionAppeal::create(['submission_id' => $sub->id, 'student_id' => $this->student->id, 'reason' => 'abcde', 'status' => 'pending', 'created_at' => now()]);
        $this->assertSame('Awaiting regrade', $this->list()[0]['appealStatus']);
    }

    public function test_can_appeal_needs_an_exam_a_visible_official_grade_and_a_context(): void
    {
        // Bài tập thường: không phúc khảo.
        $problem = $this->makeProblem();
        $plain = $this->makeActivity($this->teacher);
        $this->makeSubmission($this->student, $problem, $plain, ['evaluated_score' => 5]);
        $this->assertFalse($this->list()[0]['canAppeal']);

        // Nguồn ExamSession dù hoạt động không ở chế độ thi.
        $viaSource = $this->makeSubmission($this->student, $problem, $plain, ['source' => 'ExamSession', 'evaluated_score' => 5, 'submitted_at' => now()->addMinute()]);
        $this->assertTrue($this->list()[0]['canAppeal']);
        $viaSource->delete();

        // Ca thi của kỳ thi (examination_id) cũng tính là bài thi.
        $session = $this->makeExamSession();
        $examRow = $this->makeSubmission($this->student, $problem, $session, ['evaluated_score' => 5, 'submitted_at' => now()->addMinutes(2)]);
        $this->assertTrue($this->list()[0]['canAppeal']);

        // Chưa chấm chính thức.
        $examRow->update(['evaluated_score' => null]);
        $this->assertFalse($this->list()[0]['canAppeal']);
    }

    public function test_question_score_is_masked_while_scores_are_hidden(): void
    {
        $activity = $this->makeActivity($this->teacher, ['show_scores_after_submit' => false]);
        $sub = $this->makeSubmission($this->student, $this->makeProblem(), $activity);
        $this->questionOnly($sub, 'SQL', 'q1', 60);

        $row = $this->list('?include_question_updates=true')[0];

        $this->assertTrue($row['questionOnly']);
        $this->assertNull($row['questionScore']);
    }

    public function test_a_graded_question_only_exam_submission_cannot_be_appealed(): void
    {
        $sub = $this->examSubmission([], ['evaluated_score' => 7]);
        $this->questionOnly($sub);

        $row = $this->list('?include_question_updates=true')[0];

        $this->assertSame('Graded', $row['appealStatus']);
        $this->assertFalse($row['canAppeal']);
    }

    public function test_an_activity_counts_as_an_exam_through_either_flag_on_its_own(): void
    {
        $problem = $this->makeProblem();
        $viaExamination = $this->makeActivity($this->teacher, ['examination_id' => $this->makeExamination()->id, 'exam_mode' => false]);
        $viaMode = $this->makeActivity($this->teacher, ['exam_mode' => true]);
        $a = $this->makeSubmission($this->student, $problem, $viaExamination, ['evaluated_score' => 5, 'submitted_at' => now()->subMinute()]);
        $b = $this->makeSubmission($this->student, $problem, $viaMode, ['evaluated_score' => 5]);

        $rows = collect($this->list())->keyBy('id');

        $this->assertTrue($rows[$a->id]['canAppeal']);
        $this->assertTrue($rows[$b->id]['canAppeal']);
    }

    public function test_can_appeal_is_false_when_scores_are_hidden(): void
    {
        $this->examSubmission(['show_scores_after_submit' => false], ['evaluated_score' => 7]);

        $this->assertFalse($this->list()[0]['canAppeal']);
    }
}
