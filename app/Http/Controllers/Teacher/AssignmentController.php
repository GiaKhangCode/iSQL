<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignmentRequest;
use App\Services\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    protected AssignmentService $assignmentService;

    public function __construct(AssignmentService $assignmentService)
    {
        $this->assignmentService = $assignmentService;
    }

    public function index(): JsonResponse
    {
        $assignments = $this->assignmentService->getAllAssignments();
        return response()->json($assignments);
    }

    public function store(AssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignmentService->createAssignment(
            $request->validated(),
            $request->user()->id
        );

        return response()->json($assignment, 201);
    }

    public function update(AssignmentRequest $request, string $id): JsonResponse
    {
        $assignment = $this->assignmentService->updateAssignment($id, $request->validated());
        return response()->json($assignment);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->assignmentService->deleteAssignment($id);
        return response()->json(['message' => 'Assignment deleted successfully']);
    }
}
