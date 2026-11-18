<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignClassInstructorRequest;
use App\Http\Requests\Admin\StoreClassRequest;
use App\Http\Requests\Admin\SyncClassInstructorsRequest;
use App\Http\Requests\Admin\UpdateClassRequest;
use App\Services\ActivityLogService;
use App\Services\ClassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    public function __construct(
        protected ClassService $classService,
        protected ActivityLogService $activityLog,
    ) {
    }

    public function index(): JsonResponse
    {
        $classes = $this->classService->getAllClasses();
        $formatted = $classes->map(fn ($class) => $this->classService->formatClassResponse($class));
        return response()->json($formatted);
    }

    public function store(StoreClassRequest $request): JsonResponse
    {
        $class = $this->classService->createManagedClass($request->validated());

        $this->activityLog->record($request->user()?->id, "Created class {$class->id}");

        return response()->json($this->classService->formatClassResponse($class), 201);
    }

    public function show(string $id): JsonResponse
    {
        $class = $this->classService->getClass($id);
        return response()->json($this->classService->formatClassResponse($class));
    }

    public function update(UpdateClassRequest $request, string $id): JsonResponse
    {
        $class = $this->classService->updateClass($id, $request->validated());

        $this->activityLog->record($request->user()?->id, "Updated class {$class->id}");

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function assignInstructor(AssignClassInstructorRequest $request, string $id): JsonResponse
    {
        $assignmentType = $request->assignmentType();
        $instructorId = $request->instructorId();

        if ($instructorId === null) {
            abort(422, 'Cần cung cấp instructor_id hoặc lecturer_id.');
        }

        $class = $this->classService->assignInstructor($id, $assignmentType, $instructorId);

        $this->activityLog->record(
            $request->user()?->id,
            "Assigned instructor {$instructorId} ({$assignmentType}) to class {$id}"
        );

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function syncInstructors(SyncClassInstructorsRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        $class = $this->classService->syncInstructorAssignments(
            $id,
            $data['theory_instructor_id'],
            $data['practical_instructor_id']
        );

        $this->activityLog->record($request->user()?->id, "Synced instructors for class {$id}");

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function removeInstructorAssignment(Request $request, string $id, string $assignmentType): JsonResponse
    {
        $class = $this->classService->removeInstructorAssignment($id, $assignmentType);

        $this->activityLog->record($request->user()?->id, "Removed {$assignmentType} instructor from class {$id}");

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->classService->deleteClass($id);

        $this->activityLog->record($request->user()?->id, "Deleted class {$id}");

        return response()->json(['message' => 'Class deleted successfully']);
    }
}
