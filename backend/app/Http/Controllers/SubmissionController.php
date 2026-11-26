<?php

namespace App\Http\Controllers;

use App\Services\StudentProfileService;
use App\Services\SubmissionGradingService;
use App\Services\SubmissionQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bài nộp: danh sách / chi tiết, chấm bài, công bố điểm và hồ sơ sinh viên. Logic nằm ở
 * SubmissionQueryService, SubmissionGradingService và StudentProfileService. Phúc khảo xem RegradeAssignmentController.
 *
 * Các khóa đầu vào có nhiều cách viết (finalScore / final_score / evaluatedScore / evaluated_score,
 * questionScores / question_scores, questionId / question_id) vì frontend cũ và mới đều đang gọi; lỗi nghiệp vụ
 * giữ nguyên mã trạng thái (400 / 403 / 404 / 422) nên không dùng FormRequest.
 */
class SubmissionController extends Controller
{
    public function __construct(
        protected readonly SubmissionQueryService $queries,
        protected readonly SubmissionGradingService $grading,
        protected readonly StudentProfileService $profiles,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->queries->listForStudent($request->user(), [
            'includeQuestionUpdates' => filter_var($request->query('include_question_updates', false), FILTER_VALIDATE_BOOLEAN),
            'result'                 => (string) $request->query('result', ''),
            'source'                 => (string) $request->query('source', ''),
            'search'                 => (string) $request->query('search', ''),
        ]));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->queries->show($request->user(), $id));
    }

    public function studentWork(Request $request, string $activityId, string $studentId, string $problemId): JsonResponse
    {
        return response()->json($this->queries->studentWork($request->user(), $activityId, $studentId, $problemId));
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $score = $request->input(
            'finalScore',
            $request->input('final_score', $request->input('evaluatedScore', $request->input('evaluated_score')))
        );

        return response()->json($this->grading->review(
            $request->user(),
            $id,
            $score,
            $request->input('feedback'),
            $request->input('questionScores', $request->input('question_scores')),
            $request->input('questionFeedback', $request->input('question_feedback'))
        ));
    }

    public function inspect(Request $request, string $id): JsonResponse
    {
        return response()->json($this->queries->inspect(
            $request->user(),
            $id,
            $request->input('part'),
            (string) ($request->input('questionId') ?? $request->input('question_id') ?? '')
        ));
    }

    public function studentProfile(Request $request): JsonResponse
    {
        return response()->json($this->profiles->profile($request->user()));
    }
}
