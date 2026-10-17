<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProblemRequest;
use App\Http\Requests\RunQueryRequest;
use App\Services\ProblemService;
use Illuminate\Http\JsonResponse;

class ProblemController extends Controller
{
    protected ProblemService $problemService;

    public function __construct(ProblemService $problemService)
    {
        $this->problemService = $problemService;
    }

    public function index(): JsonResponse
    {
        $problems = $this->problemService->getAllProblems();
        return response()->json($problems);
    }

    public function show(string $id): JsonResponse
    {
        $problem = $this->problemService->getProblemById($id);
        return response()->json($problem);
    }

    public function store(ProblemRequest $request): JsonResponse
    {
        $problem = $this->problemService->createProblem($request->validated(), $request->user()->id);
        return response()->json($problem, 201);
    }

    public function update(ProblemRequest $request, string $id): JsonResponse
    {
        $problem = $this->problemService->updateProblem($id, $request->validated());
        return response()->json($problem);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->problemService->deleteProblem($id);
        return response()->json(['message' => 'Problem deleted successfully']);
    }

    public function runQuery(RunQueryRequest $request, string $id): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => 'Query executed',
            'columns' => [],
            'rows'    => [],
        ]);
    }

    public function submit(RunQueryRequest $request, string $id): JsonResponse
    {
        return response()->json([
            'result' => 'Accepted',
            'score'  => 100,
        ]);
    }
}
