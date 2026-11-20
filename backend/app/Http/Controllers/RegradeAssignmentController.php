<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignRegradeRequest;
use App\Http\Requests\RegradeCandidatesRequest;
use App\Models\User;
use App\Services\Access\StaffAccess;
use App\Services\RegradeAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phân công giảng viên chấm phúc khảo: giáo vụ phân công, giảng viên xem việc được giao. Logic ở RegradeAssignmentService. */
class RegradeAssignmentController extends Controller
{
    public function __construct(
        protected RegradeAssignmentService $regrades,
        protected StaffAccess $staff,
    ) {
    }

    protected function requireAssigner(User $user): void
    {
        abort_unless($this->staff->canManageExaminations($user), 403, 'Bạn không có quyền phân công chấm phúc khảo.');
    }

    public function candidates(RegradeCandidatesRequest $request): JsonResponse
    {
        $this->requireAssigner($request->user());

        return response()->json($this->regrades->candidates($request->filters()));
    }

    public function reviewers(Request $request): JsonResponse
    {
        $this->requireAssigner($request->user());

        $submissionId = trim((string) ($request->query('submission_id') ?? $request->query('submissionId') ?? ''));

        return response()->json($this->regrades->reviewers($submissionId));
    }

    public function assign(AssignRegradeRequest $request, string $submissionId): JsonResponse
    {
        $user = $request->user();
        $this->requireAssigner($user);

        return response()->json($this->regrades->assign($submissionId, $request->reviewerId(), $request->note(), $user));
    }

    public function unassign(Request $request, string $submissionId): JsonResponse
    {
        $this->requireAssigner($request->user());

        return response()->json($this->regrades->unassign($submissionId));
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->staff->canGrade($user), 403, 'Bạn không có quyền xem danh sách chấm phúc khảo.');

        return response()->json($this->regrades->mine($user));
    }
}
