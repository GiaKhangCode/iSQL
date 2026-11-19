<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Notification;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GradingFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả PUT /api/submissions/{id}/review (SubmissionController::review): chấm điểm, điểm từng câu,
 * giới hạn điểm, kết quả theo điểm, xử lý đơn phúc khảo / đợt chấm lại và thông báo cho sinh viên, người giao.
 *
 * Ma trận quyền theo capability nằm ở GradingPermissionTest.
 */
class SubmissionReviewTest extends TestCase
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
        $this->problem = $this->makeProblem(['title' => 'Bài chấm']);
        $this->activity = $this->makeActivity($this->teacher);
        $this->attachProblem($this->activity, $this->problem, 8);
        $this->sub = $this->makeSubmission($this->student, $this->problem, $this->activity, ['result' => 'Pending', 'score' => 3]);
        Sanctum::actingAs($this->teacher);
    }

    private function review(array $body, ?Submission $sub = null)
    {
        return $this->putJson('/api/submissions/' . ($sub ?? $this->sub)->id . '/review', $body);
    }

    private function notificationsOf(User $user)
    {
        return Notification::where('user_id', $user->id)->orderBy('created_at')->get();
    }

    // ══ truy cập ════════════════════════════════════════════════════════════

    public function test_unknown_submission_is_404_and_a_non_grader_is_rejected_before_any_scope_check(): void
    {
        $this->putJson('/api/submissions/khong-co/review', ['finalScore' => 1])->assertStatus(404);

        Sanctum::actingAs($this->makeAccount('instructor', ['results.view_all']));
        $this->review(['finalScore' => 1])->assertStatus(403)->assertJsonPath('detail', 'Bạn không có quyền chấm bài.');

        Sanctum::actingAs($this->makeAccount('instructor', ['results.grade']));
        $this->review(['finalScore' => 1])->assertStatus(403)->assertJsonPath('detail', 'Bạn không có quyền chấm bài nộp này.');
        $this->assertNull($this->sub->fresh()->evaluated_score);
    }

    public function test_a_practice_submission_can_only_be_graded_by_the_problem_author(): void
    {
        $author = $this->makeAccount('instructor', ['results.grade']);
        $practice = $this->makeSubmission($this->student, $this->makeProblem(['creator_id' => $author->id]));

        $this->review(['finalScore' => 5], $practice)->assertStatus(403);
        Sanctum::actingAs($author);
        $this->review(['finalScore' => 5], $practice)->assertOk();
        $this->assertEquals(5, $practice->fresh()->evaluated_score);
    }

    // ══ điểm số ═════════════════════════════════════════════════════════════

    public function test_score_aliases_are_all_accepted(): void
    {
        foreach (['finalScore', 'final_score', 'evaluatedScore', 'evaluated_score'] as $i => $key) {
            $this->review([$key => $i + 1])->assertOk()->assertJsonPath('finalScore', $i + 1);
            $this->assertEquals($i + 1, $this->sub->fresh()->evaluated_score, $key);
        }
    }

    public function test_the_score_is_rounded_and_the_result_follows_it(): void
    {
        $this->review(['finalScore' => 5.126])->assertOk();
        $this->assertEquals(5.13, $this->sub->fresh()->evaluated_score);
        $this->assertSame('Partial', $this->sub->fresh()->result);

        $this->review(['finalScore' => 0])->assertOk();
        $this->assertEquals(0, $this->sub->fresh()->evaluated_score);
        $this->assertSame('Rejected', $this->sub->fresh()->result);

        $this->review(['finalScore' => 8])->assertOk();
        $this->assertSame('Accepted', $this->sub->fresh()->result);

        $this->review(['finalScore' => 7.99])->assertOk();
        $this->assertSame('Partial', $this->sub->fresh()->result);

        $this->review(['finalScore' => '6.5'])->assertOk();
        $this->assertEquals(6.5, $this->sub->fresh()->evaluated_score);
    }

    public function test_the_range_and_result_use_the_rounded_score(): void
    {
        // 7.999 làm tròn thành 8.00 = điểm tối đa: hợp lệ và là Accepted.
        $this->review(['finalScore' => 7.999])->assertOk();
        $this->assertEquals(8, $this->sub->fresh()->evaluated_score);
        $this->assertSame('Accepted', $this->sub->fresh()->result);

        // 8.004 làm tròn thành 8.00 nên không vượt tối đa.
        $this->review(['finalScore' => 8.004])->assertOk();
        // -0.004 làm tròn thành 0 (không âm).
        $this->review(['finalScore' => -0.004])->assertOk();
        $this->assertSame('Rejected', $this->sub->fresh()->result);
    }

    public function test_scores_outside_the_range_or_not_numeric_are_rejected(): void
    {
        $this->review(['finalScore' => 8.01])->assertStatus(422)->assertJsonPath('detail', 'Điểm số không hợp lệ. Vui lòng nhập từ 0 đến 8.');
        $this->review(['finalScore' => -0.5])->assertStatus(422);
        $this->review(['finalScore' => 'abc'])->assertStatus(422)->assertJsonPath('detail', 'Điểm số không hợp lệ.');
        $this->review(['finalScore' => [1]])->assertStatus(422);

        $this->assertNull($this->sub->fresh()->evaluated_score);
        $this->assertSame('Pending', $this->sub->fresh()->result);
        $this->assertSame(0, Notification::where('user_id', $this->student->id)->count());
    }

    public function test_an_empty_score_counts_as_no_score(): void
    {
        // Chuỗi rỗng được middleware đổi thành null nên không chấm điểm, chỉ lưu nhận xét.
        $this->review(['finalScore' => '', 'feedback' => 'Chỉ nhận xét'])->assertOk();

        $this->assertNull($this->sub->fresh()->evaluated_score);
        $this->assertSame('Pending', $this->sub->fresh()->result);
    }

    public function test_the_maximum_comes_from_the_assignment_row_then_10_for_activities_then_100_for_practice(): void
    {
        $this->review(['finalScore' => 8])->assertOk();
        $this->review(['finalScore' => 9])->assertStatus(422);

        $noRow = $this->makeSubmission($this->student, $this->makeProblem(), $this->activity);
        $this->review(['finalScore' => 10], $noRow)->assertOk()->assertJsonPath('finalScore', 10);
        $this->review(['finalScore' => 10.5], $noRow)->assertStatus(422)->assertJsonPath('detail', 'Điểm số không hợp lệ. Vui lòng nhập từ 0 đến 10.');

        $author = $this->makeAccount('instructor', ['results.grade']);
        $practice = $this->makeSubmission($this->student, $this->makeProblem(['creator_id' => $author->id]));
        Sanctum::actingAs($author);
        $this->review(['finalScore' => 100], $practice)->assertOk();
        $this->review(['finalScore' => 101], $practice)->assertStatus(422)->assertJsonPath('detail', 'Điểm số không hợp lệ. Vui lòng nhập từ 0 đến 100.');
    }

    public function test_feedback_is_saved_and_replaced_even_when_omitted(): void
    {
        $this->review(['finalScore' => 5, 'feedback' => 'Lần một'])->assertOk()->assertJsonPath('feedback', 'Lần một');
        $this->assertSame('Lần một', $this->sub->fresh()->feedback);

        // Hành vi hiện tại: chấm lại mà không gửi feedback thì xóa feedback cũ.
        $this->review(['finalScore' => 6])->assertOk()->assertJsonPath('feedback', null);
        $this->assertNull($this->sub->fresh()->feedback);
    }

    public function test_feedback_only_leaves_the_score_and_result_untouched(): void
    {
        $this->review(['feedback' => 'Chỉ nhận xét'])->assertOk();

        $sub = $this->sub->fresh();
        $this->assertNull($sub->evaluated_score);
        $this->assertSame('Pending', $sub->result);
        $this->assertSame('Chỉ nhận xét', $sub->feedback);
        // Vẫn gửi thông báo "đã được chấm" cho sinh viên.
        $this->assertCount(1, $this->notificationsOf($this->student));
    }

    public function test_the_response_is_the_refreshed_teacher_view(): void
    {
        $json = $this->review(['finalScore' => 6, 'feedback' => 'Ổn'])->assertOk()->json();

        $this->assertSame($this->sub->id, $json['id']);
        $this->assertEquals(6, $json['finalScore']);
        $this->assertEquals(6, $json['evaluatedScore']);
        $this->assertSame('Graded', $json['status']);
        $this->assertSame('Ổn', $json['feedback']);
        $this->assertTrue($json['canEditReview']);
    }

    // ══ điểm từng câu ═══════════════════════════════════════════════════════

    private function sectionsFixture(): void
    {
        $this->withSections($this->sub, [
            ['part' => 'SQL', 'questions' => [['id' => 'a', 'status' => 'Accepted'], ['id' => 'b', 'manualScore' => 1.5]]],
            ['part' => 'DDL', 'questions' => [['id' => 'a']]],
            ['part' => 'DML'],
        ]);
    }

    private function savedSections(): array
    {
        return WorkspaceSubmissionResult::find($this->sub->id)->sections;
    }

    public function test_question_scores_are_stored_and_summed_into_the_final_score(): void
    {
        $this->sectionsFixture();

        $this->review(['questionScores' => ['SQL:a' => 2.345, 'DDL:a' => '1']])->assertOk();

        $sections = $this->savedSections();
        $this->assertEquals(2.35, $sections[0]['questions'][0]['manualScore']);
        $this->assertEquals(1.5, $sections[0]['questions'][1]['manualScore']);
        $this->assertEquals(1, $sections[1]['questions'][0]['manualScore']);
        // Tổng gồm cả điểm tay đã có ở câu khác: 2.35 + 1.5 + 1.
        $this->assertEquals(4.85, $this->sub->fresh()->evaluated_score);
        $this->assertSame('Partial', $this->sub->fresh()->result);
    }

    public function test_an_explicit_final_score_beats_the_sum_of_question_scores(): void
    {
        $this->sectionsFixture();

        $this->review(['final_score' => 3, 'question_scores' => ['SQL:a' => 2]])->assertOk();

        $this->assertEquals(3, $this->sub->fresh()->evaluated_score);
        $this->assertEquals(2, $this->savedSections()[0]['questions'][0]['manualScore']);
    }

    public function test_unusable_question_scores_are_ignored(): void
    {
        $this->sectionsFixture();
        $before = $this->savedSections();

        $this->review(['questionScores' => ['SQL:zzz' => 5, 'SQL:a' => 'abc', 'khac' => 1]])->assertOk();
        // Điểm từng câu không khớp câu nào thì tổng chỉ gồm điểm tay có sẵn (1.5).
        $this->assertEquals(1.5, $this->sub->fresh()->evaluated_score);
        $this->assertSame($before, $this->savedSections());

        $this->review(['questionScores' => []])->assertOk();
        $this->assertSame($before, $this->savedSections());
    }

    public function test_question_scores_without_saved_sections_are_ignored(): void
    {
        $this->review(['questionScores' => ['SQL:a' => 2]])->assertOk();

        $this->assertNull($this->sub->fresh()->evaluated_score);
        $this->assertNull(WorkspaceSubmissionResult::find($this->sub->id));
    }

    public function test_the_sum_of_question_scores_is_checked_against_the_maximum(): void
    {
        $this->sectionsFixture();

        $this->review(['questionScores' => ['SQL:a' => 7]])->assertStatus(422);

        $this->assertNull($this->sub->fresh()->evaluated_score);
        $this->assertArrayNotHasKey('manualScore', $this->savedSections()[0]['questions'][0]);
    }

    // ══ phúc khảo và chấm lại ════════════════════════════════════════════════

    private function reviewer(): User
    {
        return $this->makeAccount('instructor', ['results.grade']);
    }

    private function appeal(array $overrides = []): SubmissionAppeal
    {
        return SubmissionAppeal::create(array_merge([
            'submission_id' => $this->sub->id, 'student_id' => $this->student->id, 'reason' => 'Xin xem lại',
            'status' => 'pending', 'created_at' => now()->subHour(), 'resolved_at' => null,
        ], $overrides));
    }

    private function regrade(User $reviewer, array $overrides = []): SubmissionRegradeAssignment
    {
        return SubmissionRegradeAssignment::create(array_merge([
            'submission_id' => $this->sub->id, 'assigned_instructor_id' => $reviewer->id,
            'assigned_by_id' => null, 'assigned_at' => now(),
        ], $overrides));
    }

    public function test_a_regrade_assigned_to_someone_else_blocks_the_owner(): void
    {
        $other = $this->reviewer();
        $regrade = $this->regrade($other);

        $this->review(['finalScore' => 1])->assertStatus(403)
            ->assertJsonPath('detail', 'Bài nộp này đang được phân công chấm phúc khảo cho giảng viên khác.');

        $regrade->update(['completed_at' => now()]);
        $this->review(['finalScore' => 1])->assertStatus(403)
            ->assertJsonPath('detail', 'Bài nộp này đã được giảng viên phúc khảo chấm xong, không thể ghi đè.');
        $this->assertNull($this->sub->fresh()->evaluated_score);
    }

    public function test_an_unresolved_appeal_needs_a_regrade_assignment_first(): void
    {
        $this->appeal();

        $this->review(['finalScore' => 1])->assertStatus(403)
            ->assertJsonPath('detail', 'Đơn phúc khảo chưa được Giáo vụ khoa phân công giảng viên chấm lại.');

        // Đơn đã xử lý xong thì chấm bình thường.
        SubmissionAppeal::where('submission_id', $this->sub->id)->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->review(['finalScore' => 1])->assertOk();
    }

    public function test_the_assigned_reviewer_completes_the_regrade_and_the_appeal(): void
    {
        $this->sub->update(['evaluated_score' => 4, 'feedback' => 'Cũ']);
        $reviewer = $this->reviewer();
        $manager = $this->makeAccount('faculty_office', ['examination.manage']);
        $regrade = $this->regrade($reviewer, ['assigned_by_id' => $manager->id]);
        $appeal = $this->appeal();
        Sanctum::actingAs($reviewer);

        $this->review(['finalScore' => 6, 'feedback' => 'Đã xem lại'])->assertOk()
            ->assertJsonPath('status', 'Regraded')
            ->assertJsonPath('canEditReview', true);

        $appeal->refresh();
        $this->assertSame('resolved', $appeal->status);
        $this->assertNotNull($appeal->resolved_at);
        $this->assertEquals(4, $appeal->previous_evaluated_score);
        $regrade->refresh();
        $this->assertNotNull($regrade->completed_at);
        $this->assertEquals(4, $regrade->previous_evaluated_score);
        $this->assertSame('Cũ', $regrade->previous_feedback);
        $this->assertEquals(6, $this->sub->fresh()->evaluated_score);
    }

    public function test_earlier_score_snapshots_are_not_overwritten(): void
    {
        $this->sub->update(['evaluated_score' => 4, 'feedback' => 'Hiện tại']);
        $reviewer = $this->reviewer();
        $regrade = $this->regrade($reviewer, ['previous_evaluated_score' => 2, 'previous_feedback' => 'Gốc']);
        $this->appeal(['previous_evaluated_score' => 3]);
        Sanctum::actingAs($reviewer);

        $this->review(['finalScore' => 6])->assertOk();

        $this->assertEquals(3, SubmissionAppeal::find($this->sub->id)->previous_evaluated_score);
        $regrade->refresh();
        $this->assertEquals(2, $regrade->previous_evaluated_score);
        $this->assertSame('Gốc', $regrade->previous_feedback);
    }

    public function test_the_reviewer_may_grade_again_after_completing_without_changing_the_snapshot(): void
    {
        $this->sub->update(['evaluated_score' => 4]);
        $reviewer = $this->reviewer();
        $this->regrade($reviewer);
        Sanctum::actingAs($reviewer);

        $this->review(['finalScore' => 5])->assertOk();
        $this->review(['finalScore' => 7])->assertOk();

        $regrade = SubmissionRegradeAssignment::find($this->sub->id);
        $this->assertEquals(4, $regrade->previous_evaluated_score);
        $this->assertEquals(7, $this->sub->fresh()->evaluated_score);
    }

    public function test_a_resolved_appeal_is_left_alone(): void
    {
        $resolvedAt = now()->subDay()->startOfSecond();
        $this->appeal(['status' => 'resolved', 'resolved_at' => $resolvedAt, 'previous_evaluated_score' => null]);
        $this->sub->update(['evaluated_score' => 4]);

        $this->review(['finalScore' => 6])->assertOk();

        $appeal = SubmissionAppeal::find($this->sub->id);
        $this->assertNull($appeal->previous_evaluated_score);
        $this->assertSame($resolvedAt->toISOString(), $appeal->resolved_at->toISOString());
    }

    // ══ thông báo ═══════════════════════════════════════════════════════════

    public function test_the_student_is_notified_with_score_and_feedback(): void
    {
        $this->review(['finalScore' => 7.5, 'feedback' => 'Khá tốt'])->assertOk();

        $note = $this->notificationsOf($this->student)->sole();
        $this->assertSame('Bài nộp đã được chấm: Bài chấm', $note->title);
        $this->assertSame('Điểm số: 7.5 / 8. Phản hồi: Khá tốt', $note->message);
        $this->assertSame('Review', $note->type);
        $this->assertSame("/submissions?submission={$this->sub->id}", $note->link);
        $this->assertFalse($note->is_read);
    }

    public function test_the_notification_omits_feedback_when_empty_and_falls_back_to_the_auto_score(): void
    {
        $this->review(['feedback' => ''])->assertOk();

        $this->assertSame('Điểm số: 3 / 8.', $this->notificationsOf($this->student)->sole()->message);
    }

    public function test_the_notification_hides_the_score_while_scores_are_hidden(): void
    {
        $this->activity->update(['show_scores_after_submit' => false]);

        $this->review(['finalScore' => 7, 'feedback' => 'Bí mật'])->assertOk();

        $this->assertSame('Bài nộp đã được chấm. Giáo viên đang ẩn điểm của hoạt động này.', $this->notificationsOf($this->student)->sole()->message);
    }

    public function test_regrade_results_use_a_different_title(): void
    {
        $this->appeal(['status' => 'resolved', 'resolved_at' => now()]);
        $this->review(['finalScore' => 5])->assertOk();
        $this->assertSame('Kết quả phúc khảo: Bài chấm', $this->notificationsOf($this->student)->last()->title);

        $reviewer = $this->reviewer();
        $other = $this->makeSubmission($this->student, $this->problem, $this->activity);
        SubmissionRegradeAssignment::create(['submission_id' => $other->id, 'assigned_instructor_id' => $reviewer->id, 'assigned_at' => now()]);
        Sanctum::actingAs($reviewer);
        $this->review(['finalScore' => 5], $other)->assertOk();
        $note = Notification::where('user_id', $this->student->id)->where('link', "/submissions?submission={$other->id}")->sole();
        $this->assertSame('Kết quả phúc khảo: Bài chấm', $note->title);
    }

    public function test_the_person_who_assigned_the_regrade_is_notified_with_a_link_to_their_area(): void
    {
        $reviewer = $this->reviewer();
        $manager = $this->makeAccount('faculty_office', ['examination.manage']);
        $this->regrade($reviewer, ['assigned_by_id' => $manager->id]);
        Sanctum::actingAs($reviewer);

        $this->review(['finalScore' => 6])->assertOk();

        $note = $this->notificationsOf($manager)->sole();
        $this->assertSame('Đã hoàn tất chấm phúc khảo: Bài chấm', $note->title);
        $this->assertSame("{$this->student->name}: 6 / 8 (GV: {$reviewer->name}).", $note->message);
        $this->assertSame('Review', $note->type);
        $this->assertStringEndsWith("/results/review/{$this->sub->id}", $note->link);
        $this->assertStringStartsWith('/', $note->link);
    }

    public function test_the_assigner_link_prefix_follows_the_assigners_area(): void
    {
        $reviewer = $this->reviewer();
        $admin = $this->makeAccount('admin');
        $teacherAssigner = $this->makeAccount('instructor', ['results.grade']);
        $second = $this->makeSubmission($this->student, $this->problem, $this->activity);
        $this->regrade($reviewer, ['assigned_by_id' => $admin->id]);
        SubmissionRegradeAssignment::create(['submission_id' => $second->id, 'assigned_instructor_id' => $reviewer->id, 'assigned_by_id' => $teacherAssigner->id, 'assigned_at' => now()]);
        Sanctum::actingAs($reviewer);

        $this->review(['finalScore' => 6])->assertOk();
        $this->review(['finalScore' => 6], $second)->assertOk();

        $this->assertSame("/admin/results/review/{$this->sub->id}", $this->notificationsOf($admin)->sole()->link);
        $this->assertSame("/teacher/results/review/{$second->id}", $this->notificationsOf($teacherAssigner)->sole()->link);
    }

    public function test_no_assigner_notification_when_the_assigner_graded_it_themselves(): void
    {
        $reviewer = $this->reviewer();
        $this->regrade($reviewer, ['assigned_by_id' => $reviewer->id]);
        Sanctum::actingAs($reviewer);

        $this->review(['finalScore' => 6])->assertOk();

        $this->assertSame(0, Notification::where('user_id', $reviewer->id)->count());
    }

    public function test_no_assigner_notification_when_there_is_no_assigner(): void
    {
        $reviewer = $this->reviewer();
        $this->regrade($reviewer, ['assigned_by_id' => null]);
        Sanctum::actingAs($reviewer);

        $this->review(['finalScore' => 6])->assertOk();

        $this->assertSame(0, Notification::where('user_id', $reviewer->id)->count());
        $this->assertCount(1, $this->notificationsOf($this->student));
    }

    public function test_a_failed_grading_changes_nothing_at_all(): void
    {
        $this->sectionsFixture();
        $this->appeal(['status' => 'resolved', 'resolved_at' => now()]);

        $this->review(['finalScore' => 99, 'feedback' => 'Không lưu'])->assertStatus(422);

        $this->assertNull($this->sub->fresh()->feedback);
        $this->assertSame(0, Notification::where('user_id', $this->student->id)->count());
    }
}
