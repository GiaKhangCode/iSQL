<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProblemRequest;
use App\Http\Requests\RunQueryRequest;
use App\Services\ProblemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProblemController extends Controller
{
    public function __construct(protected readonly ProblemService $problemService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $problems = $this->problemService->getAllProblems($request->user());
        return response()->json($problems);
    }

    public function trending(Request $request): JsonResponse
    {
        $range = $request->query('range', 'Week');
        $trending = $this->problemService->getTrendingProblems($range);
        return response()->json($trending);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $context = $request->query('context');
        $problem = $this->problemService->getProblemById($id, $request->user(), $context);
        return response()->json($problem);
    }

    public function store(ProblemRequest $request): JsonResponse
    {
        $problem = $this->problemService->createProblem($request->validated(), $request->user());
        return response()->json($problem, 201);
    }

    public function update(ProblemRequest $request, string $id): JsonResponse
    {
        $problem = $this->problemService->updateProblem($id, $request->validated(), $request->user());
        return response()->json($problem);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->problemService->deleteProblem($id, $request->user());
        return response()->json(['message' => 'Problem deleted successfully']);
    }

    public function validateSolution(Request $request): JsonResponse
    {
        $result = $this->problemService->validateSolution($request->all(), $request->user());
        if (($result['status'] ?? '') === 'Error') {
            return response()->json([
                'detail'  => $result['message'] ?? 'Validation failed.',
                'message' => $result['message'] ?? 'Validation failed.',
            ], 400);
        }
        return response()->json($result);
    }

    public function validateStage(Request $request): JsonResponse
    {
        $result = $this->problemService->validateStage($request->all(), $request->user());
        if (($result['status'] ?? '') === 'Error') {
            return response()->json([
                'detail'  => $result['message'] ?? 'Stage validation failed.',
                'message' => $result['message'] ?? 'Stage validation failed.',
            ], 400);
        }
        return response()->json($result);
    }

    public function runQuery(RunQueryRequest $request, string $id): JsonResponse
    {
        $result = $this->problemService->runQuery($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function submit(RunQueryRequest $request, string $id): JsonResponse
    {
        $result = $this->problemService->submitQuery($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function runWorkspace(Request $request, string $id): JsonResponse
    {
        $result = $this->problemService->runWorkspace($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function questionDatabase(Request $request, string $id): JsonResponse
    {
        $result = $this->problemService->getQuestionDatabase($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function expectedOutput(Request $request, string $id): JsonResponse
    {
        $result = $this->problemService->getExpectedOutput($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function studentDatabase(Request $request, string $id): JsonResponse
    {
        $result = $this->problemService->getStudentDatabase($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function resetStudentDatabase(Request $request, string $id): JsonResponse
    {
        $result = $this->problemService->resetStudentDatabase($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function submitWorkspace(Request $request, string $id): JsonResponse
    {
        $result = $this->problemService->submitWorkspace($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function submitWorkspaceQuestion(Request $request, string $id): JsonResponse
    {
        $result = $this->problemService->submitWorkspaceQuestion($id, $request->all(), $request->user());
        return response()->json($result);
    }
}
