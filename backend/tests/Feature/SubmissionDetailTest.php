<?php

namespace Tests\Feature;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\ExamClassProctor;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\TestCase as ProblemTestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GradingFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả GET /api/submissions/{id} (show), GET /api/submissions/activity/{a}/students/{s}/problems/{p}
 * (studentWork) và POST /api/submissions/{id}/inspect: nội dung phản hồi cho sinh viên và giảng viên, che điểm,
 * và quyền xem theo phạm vi (chủ hoạt động, giảng viên thực hành đứng lớp, coi thi, người xem mọi kết quả).
 *
 * Ma trận quyền theo capability đã có ở GradingPermissionTest; ở đây là phần dữ liệu và phạm vi.
 */
class SubmissionDetailTest extends TestCase
{
    use DatabaseTransactions;
    use GradingFixtures;
    use UsesDefaultRolePermissions;

    protected User $teacher;
    protected User $student;
    protected Problem $problem;
    protected Assignment $activity;
    protected Submission $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        $this->teacher = $this->makeAccount('instructor');
        $this->student = $this->makeStudent();
        $this->problem = $this->makeProblem(['title' => 'Bài chi tiết']);
        $this->activity = $this->makeActivity($this->teacher, ['title' => 'Hoạt động chi tiết']);
        $this->attachProblem($this->activity, $this->problem, 8);
        $this->sub = $this->makeSubmission($this->student, $this->problem, $this->activity, ['score' => 4, 'query_text' => 'SELECT 42']);
    }

    private function show(User $as, ?Submission $sub = null)
    {
        Sanctum::actingAs($as);

        return $this->getJson('/api/submissions/' . ($sub ?? $this->sub)->id);
    }

    private function work(User $as, ?Assignment $activity = null, ?User $student = null, ?Problem $problem = null)
    {
        Sanctum::actingAs($as);
        $a = $activity ?? $this->activity;

        return $this->getJson("/api/submissions/activity/{$a->id}/students/" . ($student ?? $this->student)->id . '/problems/' . ($problem ?? $this->problem)->id);
    }

    private function solution(Problem $problem, string $name, string $query, int $order = 0, bool $enabled = true): ProblemSqlSolution
    {
        return ProblemSqlSolution::create(['problem_id' => $problem->id, 'name' => $name, 'query' => $query, 'enabled' => $enabled, 'order_index' => $order]);
    }

    // ══ show: hình dạng và quyền xem ═════════════════════════════════════════

    public function test_unknown_submission_is_404(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/submissions/khong-ton-tai')->assertStatus(404);
    }

    public function test_the_staff_response_has_a_stable_shape(): void
    {
        $json = $this->show($this->teacher)->assertOk()->json();

        $this->assertEqualsCanonicalizing([
            'id', 'studentId', 'student', 'studentEmail', 'problemId', 'problem', 'statement', 'referenceSolution',
            'studentQuery', 'query', 'status', 'isSubmitted', 'attempts', 'score', 'autoScore', 'maxScore',
            'evaluatedScore', 'finalScore', 'feedback', 'submittedAt', 'database', 'source', 'context', 'activityId',
            'activityTitle', 'isExam', 'examinationId', 'canEditReview', 'regradeAssignment', 'workspaceParts',
            'sections', 'reviewSections', 'attemptHistory', 'examEvents', 'departureCount', 'schemaName',
            'questionMaxScores', 'appealStatus', 'appealReason', 'appealCreatedAt', 'appealResolvedAt',
            'previousEvaluatedScore',
        ], array_keys($json));
        $this->assertSame($this->sub->id, $json['id']);
        $this->assertSame($this->student->id, $json['studentId']);
        $this->assertSame($this->student->name, $json['student']);
        $this->assertSame($this->student->email, $json['studentEmail']);
        $this->assertSame('Bài chi tiết', $json['problem']);
        $this->assertSame('Đề bài', $json['statement']);
        $this->assertSame('SELECT 42', $json['studentQuery']);
        $this->assertSame('SELECT 42', $json['query']);
        $this->assertTrue($json['isSubmitted']);
        $this->assertSame('Assignments', $json['source']);
        $this->assertSame($this->activity->id, $json['context']);
        $this->assertSame($this->activity->id, $json['activityId']);
        $this->assertSame('Hoạt động chi tiết', $json['activityTitle']);
        $this->assertEquals(8, $json['maxScore']);
        $this->assertEquals(4, $json['autoScore']);
        $this->assertEquals(4, $json['score']);
        $this->assertNull($json['finalScore']);
        $this->assertSame('SQL Server', $json['database']);
        $this->assertSame(['SQL'], $json['workspaceParts']);
        $this->assertNull($json['schemaName']);
        $this->assertFalse($json['isExam']);
        $this->assertNull($json['examinationId']);
        $this->assertNull($json['regradeAssignment']);
        $this->assertNull($json['previousEvaluatedScore']);
        $this->assertSame('{}', json_encode($this->show($this->teacher)->baseResponse->getData()->questionMaxScores));
    }

    public function test_max_score_is_100_without_an_assignment_row_and_the_context_is_practice(): void
    {
        $author = $this->makeAccount('instructor');
        $problem = $this->makeProblem(['creator_id' => $author->id]);
        $practice = $this->makeSubmission($this->student, $problem);

        $json = $this->show($author, $practice)->assertOk()->json();

        $this->assertEquals(100, $json['maxScore']);
        $this->assertSame('Practice', $json['context']);
        $this->assertNull($json['activityId']);
        $this->assertNull($json['activityTitle']);
        $this->assertFalse($json['isExam']);
    }

    public function test_exam_flags_follow_the_activity_or_the_source(): void
    {
        $session = $this->makeExamSession(['instructor_id' => $this->teacher->id]);
        $problem = $this->makeProblem();
        $this->attachProblem($session, $problem);
        $viaSession = $this->makeSubmission($this->student, $problem, $session, ['source' => 'ExamSession']);
        $manager = $this->makeAccount('faculty_office', ['examination.manage']);

        $json = $this->show($manager, $viaSession)->assertOk()->json();

        $this->assertTrue($json['isExam']);
        $this->assertSame($session->examination_id, $json['examinationId']);

        $examMode = $this->makeActivity($this->teacher, ['exam_mode' => true]);
        $this->attachProblem($examMode, $problem);
        $sub = $this->makeSubmission($this->student, $problem, $examMode);
        $this->assertTrue($this->show($manager, $sub)->json('isExam'));
        $this->assertNull($this->show($manager, $sub)->json('examinationId'));
    }

    public function test_a_student_sees_only_their_own_submission_and_a_classmate_does_not(): void
    {
        $this->show($this->student)->assertOk();
        $this->show($this->makeStudent())->assertStatus(403)->assertJsonPath('detail', 'Bạn không có quyền xem bài nộp này.');
    }

    public function test_the_student_view_hides_the_staff_only_parts(): void
    {
        $this->solution($this->problem, 'q1', 'SELECT 1');
        $this->sub->update(['evaluated_score' => 6, 'feedback' => 'Làm tốt']);
        ActivityAttempt::create(['assignment_id' => $this->activity->id, 'user_id' => $this->student->id, 'status' => 'submitted', 'drafts' => [], 'started_at' => now(), 'violations' => [['kind' => 'focus_lost']]]);

        $json = $this->show($this->student)->assertOk()->json();

        $this->assertSame('', $json['referenceSolution']);
        $this->assertSame([], $json['reviewSections']);
        $this->assertSame([], $json['attemptHistory']);
        $this->assertSame([], $json['examEvents']);
        $this->assertSame(0, $json['departureCount']);
        $this->assertFalse($json['canEditReview']);
        // Điểm hiển thị bình thường khi hoạt động công bố điểm.
        $this->assertSame('Graded', $json['status']);
        $this->assertEquals(6, $json['finalScore']);
        $this->assertSame('Làm tốt', $json['feedback']);
    }

    public function test_scores_are_masked_for_the_student_but_not_for_staff_while_hidden(): void
    {
        $this->activity->update(['show_scores_after_submit' => false]);
        $this->sub->update(['evaluated_score' => 6, 'feedback' => 'Riêng tư', 'score' => 4]);

        $student = $this->show($this->student)->assertOk()->json();
        $this->assertSame('Submitted', $student['status']);
        $this->assertNull($student['autoScore']);
        $this->assertEquals(0, $student['score']);
        $this->assertNull($student['finalScore']);
        $this->assertNull($student['evaluatedScore']);
        $this->assertNull($student['feedback']);

        $staff = $this->show($this->teacher)->assertOk()->json();
        $this->assertSame('Graded', $staff['status']);
        $this->assertEquals(4, $staff['autoScore']);
        $this->assertEquals(6, $staff['finalScore']);
        $this->assertSame('Riêng tư', $staff['feedback']);
    }

    public function test_hidden_scores_do_not_leak_per_question_results_to_the_student(): void
    {
        $this->activity->update(['show_scores_after_submit' => false]);
        $this->withSections($this->sub, [[
            'part' => 'SQL', 'status' => 'Accepted', 'message' => '1/1 câu đúng.',
            'questions' => [['id' => 'q1', 'status' => 'Accepted', 'comparisons' => [['expectedTable' => ['rows' => [[1]]]]]]],
        ]]);

        $student = $this->show($this->student)->assertOk()->json();
        $staff = $this->show($this->teacher)->assertOk()->json();

        $this->assertSame([], $student['sections']);
        $this->assertSame('Accepted', $staff['sections'][0]['status']);
    }

    public function test_visible_scores_show_the_saved_sections_to_the_student(): void
    {
        $this->withSections($this->sub, [['part' => 'SQL', 'status' => 'Accepted', 'questions' => []]]);

        $this->assertSame('Accepted', $this->show($this->student)->json('sections.0.status'));
    }

    public function test_review_status_values(): void
    {
        $this->assertSame('Needs review', $this->show($this->teacher)->json('status'));

        $this->sub->update(['evaluated_score' => 5]);
        $this->assertSame('Graded', $this->show($this->teacher)->json('status'));

        $regrade = SubmissionRegradeAssignment::create(['submission_id' => $this->sub->id, 'assigned_instructor_id' => $this->teacher->id, 'assigned_at' => now()]);
        $this->assertSame('Awaiting regrade', $this->show($this->teacher)->json('status'));

        $regrade->update(['completed_at' => now()]);
        $this->assertSame('Regraded', $this->show($this->teacher)->json('status'));
    }

    public function test_attempt_history_lists_official_attempts_oldest_first(): void
    {
        $base = now()->subDays(5)->startOfSecond();
        $this->sub->update(['submitted_at' => $base->copy()->addHours(3)]);
        $this->questionOnly($this->makeSubmission($this->student, $this->problem, $this->activity, ['submitted_at' => $base->copy()->addHours(9)]));
        $made = [];
        foreach ([5, 1, 4, 2] as $h) {
            $made[$h] = $this->makeSubmission($this->student, $this->problem, $this->activity, [
                'submitted_at' => $base->copy()->addHours($h), 'score' => $h, 'evaluated_score' => $h === 4 ? 7 : null, 'result' => "R{$h}",
            ]);
        }
        // Bài của sinh viên khác, của hoạt động khác và nguồn khác không tính.
        $this->makeSubmission($this->makeStudent(), $this->problem, $this->activity, ['submitted_at' => $base]);
        $this->makeSubmission($this->student, $this->problem, $this->makeActivity($this->teacher), ['submitted_at' => $base]);
        $this->makeSubmission($this->student, $this->problem, $this->activity, ['source' => 'ExamSession', 'submitted_at' => $base]);

        $json = $this->show($this->teacher, $made[5])->assertOk()->json();

        $this->assertSame('5', $json['attempts']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($json['attemptHistory'], 'number'));
        $this->assertSame(
            [$made[1]->id, $made[2]->id, $this->sub->id, $made[4]->id, $made[5]->id],
            array_column($json['attemptHistory'], 'id')
        );
        $row = $json['attemptHistory'][3];
        $this->assertEquals(4, $row['autoScore']);
        $this->assertEquals(7, $row['finalScore']);
        $this->assertSame('R4', $row['status']);
        $this->assertNotNull($row['submittedAt']);
        $this->assertNull($json['attemptHistory'][0]['finalScore']);
    }

    public function test_exam_events_and_departures_come_from_the_attempt_for_staff(): void
    {
        ActivityAttempt::create([
            'assignment_id' => $this->activity->id, 'user_id' => $this->student->id, 'status' => 'submitted', 'drafts' => [], 'started_at' => now(),
            'violations' => [['kind' => 'focus_lost', 'counted' => true], ['kind' => 'focus_lost'], ['kind' => 'external_paste'], 'rac'],
        ]);

        $json = $this->show($this->teacher)->assertOk()->json();

        $this->assertCount(4, $json['examEvents']);
        $this->assertSame(2, $json['departureCount']);
    }

    public function test_the_reference_solution_lists_enabled_sql_solutions_in_order(): void
    {
        $this->solution($this->problem, 'tuyển chọn', 'SELECT 2', 1);
        $this->solution($this->problem, 'đầu tiên', "  SELECT 1  ", 0);
        $this->solution($this->problem, 'tắt', 'SELECT 3', 2, false);
        $this->solution($this->problem, 'rỗng', '   ', 3);

        $this->assertSame("-- [SQL] đầu tiên\nSELECT 1\n\n-- [SQL] tuyển chọn\nSELECT 2", $this->show($this->teacher)->json('referenceSolution'));
    }

    public function test_the_reference_solution_falls_back_to_the_first_test_cases_expected_query(): void
    {
        ProblemTestCase::create(['problem_id' => $this->problem->id, 'is_hidden' => false, 'order_index' => 2, 'expected_query' => 'SELECT later']);
        ProblemTestCase::create(['problem_id' => $this->problem->id, 'is_hidden' => false, 'order_index' => 1, 'expected_query' => 'SELECT first']);

        $this->assertSame('SELECT first', $this->show($this->teacher)->json('referenceSolution'));
    }

    public function test_the_reference_solution_includes_stage_answers_for_non_sql_parts(): void
    {
        $problem = $this->makeProblem(['workspace_parts' => ['DDL', 'SQL']]);
        ProblemStageExercise::create(['problem_id' => $problem->id, 'part' => 'DDL', 'config' => ['questions' => [
            ['id' => 'd1', 'name' => 'tao_bang.sql', 'answer' => 'CREATE TABLE a(x INT)'],
            ['id' => 'd2', 'answer' => '   '],
            ['id' => 'd3', 'answer' => 'CREATE TABLE b(y INT)'],
        ]]]);
        $this->solution($problem, 'truy vấn', 'SELECT 1');
        $activity = $this->makeActivity($this->teacher);
        $this->attachProblem($activity, $problem);
        $sub = $this->makeSubmission($this->student, $problem, $activity);

        $text = $this->show($this->teacher, $sub)->json('referenceSolution');

        $this->assertSame("-- [DDL] tao_bang.sql\nCREATE TABLE a(x INT)\n\n-- [DDL] DDL.sql\nCREATE TABLE b(y INT)\n\n-- [SQL] truy vấn\nSELECT 1", $text);
    }

    public function test_the_member_workspace_parts_override_the_problems(): void
    {
        $problem = $this->makeProblem(['workspace_parts' => ['SQL', 'DDL']]);
        $activity = $this->makeActivity($this->teacher);
        $this->attachProblem($activity, $problem, 10, 1, ['DML']);
        $sub = $this->makeSubmission($this->student, $problem, $activity);

        $this->assertSame(['DML'], $this->show($this->teacher, $sub)->json('workspaceParts'));
    }

    public function test_regrade_and_appeal_details_are_reported(): void
    {
        $reviewer = $this->makeAccount('instructor', ['results.grade']);
        $this->sub->update(['evaluated_score' => 6]);
        SubmissionAppeal::create([
            'submission_id' => $this->sub->id, 'student_id' => $this->student->id, 'reason' => 'Xin xem lại', 'status' => 'pending',
            'previous_evaluated_score' => 6, 'created_at' => now()->subHour(),
        ]);

        $json = $this->show($this->teacher)->assertOk()->json();
        $this->assertSame('pending', $json['appealStatus']);
        $this->assertSame('Xin xem lại', $json['appealReason']);
        $this->assertNotNull($json['appealCreatedAt']);
        $this->assertNull($json['appealResolvedAt']);
        $this->assertEquals(6, $json['previousEvaluatedScore']);

        SubmissionRegradeAssignment::create([
            'submission_id' => $this->sub->id, 'assigned_instructor_id' => $reviewer->id, 'assigned_by_id' => $this->teacher->id,
            'note' => 'Xem kỹ câu 2', 'previous_evaluated_score' => 5, 'previous_feedback' => 'cũ', 'assigned_at' => now(),
        ]);
        $json = $this->show($this->teacher)->assertOk()->json();
        $this->assertSame($reviewer->id, $json['regradeAssignment']['reviewerId']);
        $this->assertSame($reviewer->name, $json['regradeAssignment']['reviewerName']);
        $this->assertSame('Xem kỹ câu 2', $json['regradeAssignment']['note']);
        $this->assertEquals(5, $json['regradeAssignment']['previousEvaluatedScore']);
        $this->assertSame('cũ', $json['regradeAssignment']['previousFeedback']);
        $this->assertNotNull($json['regradeAssignment']['assignedAt']);
        $this->assertNull($json['regradeAssignment']['completedAt']);
        // Điểm trước của đợt chấm lại được ưu tiên hơn điểm trước của đơn phúc khảo.
        $this->assertEquals(5, $json['previousEvaluatedScore']);
    }

    public function test_can_edit_review_depends_on_grading_scope_and_regrade_assignment(): void
    {
        $this->assertTrue($this->show($this->teacher)->json('canEditReview'));
        $this->assertFalse($this->show($this->makeAccount('instructor', ['results.view_all']))->json('canEditReview'));

        $reviewer = $this->makeAccount('instructor', ['results.grade']);
        SubmissionRegradeAssignment::create(['submission_id' => $this->sub->id, 'assigned_instructor_id' => $reviewer->id, 'assigned_at' => now()]);

        // Đã giao cho người khác chấm lại thì chủ hoạt động không còn sửa được; người được giao thì sửa được.
        $this->assertFalse($this->show($this->teacher)->json('canEditReview'));
        $this->assertTrue($this->show($reviewer)->assertOk()->json('canEditReview'));
    }

    public function test_unknown_student_and_problem_use_placeholders(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('problems')->where('id', $this->problem->id)->delete();
        DB::table('users')->where('id', $this->student->id)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $manager = $this->makeAccount('faculty_office', ['results.view_all']);

        $json = $this->show($manager)->assertOk()->json();

        $this->assertSame('Unknown Student', $json['student']);
        $this->assertSame('', $json['studentEmail']);
        $this->assertSame('Unknown Problem', $json['problem']);
        $this->assertSame('', $json['statement']);
        $this->assertSame('', $json['referenceSolution']);
        $this->assertSame([], $json['reviewSections']);
    }

    // ══ show: phạm vi xem của nhân sự ════════════════════════════════════════

    public function test_staff_scope_for_a_regular_activity(): void
    {
        $other = $this->makeAccount('instructor');
        $classTeacher = $this->makeAccount('instructor');
        $proctor = $this->makeAccount('instructor', ['exam.proctor']);
        $class = $this->makeClass(['instructor_id' => $classTeacher->id]);
        $this->linkClass($this->activity, $class);
        ExamClassProctor::create(['session_id' => $this->activity->id, 'class_id' => $class->id, 'proctor_id' => $proctor->id, 'assigned_at' => now()]);

        $this->show($this->teacher)->assertOk();
        $this->show($classTeacher)->assertOk();
        $this->show($proctor)->assertOk();
        $this->show($other)->assertStatus(403)->assertJsonPath('detail', 'Bạn không có quyền xem bài nộp này.');
        $this->show($this->makeAccount('instructor', ['results.view_all']))->assertOk();
        $this->show($this->makeAccount('faculty_office', ['examination.manage']))->assertOk();
    }

    public function test_teaching_a_class_through_each_instructor_column_grants_access(): void
    {
        $viaPractice = $this->makeAccount('instructor');
        $viaPivot = $this->makeAccount('instructor');
        $class = $this->makeClass(['practice_instructor_id' => $viaPractice->id]);
        $this->linkClass($this->activity, $class);
        DB::table('class_instructors')->insert(['class_id' => $class->id, 'instructor_id' => $viaPivot->id, 'assignment_type' => 'theory']);

        $this->show($viaPractice)->assertOk();
        $this->show($viaPivot)->assertOk();
    }

    public function test_exam_submissions_are_visible_only_to_practical_instructors_of_the_students_class(): void
    {
        $session = $this->makeExamSession(['instructor_id' => $this->teacher->id]);
        $problem = $this->makeProblem();
        $this->attachProblem($session, $problem);
        $sub = $this->makeSubmission($this->student, $problem, $session, ['source' => 'ExamSession']);
        $class = $this->makeClass();
        $this->enroll($class, $this->student);
        $this->linkClass($session, $class);
        $practical = $this->makeAccount('instructor');
        $viaPivot = $this->makeAccount('instructor');
        $theory = $this->makeAccount('instructor');
        $class->update(['practice_instructor_id' => $practical->id]);
        DB::table('class_instructors')->insert(['class_id' => $class->id, 'instructor_id' => $viaPivot->id, 'assignment_type' => 'practical']);
        DB::table('class_instructors')->insert(['class_id' => $class->id, 'instructor_id' => $theory->id, 'assignment_type' => 'theory']);
        $outsider = $this->makeAccount('instructor');
        $otherClass = $this->makeClass(['practice_instructor_id' => $outsider->id]);
        $this->enroll($otherClass, $this->makeStudent());

        $this->show($practical, $sub)->assertOk();
        $this->show($viaPivot, $sub)->assertOk();
        // Chủ ca thi, giảng viên lý thuyết và giảng viên lớp khác đều không xem được bài thi.
        $this->show($this->teacher, $sub)->assertStatus(403);
        $this->show($theory, $sub)->assertStatus(403);
        $this->show($outsider, $sub)->assertStatus(403);
        // Người xem mọi kết quả thì xem được.
        $this->show($this->makeAccount('instructor', ['results.view_all']), $sub)->assertOk();
    }

    public function test_an_activity_is_an_exam_through_either_flag_on_its_own(): void
    {
        $practical = $this->makeAccount('instructor');
        $class = $this->makeClass(['practice_instructor_id' => $practical->id]);
        $this->enroll($class, $this->student);

        foreach ([['examination_id' => $this->makeExamination()->id, 'exam_mode' => false], ['exam_mode' => true]] as $flags) {
            $activity = $this->makeActivity($this->teacher, $flags);
            $this->attachProblem($activity, $this->problem);
            $this->linkClass($activity, $class);
            $sub = $this->makeSubmission($this->student, $this->problem, $activity);

            // Chủ hoạt động không xem được vì đây là bài thi; giảng viên thực hành thì xem được.
            $this->show($this->teacher, $sub)->assertStatus(403);
            $this->show($practical, $sub)->assertOk()->assertJsonPath('isExam', true);
        }
    }

    public function test_the_practical_instructor_must_teach_a_class_the_student_is_enrolled_in(): void
    {
        $session = $this->makeExamSession();
        $problem = $this->makeProblem();
        $sub = $this->makeSubmission($this->student, $problem, $session, ['source' => 'ExamSession']);
        $teacher = $this->makeAccount('instructor');
        $class = $this->makeClass(['practice_instructor_id' => $teacher->id]);
        $this->linkClass($session, $class);
        $this->enroll($class, $this->makeStudent());

        // Dạy lớp của ca nhưng sinh viên này không ghi danh lớp đó.
        $this->show($teacher, $sub)->assertStatus(403);
        $this->enroll($class, $this->student);
        $this->show($teacher, $sub)->assertOk();
    }

    public function test_the_practical_instructor_scope_is_limited_to_the_sessions_classes(): void
    {
        $session = $this->makeExamSession();
        $problem = $this->makeProblem();
        $sub = $this->makeSubmission($this->student, $problem, $session, ['source' => 'ExamSession']);
        $inSession = $this->makeClass();
        $notInSession = $this->makeClass();
        $this->linkClass($session, $inSession);
        $this->enroll($notInSession, $this->student);
        $teacher = $this->makeAccount('instructor');
        $notInSession->update(['practice_instructor_id' => $teacher->id]);

        // Dạy lớp của sinh viên nhưng lớp đó không thuộc ca thi.
        $this->show($teacher, $sub)->assertStatus(403);

        // Ca chưa gán lớp nào: tính theo mọi lớp thực hành của giảng viên.
        $bare = $this->makeExamSession();
        $bareSub = $this->makeSubmission($this->student, $problem, $bare, ['source' => 'ExamSession']);
        $this->show($teacher, $bareSub)->assertOk();
    }

    public function test_practice_submissions_are_visible_to_the_problem_author_only(): void
    {
        $author = $this->makeAccount('instructor');
        $other = $this->makeAccount('instructor');
        $practice = $this->makeSubmission($this->student, $this->makeProblem(['creator_id' => $author->id]));

        $this->show($author, $practice)->assertOk();
        $this->show($other, $practice)->assertStatus(403);
        $this->show($this->student, $practice)->assertOk();
    }

    public function test_a_deleted_activity_hides_the_submission_from_non_privileged_staff(): void
    {
        $sub = $this->makeSubmission($this->student, $this->problem, $this->activity, ['context' => 'hoat-dong-da-xoa']);

        $this->show($this->teacher, $sub)->assertStatus(403);
        $this->show($this->student, $sub)->assertOk();
    }

    public function test_a_regrade_reviewer_may_read_sibling_attempts_but_not_grade_them(): void
    {
        $reviewer = $this->makeAccount('instructor', ['results.grade']);
        $first = $this->sub;
        $second = $this->makeSubmission($this->student, $this->problem, $this->activity, ['submitted_at' => now()->addMinute()]);
        SubmissionRegradeAssignment::create(['submission_id' => $second->id, 'assigned_instructor_id' => $reviewer->id, 'assigned_at' => now()]);

        $this->show($reviewer, $first)->assertOk()->assertJsonPath('canEditReview', false);
        Sanctum::actingAs($reviewer);
        $this->putJson("/api/submissions/{$first->id}/review", ['finalScore' => 1])->assertStatus(403);
        // Bài của chính đợt chấm lại thì chấm được.
        $this->putJson("/api/submissions/{$second->id}/review", ['finalScore' => 1])->assertOk();
    }

    // ══ studentWork ═════════════════════════════════════════════════════════

    public function test_student_work_requires_staff(): void
    {
        $this->work($this->student)->assertStatus(403)->assertJsonPath('detail', 'Only instructors can view student work.');
    }

    public function test_student_work_requires_existing_activity_and_member_problem(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/submissions/activity/khong-co/students/{$this->student->id}/problems/{$this->problem->id}")->assertStatus(404);
        $this->work($this->teacher, null, null, $this->makeProblem())->assertStatus(404);
    }

    public function test_student_work_returns_the_latest_official_submission(): void
    {
        $base = now()->subDays(3)->startOfSecond();
        $this->sub->update(['submitted_at' => $base]);
        $latest = $this->makeSubmission($this->student, $this->problem, $this->activity, ['submitted_at' => $base->copy()->addHours(5), 'query_text' => 'SELECT latest']);
        $this->questionOnly($this->makeSubmission($this->student, $this->problem, $this->activity, ['submitted_at' => $base->copy()->addHours(9)]));
        $this->makeSubmission($this->student, $this->problem, $this->activity, ['source' => 'Practice', 'submitted_at' => $base->copy()->addHours(20)]);
        $this->makeSubmission($this->makeStudent(), $this->problem, $this->activity, ['submitted_at' => $base->copy()->addHours(30)]);

        $json = $this->work($this->teacher)->assertOk()->json();

        $this->assertSame($latest->id, $json['id']);
        $this->assertSame('SELECT latest', $json['studentQuery']);
        $this->assertTrue($json['isSubmitted']);
    }

    public function test_student_work_for_a_student_who_has_not_submitted(): void
    {
        $this->sub->delete();
        $this->solution($this->problem, 'q1', 'SELECT ref');

        $none = $this->work($this->teacher)->assertOk()->json();

        $this->assertSame("unsubmitted:{$this->activity->id}:{$this->student->id}:{$this->problem->id}", $none['id']);
        $this->assertSame($this->student->id, $none['studentId']);
        $this->assertSame($this->student->name, $none['student']);
        $this->assertSame($this->student->email, $none['studentEmail']);
        $this->assertSame($this->problem->id, $none['problemId']);
        $this->assertSame('Bài chi tiết', $none['problem']);
        $this->assertSame('Đề bài', $none['statement']);
        $this->assertSame('-- [SQL] q1' . "\n" . 'SELECT ref', $none['referenceSolution']);
        $this->assertSame('', $none['studentQuery']);
        $this->assertSame('Not submitted', $none['status']);
        $this->assertFalse($none['isSubmitted']);
        $this->assertEquals(0, $none['score']);
        $this->assertEquals(0, $none['autoScore']);
        $this->assertEquals(8, $none['maxScore']);
        $this->assertNull($none['finalScore']);
        $this->assertNull($none['evaluatedScore']);
        $this->assertNull($none['feedback']);
        $this->assertSame('', $none['submittedAt']);
        $this->assertSame('SQL Server', $none['database']);
        $this->assertSame('Assignments', $none['source']);
        $this->assertSame($this->activity->id, $none['context']);
        $this->assertSame($this->activity->id, $none['activityId']);
        $this->assertSame('Hoạt động chi tiết', $none['activityTitle']);
        $this->assertSame(['SQL'], $none['workspaceParts']);
        $this->assertSame([], $none['attemptHistory']);
        $this->assertSame([], $none['examEvents']);
        $this->assertSame(0, $none['departureCount']);
        $this->assertNull($none['schemaName']);
        $this->assertNull($none['appealStatus']);
        $this->assertNotEmpty($none['reviewSections']);
        $this->assertSame($none['sections'], $none['reviewSections']);
    }

    public function test_student_work_reflects_the_attempt_state(): void
    {
        $this->sub->delete();
        $attempt = ActivityAttempt::create([
            'assignment_id' => $this->activity->id, 'user_id' => $this->student->id, 'status' => 'active',
            'drafts' => [], 'started_at' => now(), 'violations' => [['kind' => 'focus_lost'], ['kind' => 'other']],
        ]);

        $json = $this->work($this->teacher)->assertOk()->json();
        $this->assertSame('Draft saved', $json['status']);
        $this->assertSame('', $json['studentQuery']);
        $this->assertCount(2, $json['examEvents']);
        $this->assertSame(1, $json['departureCount']);

        $attempt->update(['drafts' => [$this->problem->id => ['query' => 'SELECT draft', 'database' => 'MySQL']]]);
        $json = $this->work($this->teacher)->assertOk()->json();
        $this->assertSame(['query' => 'SELECT draft', 'database' => 'MySQL'], json_decode($json['studentQuery'], true));
        $this->assertSame('MySQL', $json['database']);

        $attempt->update(['status' => 'submitted', 'submitted_at' => now()->startOfSecond(), 'drafts' => []]);
        $json = $this->work($this->teacher)->assertOk()->json();
        $this->assertSame('Submitted (empty)', $json['status']);
        $saved = ActivityAttempt::where('assignment_id', $this->activity->id)->where('user_id', $this->student->id)->first();
        $this->assertSame($saved->submitted_at->toISOString(), $json['submittedAt']);
    }

    public function test_student_work_for_an_exam_session_reports_the_exam_source(): void
    {
        $session = $this->makeExamSession(['instructor_id' => $this->teacher->id]);
        $this->attachProblem($session, $this->problem);
        $manager = $this->makeAccount('faculty_office', ['examination.manage']);

        $this->assertSame('ExamSession', $this->work($manager, $session)->assertOk()->json('source'));
    }

    public function test_student_work_unknown_student_is_404(): void
    {
        $this->sub->delete();
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/submissions/activity/{$this->activity->id}/students/khong-co/problems/{$this->problem->id}")->assertStatus(404);
    }

    public function test_student_work_scope_for_regular_activities(): void
    {
        $other = $this->makeAccount('instructor');
        $classTeacher = $this->makeAccount('instructor');
        $proctor = $this->makeAccount('instructor', ['exam.proctor']);
        $class = $this->makeClass(['instructor_id' => $classTeacher->id]);
        $this->linkClass($this->activity, $class);
        ExamClassProctor::create(['session_id' => $this->activity->id, 'class_id' => $class->id, 'proctor_id' => $proctor->id, 'assigned_at' => now()]);

        $this->work($this->teacher)->assertOk();
        $this->work($classTeacher)->assertOk();
        $this->work($proctor)->assertOk();
        $this->work($other)->assertStatus(403)->assertJsonPath('detail', 'Bạn không có quyền xem bài làm của hoạt động này.');
        $this->work($this->makeAccount('instructor', ['results.view_all']))->assertOk();
    }

    public function test_student_work_scope_for_exam_sessions(): void
    {
        $session = $this->makeExamSession(['instructor_id' => $this->teacher->id]);
        $this->attachProblem($session, $this->problem);
        $class = $this->makeClass();
        $this->enroll($class, $this->student);
        $this->linkClass($session, $class);
        $practical = $this->makeAccount('instructor');
        $class->update(['practice_instructor_id' => $practical->id]);

        $this->work($practical, $session)->assertOk();
        $this->work($this->teacher, $session)->assertStatus(403)
            ->assertJsonPath('detail', 'Chỉ giảng viên thực hành đứng lớp của sinh viên mới có quyền xem bài thi này.');
        $this->work($this->makeAccount('faculty_office', ['examination.manage']), $session)->assertOk();
    }

    // ══ inspect ═════════════════════════════════════════════════════════════

    private function inspect(User $as, array $body, ?Submission $sub = null)
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/submissions/' . ($sub ?? $this->sub)->id . '/inspect', $body);
    }

    public function test_inspect_access_and_not_found_cases(): void
    {
        $solution = $this->solution($this->problem, 'q1', 'SELECT 1 AS x');

        $this->inspect($this->student, ['questionId' => $solution->id])->assertStatus(403)
            ->assertJsonPath('detail', 'Chỉ Giảng viên và Admin mới có quyền đối chiếu bài nộp.');
        $this->inspect($this->makeAccount('instructor'), ['questionId' => $solution->id])->assertStatus(403);
        $this->inspect($this->teacher, ['questionId' => 'khong-co'])->assertStatus(404)->assertJsonPath('detail', 'Question not found in this submission');
        $this->inspect($this->teacher, [])->assertStatus(404);
        $this->inspect($this->teacher, ['questionId' => $solution->id, 'part' => 'DDL'])->assertStatus(404);
        $this->postJson('/api/submissions/khong-co/inspect', [])->assertStatus(404);
    }

    public function test_inspect_reports_an_unanswered_question(): void
    {
        $solution = $this->solution($this->problem, 'q1', 'SELECT 1 AS x');
        $this->sub->update(['query_text' => '']);

        $this->inspect($this->teacher, ['questionId' => $solution->id])->assertOk()
            ->assertJsonPath('status', 'Not answered')->assertJsonPath('datasets', []);
    }

    public function test_inspect_accepts_the_snake_case_question_id_and_lowercase_part(): void
    {
        $solution = $this->solution($this->problem, 'q1', 'SELECT 1 AS x');
        $this->sub->update(['query_text' => '']);

        $this->inspect($this->teacher, ['question_id' => $solution->id, 'part' => 'sql'])->assertOk()->assertJsonPath('status', 'Not answered');
    }

    public function test_an_exam_source_submission_of_a_regular_activity_follows_the_exam_rule(): void
    {
        // Hoạt động không ở chế độ thi nhưng bài nộp có nguồn ExamSession: chủ hoạt động không xem được,
        // chỉ giảng viên thực hành đứng lớp của sinh viên.
        $sub = $this->makeSubmission($this->student, $this->problem, $this->activity, ['source' => 'ExamSession']);
        $class = $this->makeClass();
        $this->enroll($class, $this->student);
        $practical = $this->makeAccount('instructor');
        $class->update(['practice_instructor_id' => $practical->id]);

        $this->show($this->teacher, $sub)->assertStatus(403);
        $this->show($practical, $sub)->assertOk();
    }

    public function test_inspect_attributes_an_unmarked_script_to_the_first_question(): void
    {
        $solution = $this->solution($this->problem, 'q1', 'SELECT 1 AS x');
        ProblemTestCase::create(['problem_id' => $this->problem->id, 'is_hidden' => false, 'order_index' => 0]);
        $this->sub->update(['query_text' => 'SELECT 1 AS x']);

        $this->inspect($this->teacher, ['questionId' => $solution->id])->assertOk()->assertJsonPath('status', 'Accepted');
    }

    public function test_inspect_reports_a_missing_reference_solution(): void
    {
        $this->sub->update(['query_text' => "-- solution:__legacy_sql__\nSELECT 1 AS x"]);

        $this->inspect($this->teacher, ['questionId' => '__legacy_sql__'])->assertOk()
            ->assertJsonPath('status', 'Not available')
            ->assertJsonPath('message', 'No reference solution provided.');
    }

    public function test_inspect_compares_the_students_query_with_the_reference_on_sql_server(): void
    {
        $solution = $this->solution($this->problem, 'q1', 'SELECT 1 AS x');
        ProblemTestCase::create(['problem_id' => $this->problem->id, 'is_hidden' => false, 'order_index' => 0]);

        $this->sub->update(['query_text' => "-- solution:{$solution->id}\nSELECT 1 AS x"]);
        $this->inspect($this->teacher, ['questionId' => $solution->id])->assertOk()
            ->assertJsonPath('status', 'Accepted')
            ->assertJsonPath('datasets.0.matched', true)
            ->assertJsonPath('datasets.0.dataset', 1);

        $this->sub->update(['query_text' => "-- solution:{$solution->id}\nSELECT 2 AS x"]);
        $this->inspect($this->teacher, ['questionId' => $solution->id])->assertOk()
            ->assertJsonPath('status', 'Wrong Answer')
            ->assertJsonPath('datasets.0.matched', false);

        $this->sub->update(['query_text' => "-- solution:{$solution->id}\nSELEC nothing"]);
        $this->inspect($this->teacher, ['questionId' => $solution->id])->assertOk()
            ->assertJsonPath('status', 'Wrong Answer')
            ->assertJsonPath('datasets.0.runtimeError', true);
    }

    public function test_inspect_is_open_to_read_only_staff_in_scope(): void
    {
        $solution = $this->solution($this->problem, 'q1', 'SELECT 1 AS x');
        $viewer = $this->makeAccount('instructor', ['results.view_all']);
        $this->sub->update(['query_text' => '']);

        $this->inspect($viewer, ['questionId' => $solution->id])->assertOk()->assertJsonPath('status', 'Not answered');
    }
}
