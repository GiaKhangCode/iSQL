<?php

namespace App\Services\Grading;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\Submission;
use App\Models\User;
use App\Services\ExaminationService;

/**
 * Phiếu chấm tay của một bài thi: giảng viên cho điểm và nhận xét từng câu, điểm cuối là tổng điểm các câu.
 * Chỉ áp dụng cho bài thi (hoạt động có examination_id hoặc exam_mode) có kết quả từng câu đã lưu.
 */
class ExamQuestionSheet
{
    public function __construct(protected readonly ExaminationService $examinationService)
    {
    }

    /**
     * Điểm tối đa từng câu theo cấu hình đề của sinh viên, khóa "PHẦN:mã câu". Mảng rỗng = bài này không chấm theo câu.
     *
     * @param  array<int, array<string, mixed>>  $sections  kết quả bài nộp đã lưu
     * @return array<string, float>
     */
    public function maxScores(Submission $sub, ?Assignment $activity, ?AssignmentProblem $ap, array $sections): array
    {
        if (!$activity || !$ap || !($activity->examination_id || $activity->exam_mode)) {
            return [];
        }

        $questions = [];
        foreach ($sections as $section) {
            foreach (($section['questions'] ?? []) as $question) {
                $questions[] = ['part' => (string) ($section['part'] ?? ''), 'id' => (string) ($question['id'] ?? '')];
            }
        }
        if ($questions === []) {
            return [];
        }

        $student = User::find($sub->user_id);
        $selection = $student ? $this->examinationService->studentSelectionForProblem($student, $activity, $ap) : null;
        $points = QuestionScoring::maxScores($questions, $selection, (float) $ap->points);

        $result = [];
        foreach ($questions as $i => $question) {
            $result[$question['part'] . ':' . $question['id']] = $points[$i];
        }

        return $result;
    }
}
