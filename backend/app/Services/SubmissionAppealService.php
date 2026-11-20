<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionAppeal;
use App\Models\SubmissionRegradeAssignment;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;

/**
 * Sinh viên gửi đơn phúc khảo cho một bài thi đã có điểm chính thức và đã công bố.
 */
class SubmissionAppealService
{
    public function __construct(protected readonly SubmissionViewService $views)
    {
    }

    /**
     * POST /submissions/{id}/appeal. Thứ tự kiểm tra (lỗi đầu tiên được báo): bài của chính mình (404), không phải bài
     * từng câu, là bài thi, đã chấm chính thức, điểm đã công bố, là lần nộp chính thức mới nhất, chưa có đơn, lý do >= 5 ký tự.
     *
     * @return array<string, mixed> dòng bài nộp (góc nhìn sinh viên) sau khi gửi đơn
     */
    public function submit(User $user, string $id, string $reason): array
    {
        $sub = Submission::where('id', $id)->where('user_id', $user->id)->firstOrFail();

        $ws = WorkspaceSubmissionResult::find($sub->id);
        $wsSections = ($ws && is_array($ws->sections)) ? $ws->sections : null;
        $isQuestionOnly = is_array($wsSections) && isset($wsSections[0]['_questionSubmission']['part']);
        abort_if($isQuestionOnly, 422, 'Không thể gửi phúc khảo cho bài nộp từng câu.');

        $activity = ($sub->context && $sub->context !== 'Practice') ? Assignment::find($sub->context) : null;
        $isExamSession = in_array($sub->source, ['ExamSession'], true)
            || ($activity && ($activity->examination_id || $activity->exam_mode));
        abort_unless($isExamSession, 422, 'Chỉ bài thi mới được gửi đơn phúc khảo.');
        abort_unless($sub->evaluated_score !== null, 422, 'Bài nộp chưa được chấm chính thức, chưa thể gửi phúc khảo.');
        if ($activity && !$activity->show_scores_after_submit) {
            abort(422, 'Điểm thi chưa được công bố, chưa thể gửi phúc khảo.');
        }

        $latestOfficialId = Submission::where('user_id', $user->id)
            ->where('problem_id', $sub->problem_id)
            ->where('context', $sub->context)
            ->where('source', $sub->source)
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->value('id');
        abort_if($latestOfficialId && $latestOfficialId !== $sub->id, 422, 'Vui lòng gửi phúc khảo trên lần nộp mới nhất của bài thi.');

        $existingAppeal = SubmissionAppeal::find($sub->id);
        if ($existingAppeal) {
            abort(422, $existingAppeal->resolved_at
                ? 'Bài nộp này đã được phúc khảo xong.'
                : 'Bạn đã gửi đơn phúc khảo cho bài nộp này rồi.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            abort(400, 'Vui lòng mô tả lý do phúc khảo rõ ràng hơn (tối thiểu 5 ký tự).');
        }

        $appeal = SubmissionAppeal::create([
            'submission_id'            => $sub->id,
            'student_id'               => $user->id,
            'reason'                   => $reason,
            'status'                   => 'pending',
            'previous_evaluated_score' => $sub->evaluated_score !== null ? (float) $sub->evaluated_score : null,
            'created_at'               => now(),
            'resolved_at'              => null,
        ]);

        $prob = Problem::find($sub->problem_id);
        $ap = $activity
            ? AssignmentProblem::where('assignment_id', $activity->id)->where('problem_id', $sub->problem_id)->first()
            : null;
        $regrade = SubmissionRegradeAssignment::find($sub->id);

        return $this->views->studentRow($sub, $prob, $activity, $ap, $ws, $appeal, $regrade);
    }
}
