<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\ProblemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PracticeController extends Controller
{
    protected ProblemService $problemService;

    public function __construct(ProblemService $problemService)
    {
        $this->problemService = $problemService;
    }

    public function index(Request $request): JsonResponse
    {
        $problems = $this->problemService->getAllProblems(true);
        return response()->json($problems);
    }
}
