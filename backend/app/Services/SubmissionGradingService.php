<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use App\Services\Access\StaffAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Giảng viên chấm bài (điểm, nhận xét, điểm từng câu), xử lý đơn phúc khảo / đợt chấm lại, và công bố điểm.
 */
class SubmissionGradingService
{
    public function __construct(
        protected readonly SubmissionAccessService $access,
        protected readonly SubmissionViewService $views,
        protected readonly NotificationService $notificationService,
        protected readonly StaffAccess $staff,
    ) {
    }

    /**
     * PUT /submissions/{id}/review. Điểm tối đa: điểm của bài trong hoạt động; hoạt động không có dòng bài thì 10;
     * bài luyện tập 100. Kết quả theo điểm: <= 0 Rejected, >= tối đa Accepted, còn lại Partial. Không gửi điểm thì chỉ
     * lưu nhận xét (và nhận xét luôn được ghi đè, kể cả khi bỏ trống).
     *
     * @param mixed $score điểm cuối (null = không đổi); nếu null mà có $questionScores thì lấy tổng điểm tay các câu
     * @param array<string, mixed>|null $questionScores điểm từng câu theo khóa "PHẦN:mã câu"
     * @return array<string, mixed> bài nộp sau khi chấm (góc nhìn giảng viên)
     */
    public function review(User $user, string $id, mixed $score, mixed $feedback, mixed $questionScores): array
    {
        $sub = Submission::findOrFail($id);
        abort_unless($this->staff->canGrade($user), 403, 'Bạn không có quyền chấm bài.');
        abort_unless($this->access->canGradeSubmission($user, $sub), 403, 'Bạn không có quyền chấm bài nộp này.');

        $regrade = SubmissionRegradeAssignment::find($sub->id);
        if ($regrade && $regrade->assigned_instructor_id !== $user->id) {
            abort(
                403,
                $regrade->completed_at === null
                    ? 'Bài nộp này đang được phân công chấm phúc khảo cho giảng viên khác.'
                    : 'Bài nộp này đã được giảng viên phúc khảo chấm xong, không thể ghi đè.'
            );
        }

        $appeal = SubmissionAppeal::find($sub->id);
        if ($appeal && !$appeal->resolved_at && !$regrade) {
            abort(403, 'Đơn phúc khảo chưa được Giáo vụ khoa phân công giảng viên chấm lại.');
        }

        $evalScoreInput = $score;

        $isActivity = in_array($sub->source, ['Assignments', 'ExamSession', 'Assignment'], true)
            && $sub->context && $sub->context !== 'Practice';
        $activity = $isActivity ? Assignment::find($sub->context) : null;
        $ap = ($activity && $sub->problem_id)
            ? AssignmentProblem::where('assignment_id', $activity->id)->where('problem_id', $sub->problem_id)->first()
            : null;
        $maxScore = $ap ? (float) $ap->points : ($activity ? 10.0 : 100.0);

        $ws = WorkspaceSubmissionResult::find($sub->id);
        $updatedSections = null;
        if (is_array($questionScores) && !empty($questionScores) && $ws && is_array($ws->sections)) {
            $sections = $ws->sections;
            $sum = 0.0;
            foreach ($sections as &$sec) {
                $part = $sec['part'] ?? '';
                if (!empty($sec['questions']) && is_array($sec['questions'])) {
                    foreach ($sec['questions'] as &$q) {
                        $qid = $q['id'] ?? '';
                        $key = "{$part}:{$qid}";
                        if (isset($questionScores[$key]) && is_numeric($questionScores[$key])) {
                            $q['manualScore'] = round((float) $questionScores[$key], 2);
                        }
                        if (isset($q['manualScore'])) {
                            $sum += (float) $q['manualScore'];
                        }
                    }
                    unset($q);
                }
            }
            unset($sec);
            $updatedSections = $sections;

            if ($evalScoreInput === null) {
                $evalScoreInput = round($sum, 2);
            }
        }

        $scoreVal = null;
        if ($evalScoreInput !== null) {
            if (!is_numeric($evalScoreInput)) {
                abort(422, 'Điểm số không hợp lệ.');
            }
            $scoreVal = round((float) $evalScoreInput, 2);
            if ($scoreVal < 0 || $scoreVal > $maxScore) {
                abort(422, "Điểm số không hợp lệ. Vui lòng nhập từ 0 đến {$maxScore}.");
            }
        }

        $isRegradeAction = ($regrade && $regrade->assigned_instructor_id === $user->id) || ($appeal !== null);
        $prob = Problem::find($sub->problem_id);
        $scoresVisible = !$activity || (bool) $activity->show_scores_after_submit;

        DB::transaction(function () use (
            $sub,
            $ws,
            $updatedSections,
            $scoreVal,
            $maxScore,
            $feedback,
            $appeal,
            $regrade,
            $user,
            $isRegradeAction,
            $prob,
            $scoresVisible
        ) {
            $previousScore = $sub->evaluated_score !== null ? (float) $sub->evaluated_score : null;
            $previousFeedback = $sub->feedback;

            if ($ws && $updatedSections !== null) {
                $ws->sections = $updatedSections;
                $ws->save();
            }

            if ($scoreVal !== null) {
                $sub->evaluated_score = $scoreVal;
                if ($scoreVal <= 0) {
                    $sub->result = 'Rejected';
                } elseif ($scoreVal >= $maxScore) {
                    $sub->result = 'Accepted';
                } else {
                    $sub->result = 'Partial';
                }
            }
            $sub->feedback = $feedback;
            $sub->save();

            if ($appeal && !$appeal->resolved_at) {
                if ($appeal->previous_evaluated_score === null) {
                    $appeal->previous_evaluated_score = $previousScore;
                }
                $appeal->status = 'resolved';
                $appeal->resolved_at = now();
                $appeal->save();
            }

            if ($regrade && $regrade->assigned_instructor_id === $user->id) {
                if ($regrade->completed_at === null && $regrade->previous_evaluated_score === null) {
                    $regrade->previous_evaluated_score = $previousScore;
                    $regrade->previous_feedback = $previousFeedback;
                }
                $regrade->completed_at = now();
                $regrade->save();
            }

            $notifTitle = ($isRegradeAction ? 'Kết quả phúc khảo: ' : 'Bài nộp đã được chấm: ') . ($prob ? $prob->title : 'SQL Problem');
            $this->notificationService->create(
                $sub->user_id,
                $notifTitle,
                $scoresVisible
                    ? ('Điểm số: ' . ($sub->evaluated_score ?? $sub->score) . " / {$maxScore}." . ($feedback ? " Phản hồi: {$feedback}" : ''))
                    : 'Bài nộp đã được chấm. Giáo viên đang ẩn điểm của hoạt động này.',
                'Review',
                "/submissions?submission={$sub->id}",
            );

            if ($regrade && $regrade->assigned_by_id && $regrade->assigned_by_id !== $user->id) {
                $assigner = User::find($regrade->assigned_by_id);
                if ($assigner) {
                    $assignerLink = $this->staff->reviewAreaPrefix($assigner) . "/results/review/{$sub->id}";
                    $student = User::find($sub->user_id);
                    $this->notificationService->create(
                        $assigner->id,
                        'Đã hoàn tất chấm phúc khảo: ' . ($prob ? $prob->title : 'Bài nộp SQL'),
                        ($student ? $student->name : 'Sinh viên') . ': ' . ($sub->evaluated_score ?? $sub->score) . " / {$maxScore} (GV: {$user->name}).",
                        'Review',
                        $assignerLink,
                    );
                }
            }
        });

        return $this->views->teacherView($sub->refresh(), $user);
    }

    /**
     * POST /results/{activityId}/publish: công bố (hoặc thu hồi) điểm của hoạt động. Chủ hoạt động hoặc người xem
     * mọi kết quả mới được làm; ghi nhật ký "results:publish|unpublish:{id}".
     */
    public function publishResults(User $user, string $activityId, bool $published): bool
    {
        abort_unless($this->staff->isStaff($user), 403, 'Bạn không có quyền công bố điểm.');

        $activity = Assignment::findOrFail($activityId);
        if (!$this->staff->seesEverything($user)) {
            abort_unless($activity->instructor_id === $user->id, 403, 'Bạn không có quyền công bố điểm của hoạt động này.');
        }
        $activity->show_scores_after_submit = $published;
        $activity->save();

        ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user->id,
            'action'     => ($published ? 'results:publish:' : 'results:unpublish:') . $activity->id,
            'created_at' => now(),
        ]);

        return $published;
    }
}
