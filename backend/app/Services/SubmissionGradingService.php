<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\SubmissionGrade;
use App\Models\SubmissionGradeLog;
use App\Models\ExamRegrade;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use App\Services\Access\StaffAccess;
use App\Services\Grading\ExamQuestionSheet;
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
        protected readonly ExamQuestionSheet $questionSheet,
    ) {
    }

    /**
     * PUT /submissions/{id}/review.
     *
     * Bài thi có kết quả từng câu: bắt buộc chấm điểm MỌI câu (0..điểm tối đa của câu), điểm cuối là tổng các câu
     * ($score bị bỏ qua); chỉ có nhận xét từng câu, không còn nhận xét chung ($feedback bị bỏ qua).
     *
     * Các bài khác: Điểm tối đa: điểm của bài trong hoạt động; hoạt động không có dòng bài thì 10;
     * bài luyện tập 100. Kết quả theo điểm: <= 0 Rejected, >= tối đa Accepted, còn lại Partial. Không gửi điểm thì chỉ
     * lưu nhận xét (và nhận xét luôn được ghi đè, kể cả khi bỏ trống).
     *
     * @param mixed $score điểm cuối (null = không đổi); nếu null mà có $questionScores thì lấy tổng điểm tay các câu
     * @param array<string, mixed>|null $questionScores điểm từng câu theo khóa "PHẦN:mã câu"
     * @param array<string, mixed>|null $questionFeedback nhận xét từng câu theo cùng khóa (chỉ bài thi)
     * @return array<string, mixed> bài nộp sau khi chấm (góc nhìn giảng viên)
     */
    public function review(User $user, string $id, mixed $score, mixed $feedback, mixed $questionScores, mixed $questionFeedback = null): array
    {
        $sub = Submission::findOrFail($id);
        $regrade = ExamRegrade::forSubmission($sub);
        $assignedRegrader = $regrade && $regrade->assigned_instructor_id === $user->id && $this->staff->canRegrade($user);
        abort_unless($this->staff->canGrade($user) || $assignedRegrader, 403, 'Bạn không có quyền chấm bài.');
        abort_unless($this->access->canGradeSubmission($user, $sub), 403, 'Bạn không có quyền chấm bài nộp này.');

        if ($regrade && $regrade->assigned_instructor_id !== $user->id) {
            abort(
                403,
                $regrade->completed_at === null
                    ? 'Bài nộp này đang được phân công chấm phúc khảo cho giảng viên khác.'
                    : 'Bài nộp này đã được giảng viên phúc khảo chấm xong, không thể ghi đè.'
            );
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
        $asRegrader = $regrade && $regrade->assigned_instructor_id === $user->id;
        $questionMax = ($ws && is_array($ws->sections))
            ? $this->questionSheet->maxScores($sub, $activity, $ap, $ws->sections)
            : [];
        if ($questionMax !== []) {
            [$updatedSections, $evalScoreInput] = $this->gradeEveryQuestion(
                $ws->sections,
                $questionMax,
                is_array($questionScores) ? $questionScores : [],
                is_array($questionFeedback) ? $questionFeedback : [],
            );
            $feedback = null;
        } elseif (is_array($questionScores) && !empty($questionScores) && $ws && is_array($ws->sections)) {
            $sections = $ws->sections;
            // Bản phúc khảo độc lập với lần chấm đầu: điểm tay từng câu chính thức chỉ lấy từ người phúc khảo
            // (điểm từng câu của lần chấm đầu vẫn nằm trong bản `initial` của submission_grades).
            if ($asRegrader) {
                $sections = $this->withoutManualScores($sections);
            }
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

        // Phúc khảo không gửi điểm từng câu: điểm tay từng câu chính thức cũng không còn là của lần chấm đầu.
        if ($asRegrader && $updatedSections === null && $ws && is_array($ws->sections)) {
            $updatedSections = $this->withoutManualScores($ws->sections);
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

        $isRegradeAction = $regrade && $regrade->assigned_instructor_id === $user->id;
        $prob = Problem::find($sub->problem_id);
        $scoresVisible = !$activity || (bool) $activity->show_scores_after_submit;

        DB::transaction(function () use (
            $sub,
            $ws,
            $updatedSections,
            $scoreVal,
            $maxScore,
            $feedback,
            $regrade,
            $user,
            $isRegradeAction,
            $prob,
            $scoresVisible
        ) {
            $previousScore = $sub->evaluated_score !== null ? (float) $sub->evaluated_score : null;
            $previousFeedback = $sub->feedback;

            // Bài đã chấm từ trước khi có bảng submission_grades: chụp lần chấm đầu trước khi phúc khảo ghi lên điểm chính thức.
            if ($isRegradeAction
                && ($sub->evaluated_score !== null || $sub->feedback !== null)
                && !SubmissionGrade::where('submission_id', $sub->id)->where('kind', SubmissionGrade::INITIAL)->exists()) {
                SubmissionGrade::create([
                    'submission_id'   => $sub->id,
                    'kind'            => SubmissionGrade::INITIAL,
                    'grader_id'       => null,
                    'score'           => $sub->evaluated_score,
                    'feedback'        => $sub->feedback,
                    'question_scores' => $this->manualScores($ws && is_array($ws->sections) ? $ws->sections : []) ?: null,
                    'question_feedback' => $this->manualFeedback($ws && is_array($ws->sections) ? $ws->sections : []) ?: null,
                    'graded_at'       => null,
                ]);
            }

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

            // Bản chấm riêng của người này (lần đầu hoặc phúc khảo); không bao giờ ghi lên bản của người kia.
            $kind = $isRegradeAction ? SubmissionGrade::REGRADE : SubmissionGrade::INITIAL;
            $ownRow = SubmissionGrade::where('submission_id', $sub->id)->where('kind', $kind)->first();
            $gradeData = [
                'grader_id'       => $user->id,
                // Chỉ lưu nhận xét (không gửi điểm) thì giữ điểm đã có của bản này.
                'score'           => $scoreVal ?? $ownRow?->score ?? ($isRegradeAction ? null : $sub->evaluated_score),
                'feedback'        => $feedback,
                // Điểm tay từng câu của riêng người này (sections chính thức vừa được cập nhật ở trên theo cùng quy tắc).
                'question_scores' => $this->manualScores($updatedSections ?? ($ws && is_array($ws->sections) ? $ws->sections : [])) ?: null,
                'question_feedback' => $this->manualFeedback($updatedSections ?? ($ws && is_array($ws->sections) ? $ws->sections : [])) ?: null,
                'graded_at'       => now(),
            ];
            SubmissionGrade::updateOrCreate(['submission_id' => $sub->id, 'kind' => $kind], $gradeData);
            // Nhật ký: mỗi lần lưu thêm một dòng, không bao giờ sửa dòng cũ.
            SubmissionGradeLog::create(['submission_id' => $sub->id, 'kind' => $kind] + $gradeData);

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
     * Ghi điểm và nhận xét của mọi câu vào kết quả bài nộp (thay hẳn điểm tay cũ).
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, float>  $max  điểm tối đa từng câu
     * @param  array<string, mixed>  $scores
     * @param  array<string, mixed>  $comments
     * @return array{0: array<int, array<string, mixed>>, 1: float}  kết quả mới và tổng điểm
     */
    protected function gradeEveryQuestion(array $sections, array $max, array $scores, array $comments): array
    {
        $missing = array_filter(array_keys($max), fn (string $key) => !isset($scores[$key]) || !is_numeric($scores[$key]));
        if ($missing !== []) {
            abort(422, 'Vui lòng chấm điểm tất cả các câu (còn thiếu ' . count($missing) . ' câu).');
        }

        $sum = 0.0;
        foreach ($sections as $i => $section) {
            foreach (($section['questions'] ?? []) as $j => $question) {
                $key = ($section['part'] ?? '') . ':' . ($question['id'] ?? '');
                $value = round((float) $scores[$key], 2);
                if ($value < 0 || $value > $max[$key]) {
                    abort(422, 'Điểm câu ' . ($j + 1) . ' (' . ($section['part'] ?? '') . ") không hợp lệ. Vui lòng nhập từ 0 đến {$max[$key]}.");
                }
                $comment = isset($comments[$key]) && is_string($comments[$key]) ? trim($comments[$key]) : '';
                if (mb_strlen($comment) > 5000) {
                    abort(422, 'Nhận xét của một câu không được dài quá 5000 ký tự.');
                }
                $question['manualScore'] = $value;
                $question['manualMaxScore'] = $max[$key];
                unset($question['manualFeedback']);
                if ($comment !== '') {
                    $question['manualFeedback'] = $comment;
                }
                $sections[$i]['questions'][$j] = $question;
                $sum += $value;
            }
        }

        return [$sections, round($sum, 2)];
    }

    /**
     * Nhận xét từng câu trong kết quả bài nộp: "PHẦN:mã câu" => nhận xét.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, string>
     */
    protected function manualFeedback(array $sections): array
    {
        $comments = [];
        foreach ($sections as $section) {
            foreach (($section['questions'] ?? []) as $question) {
                if (!empty($question['manualFeedback'])) {
                    $comments[($section['part'] ?? '') . ':' . ($question['id'] ?? '')] = (string) $question['manualFeedback'];
                }
            }
        }

        return $comments;
    }

    /**
     * Điểm tay từng câu trong kết quả bài nộp: "PHẦN:mã câu" => điểm.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, float>
     */
    protected function manualScores(array $sections): array
    {
        $scores = [];
        foreach ($sections as $section) {
            foreach (($section['questions'] ?? []) as $question) {
                if (isset($question['manualScore']) && is_numeric($question['manualScore'])) {
                    $scores[($section['part'] ?? '') . ':' . ($question['id'] ?? '')] = round((float) $question['manualScore'], 2);
                }
            }
        }

        return $scores;
    }

    /** Kết quả bài nộp không còn điểm tay từng câu nào (chỉ còn điểm tự động). */
    protected function withoutManualScores(array $sections): array
    {
        foreach ($sections as $i => $section) {
            foreach (($section['questions'] ?? []) as $j => $question) {
                unset(
                    $sections[$i]['questions'][$j]['manualScore'],
                    $sections[$i]['questions'][$j]['manualMaxScore'],
                    $sections[$i]['questions'][$j]['manualFeedback'],
                );
            }
        }

        return $sections;
    }

}
