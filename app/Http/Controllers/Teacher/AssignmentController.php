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

    public function index(Request $request): JsonResponse
    {
        $assignments = $this->assignmentService->getAllAssignments($request->user());
        return response()->json($assignments);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $assignment = $this->assignmentService->getAssignmentById($id, $request->user());
        return response()->json($assignment);
    }

    public function audienceCount(Request $request): JsonResponse
    {
        $audienceType = $request->query('audience_type', 'classes');
        $classIds = (array) $request->query('class_ids', []);

        $count = $this->assignmentService->getAudienceCount($audienceType, $classIds, $request->user());
        return response()->json([
            'eligibleStudents'  => $count,
            'eligible_students' => $count,
            'class_count'       => count($classIds),
        ]);
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
        $assignment = $this->assignmentService->updateAssignment(
            $id,
            $request->validated(),
            $request->user()
        );

        return response()->json($assignment);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assignmentService->deleteAssignment($id, $request->user());
        return response()->json(['message' => 'Assignment deleted successfully']);
    }
}
