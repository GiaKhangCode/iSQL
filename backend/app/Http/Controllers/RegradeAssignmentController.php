<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignRegradeRequest;
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

    public function reviewers(Request $request): JsonResponse
    {
        $this->requireAssigner($request->user());

        $activityId = trim((string) ($request->query('activity_id') ?? $request->query('activityId') ?? ''));
        $studentId = trim((string) ($request->query('student_id') ?? $request->query('studentId') ?? ''));

        return response()->json($this->regrades->reviewers($activityId, $studentId));
    }

    public function assign(AssignRegradeRequest $request, string $activityId, string $studentId): JsonResponse
    {
        $user = $request->user();
        $this->requireAssigner($user);

        return response()->json($this->regrades->assign($activityId, $studentId, $request->reviewerId(), $request->note(), $user));
    }

    public function unassign(Request $request, string $activityId, string $studentId): JsonResponse
    {
        $this->requireAssigner($request->user());

        return response()->json($this->regrades->unassign($activityId, $studentId));
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->staff->canRegrade($user), 403, 'Bạn không có quyền xem danh sách chấm phúc khảo.');

        return response()->json($this->regrades->mine($user));
    }
}
