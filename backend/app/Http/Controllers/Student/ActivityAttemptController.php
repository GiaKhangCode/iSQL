<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\ActivityAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lượt làm bài tập / ca thi của sinh viên. Mọi logic nằm ở ActivityAttemptService.
 */
class ActivityAttemptController extends Controller
{
    public function __construct(protected readonly ActivityAttemptService $attempts)
    {
    }

    public function show(Request $request, string $activityId): JsonResponse
    {
        $source = (string) $request->query('source', 'Assignments');

        return response()->json($this->attempts->show($request, $request->user(), $activityId, $source));
    }

    public function start(Request $request, string $activityId): JsonResponse
    {
        return response()->json(
            $this->attempts->start($request, $request->user(), $activityId, $this->sourceOf($request))
        );
    }

    public function updateAction(Request $request, string $activityId, string $action): JsonResponse
    {
        return response()->json(
            $this->attempts->applyAction($request, $request->user(), $activityId, $action, $this->sourceOf($request), $request->all())
        );
    }

    private function sourceOf(Request $request): string
    {
        return (string) ($request->input('source') ?? 'Assignments');
    }
}
