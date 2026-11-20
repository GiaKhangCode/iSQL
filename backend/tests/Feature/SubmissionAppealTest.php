<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GradingFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả POST /api/submissions/{id}/appeal (sinh viên gửi đơn phúc khảo bài thi) và
 * POST /api/results/{activityId}/publish (công bố / thu hồi điểm của hoạt động).
 */
class SubmissionAppealTest extends TestCase
{
    use DatabaseTransactions;
    use GradingFixtures;
    use UsesDefaultRolePermissions;

    protected User $teacher;
    protected User $student;
    protected Problem $problem;
    protected Assignment $exam;
    protected Submission $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        $this->teacher = $this->makeAccount('instructor');
        $this->student = $this->makeStudent();
        $this->problem = $this->makeProblem();
        $this->exam = $this->makeActivity($this->teacher, ['exam_mode' => true]);
        $this->attachProblem($this->exam, $this->problem, 10);
        $this->sub = $this->makeSubmission($this->student, $this->problem, $this->exam, ['source' => 'ExamSession', 'evaluated_score' => 6]);
        Sanctum::actingAs($this->student);
    }

    private function appeal(array $body = ['reason' => 'Câu 2 chấm thiếu điểm'], ?Submission $sub = null)
    {
        return $this->postJson('/api/submissions/' . ($sub ?? $this->sub)->id . '/appeal', $body);
    }

    // ══ gửi đơn phúc khảo ═══════════════════════════════════════════════════

    public function test_a_valid_appeal_is_stored_and_returned(): void
    {
        $res = $this->appeal(['reason' => '  Câu 2 chấm thiếu điểm  '])->assertOk();

        $res->assertJsonPath('id', $this->sub->id)
            ->assertJsonPath('appealStatus', 'Awaiting regrade')
            ->assertJsonPath('appealReason', 'Câu 2 chấm thiếu điểm')
            ->assertJsonPath('appealResolvedAt', null)
            ->assertJsonPath('canAppeal', false);
        $this->assertNotNull($res->json('appealCreatedAt'));

        $appeal = SubmissionAppeal::find($this->sub->id);
        $this->assertSame($this->student->id, $appeal->student_id);
        $this->assertSame('Câu 2 chấm thiếu điểm', $appeal->reason);
        $this->assertSame('pending', $appeal->status);
        $this->assertEquals(6, $appeal->previous_evaluated_score);
        $this->assertNull($appeal->resolved_at);
        $this->assertNotNull($appeal->created_at);
    }

    public function test_requires_authentication_and_ownership(): void
    {
        $this->appeal(['reason' => 'Lý do hợp lệ'], $this->makeSubmission($this->makeStudent(), $this->problem, $this->exam, ['source' => 'ExamSession', 'evaluated_score' => 1]))
            ->assertStatus(404);
        $this->postJson('/api/submissions/khong-co/appeal', ['reason' => 'Lý do hợp lệ'])->assertStatus(404);

        $this->app['auth']->forgetGuards();
        $this->withHeaders([])->postJson("/api/submissions/{$this->sub->id}/appeal", ['reason' => 'Lý do hợp lệ'], ['Authorization' => ''])->assertStatus(401);
        $this->assertNull(SubmissionAppeal::find($this->sub->id));
    }

    public function test_the_reason_must_have_at_least_five_characters(): void
    {
        $msg = 'Vui lòng mô tả lý do phúc khảo rõ ràng hơn (tối thiểu 5 ký tự).';

        $this->appeal([])->assertStatus(400)->assertJsonPath('detail', $msg);
        $this->appeal(['reason' => ''])->assertStatus(400);
        $this->appeal(['reason' => '  abcd  '])->assertStatus(400)->assertJsonPath('detail', $msg);
        // 4 ký tự có dấu (8 byte) vẫn là quá ngắn: độ dài tính theo ký tự.
        $this->appeal(['reason' => 'ơơơơ'])->assertStatus(400)->assertJsonPath('detail', $msg);
        $this->assertNull(SubmissionAppeal::find($this->sub->id));

        // Đủ 5 ký tự tính theo ký tự, không theo byte.
        $this->appeal(['reason' => 'ơơơơơ'])->assertOk();
    }

    public function test_question_only_submissions_cannot_be_appealed(): void
    {
        $this->questionOnly($this->sub);

        $this->appeal()->assertStatus(422)->assertJsonPath('detail', 'Không thể gửi phúc khảo cho bài nộp từng câu.');
    }

    public function test_only_exam_submissions_can_be_appealed(): void
    {
        $plain = $this->makeActivity($this->teacher);
        $this->attachProblem($plain, $this->problem);
        $sub = $this->makeSubmission($this->student, $this->problem, $plain, ['evaluated_score' => 6]);

        $this->appeal(['reason' => 'Lý do hợp lệ'], $sub)->assertStatus(422)->assertJsonPath('detail', 'Chỉ bài thi mới được gửi đơn phúc khảo.');
        $practice = $this->makeSubmission($this->student, $this->problem, null, ['evaluated_score' => 6]);
        $this->appeal(['reason' => 'Lý do hợp lệ'], $practice)->assertStatus(422);
    }

    public function test_exam_submissions_are_recognised_by_source_exam_mode_or_examination(): void
    {
        $session = $this->makeExamSession();
        $viaExamination = $this->makeSubmission($this->student, $this->problem, $session, ['evaluated_score' => 6]);
        $plain = $this->makeActivity($this->teacher);
        $viaSource = $this->makeSubmission($this->student, $this->problem, $plain, ['source' => 'ExamSession', 'evaluated_score' => 6]);

        $this->appeal(['reason' => 'Lý do hợp lệ'], $viaExamination)->assertOk();
        $this->appeal(['reason' => 'Lý do hợp lệ'], $viaSource)->assertOk();
        $this->appeal()->assertOk();
    }

    public function test_each_exam_flag_alone_is_enough_to_appeal(): void
    {
        $viaExamination = $this->makeActivity($this->teacher, ['examination_id' => $this->makeExamination()->id, 'exam_mode' => false]);
        $viaMode = $this->makeActivity($this->teacher, ['exam_mode' => true]);
        foreach ([$viaExamination, $viaMode] as $activity) {
            $sub = $this->makeSubmission($this->student, $this->problem, $activity, ['evaluated_score' => 6]);
            $this->appeal(['reason' => 'Lý do hợp lệ'], $sub)->assertOk();
        }
    }

    public function test_the_submission_must_be_officially_graded(): void
    {
        $this->sub->update(['evaluated_score' => null]);

        $this->appeal()->assertStatus(422)->assertJsonPath('detail', 'Bài nộp chưa được chấm chính thức, chưa thể gửi phúc khảo.');
    }

    public function test_scores_must_be_published_first(): void
    {
        $this->exam->update(['show_scores_after_submit' => false]);

        $this->appeal()->assertStatus(422)->assertJsonPath('detail', 'Điểm thi chưa được công bố, chưa thể gửi phúc khảo.');

        $this->exam->update(['show_scores_after_submit' => true]);
        $this->appeal()->assertOk();
    }

    public function test_a_submission_whose_activity_is_gone_can_still_be_appealed(): void
    {
        $orphan = $this->makeSubmission($this->student, $this->problem, null, ['source' => 'ExamSession', 'context' => 'hoat-dong-da-xoa', 'evaluated_score' => 6]);

        $this->appeal(['reason' => 'Lý do hợp lệ'], $orphan)->assertOk()->assertJsonPath('contextTitle', 'hoat-dong-da-xoa');
    }

    public function test_only_the_latest_official_attempt_can_be_appealed(): void
    {
        $base = now()->subDays(2)->startOfSecond();
        $this->sub->update(['submitted_at' => $base]);
        $newer = $this->makeSubmission($this->student, $this->problem, $this->exam, ['source' => 'ExamSession', 'evaluated_score' => 7, 'submitted_at' => $base->copy()->addHour()]);
        // Bài nộp từng câu mới hơn không được tính là lần nộp chính thức.
        $this->questionOnly($this->makeSubmission($this->student, $this->problem, $this->exam, ['source' => 'ExamSession', 'submitted_at' => $base->copy()->addHours(5)]));

        $this->appeal()->assertStatus(422)->assertJsonPath('detail', 'Vui lòng gửi phúc khảo trên lần nộp mới nhất của bài thi.');
        $this->appeal(['reason' => 'Lý do hợp lệ'], $newer)->assertOk();
    }

    public function test_the_latest_attempt_tie_is_broken_by_id(): void
    {
        $at = now()->subDay()->startOfSecond();
        $this->sub->update(['submitted_at' => $at]);
        $others = [];
        for ($i = 0; $i < 4; $i++) {
            $others[] = $this->makeSubmission($this->student, $this->problem, $this->exam, ['source' => 'ExamSession', 'evaluated_score' => 5, 'submitted_at' => $at]);
        }
        $all = array_merge([$this->sub], $others);
        usort($all, fn ($a, $b) => strcmp($b->id, $a->id));

        $this->appeal(['reason' => 'Lý do hợp lệ'], $all[0])->assertOk();
        $this->appeal(['reason' => 'Lý do hợp lệ'], $all[1])->assertStatus(422);
    }

    public function test_attempts_of_other_sources_or_problems_do_not_count_as_newer(): void
    {
        $this->makeSubmission($this->student, $this->problem, $this->exam, ['source' => 'Assignments', 'submitted_at' => now()->addHour()]);
        $this->makeSubmission($this->student, $this->makeProblem(), $this->exam, ['source' => 'ExamSession', 'submitted_at' => now()->addHour()]);
        $this->makeSubmission($this->makeStudent(), $this->problem, $this->exam, ['source' => 'ExamSession', 'submitted_at' => now()->addHour()]);

        $this->appeal()->assertOk();
    }

    public function test_a_second_appeal_is_refused_with_the_right_message(): void
    {
        $this->appeal()->assertOk();
        $this->appeal(['reason' => 'Gửi lại lần nữa'])->assertStatus(422)->assertJsonPath('detail', 'Bạn đã gửi đơn phúc khảo cho bài nộp này rồi.');

        SubmissionAppeal::where('submission_id', $this->sub->id)->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->appeal(['reason' => 'Gửi lại lần nữa'])->assertStatus(422)->assertJsonPath('detail', 'Bài nộp này đã được phúc khảo xong.');
        $this->assertSame(1, SubmissionAppeal::where('submission_id', $this->sub->id)->count());
    }

    public function test_the_checks_run_in_a_fixed_order(): void
    {
        // Cả "không phải bài thi" lẫn "lý do ngắn": báo lỗi loại bài trước.
        $plain = $this->makeActivity($this->teacher);
        $sub = $this->makeSubmission($this->student, $this->problem, $plain);
        $this->appeal(['reason' => 'a'], $sub)->assertStatus(422);

        // Cả "chưa chấm" lẫn "lý do ngắn": báo chưa chấm trước.
        $this->sub->update(['evaluated_score' => null]);
        $this->appeal(['reason' => 'a'])->assertStatus(422)->assertJsonPath('detail', 'Bài nộp chưa được chấm chính thức, chưa thể gửi phúc khảo.');

        // Lý do ngắn chỉ bị báo khi mọi điều kiện khác đã đạt.
        $this->sub->update(['evaluated_score' => 6]);
        $this->appeal(['reason' => 'a'])->assertStatus(400);
    }

    // ══ công bố điểm ════════════════════════════════════════════════════════

    private function publish(Assignment $activity, array $body = [])
    {
        return $this->postJson("/api/results/{$activity->id}/publish", $body);
    }

    public function test_publishing_defaults_to_true_and_is_logged(): void
    {
        $this->exam->update(['show_scores_after_submit' => false]);
        Sanctum::actingAs($this->teacher);

        $this->publish($this->exam)->assertOk()->assertExactJson(['published' => true]);

        $this->assertTrue((bool) $this->exam->fresh()->show_scores_after_submit);
        $log = ActivityLog::where('action', "results:publish:{$this->exam->id}")->sole();
        $this->assertSame($this->teacher->id, $log->user_id);
    }

    public function test_unpublishing_and_the_accepted_boolean_spellings(): void
    {
        Sanctum::actingAs($this->teacher);

        foreach ([[false, false], ['false', false], ['0', false], ['no', false], ['abc', false], [true, true], ['true', true], ['1', true], ['on', true]] as [$input, $expected]) {
            $this->publish($this->exam, ['published' => $input])->assertOk()->assertExactJson(['published' => $expected]);
            $this->assertSame($expected, (bool) $this->exam->fresh()->show_scores_after_submit, var_export($input, true));
        }

        $this->publish($this->exam, ['published' => false])->assertOk();
        $this->assertTrue(ActivityLog::where('action', "results:unpublish:{$this->exam->id}")->where('user_id', $this->teacher->id)->exists());
    }

    public function test_publishing_scope(): void
    {
        $other = $this->makeAccount('instructor');
        $viewer = $this->makeAccount('instructor', ['results.view_all']);
        $manager = $this->makeAccount('faculty_office', ['examination.manage']);
        $admin = $this->makeAccount('admin');

        Sanctum::actingAs($this->student);
        $this->publish($this->exam)->assertStatus(403)->assertJsonPath('detail', 'Bạn không có quyền công bố điểm.');
        Sanctum::actingAs($other);
        $this->publish($this->exam)->assertStatus(403)->assertJsonPath('detail', 'Bạn không có quyền công bố điểm của hoạt động này.');
        foreach ([$viewer, $manager, $admin, $this->teacher] as $user) {
            Sanctum::actingAs($user);
            $this->publish($this->exam, ['published' => false])->assertOk();
        }
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/results/khong-co/publish')->assertStatus(404);
    }
}
