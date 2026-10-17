<?php

namespace App\Http\Controllers\Contest;

use App\Http\Controllers\Controller;
use App\Http\Requests\RunQueryRequest;
use App\Http\Requests\SubmitExamRequest;
use App\Services\SqlServer\SandboxService;
use App\Services\Grading\ContestGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    protected SandboxService $sandboxService;
    protected ContestGradingService $gradingService;

    public function __construct(SandboxService $sandboxService, ContestGradingService $gradingService)
    {
        $this->sandboxService = $sandboxService;
        $this->gradingService = $gradingService;
    }

    public function setupDb(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => 'Sandbox database schema initialized for contest ' . $id,
        ]);
    }

    public function runDdl(Request $request, string $id): JsonResponse
    {
        $request->validate(['ddl_script' => 'required|string']);

        return response()->json([
            'status'  => 'success',
            'message' => 'DDL executed successfully',
        ]);
    }

    public function seedDml(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => 'DML seeded successfully',
        ]);
    }

    public function inspectSchema(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'tables' => [],
        ]);
    }

    public function runQuery(RunQueryRequest $request, string $id): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'columns' => [],
            'rows'    => [],
        ]);
    }

    public function submitExam(SubmitExamRequest $request, string $id): JsonResponse
    {
        return response()->json([
            'status'  => 'submitted',
            'message' => 'Exam submission received for evaluation',
        ]);
    }
}
