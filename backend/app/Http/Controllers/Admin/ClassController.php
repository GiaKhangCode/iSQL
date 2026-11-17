<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ClassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClassController extends Controller
{
    protected ClassService $classService;

    public function __construct(ClassService $classService)
    {
        $this->classService = $classService;
    }

    public function index(): JsonResponse
    {
        $classes = $this->classService->getAllClasses();
        $formatted = $classes->map(fn ($class) => $this->classService->formatClassResponse($class));
        return response()->json($formatted);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'              => 'sometimes|string|max:50|unique:classes,id',
            'course'          => 'required_without:course_id|string|max:255',
            'course_id'       => 'required_without:course|nullable|string|exists:courses,id',
            'term'            => 'required_without_all:term_id,semester|string|max:100',
            'semester'        => 'sometimes|string|max:100',
            'term_id'         => 'required_without_all:term,semester|nullable|string|exists:academic_terms,id',
            'start_date'      => 'sometimes|nullable|date',
            'startDate'       => 'sometimes|nullable|date',
            'end_date'        => 'sometimes|nullable|date|after_or_equal:start_date',
            'endDate'         => 'sometimes|nullable|date',
            'status'          => 'sometimes|in:Active,Archived,Draft',
            'lecturer_id'     => 'sometimes|nullable|string',
            'lecturerId'      => 'sometimes|nullable|string',
            'instructor_id'   => 'sometimes|nullable|string',
            'assignment_type' => ['sometimes', 'string', Rule::in(['theory', 'practical'])],
        ]);

        $class = $this->classService->createManagedClass($data);

        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'action'  => "Created class {$class->id}",
        ]);

        return response()->json($this->classService->formatClassResponse($class), 201);
    }

    public function show(string $id): JsonResponse
    {
        $class = $this->classService->getClass($id);
        return response()->json($this->classService->formatClassResponse($class));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'course'          => 'sometimes|required|string|max:255',
            'course_id'       => 'sometimes|nullable|string|exists:courses,id',
            'term'            => 'sometimes|required|string|max:100',
            'semester'        => 'sometimes|string|max:100',
            'term_id'         => 'sometimes|nullable|string|exists:academic_terms,id',
            'start_date'      => 'sometimes|nullable|date',
            'startDate'       => 'sometimes|nullable|date',
            'end_date'        => 'sometimes|nullable|date',
            'endDate'         => 'sometimes|nullable|date',
            'status'          => 'sometimes|in:Active,Archived,Draft',
            'lecturer_id'     => 'sometimes|nullable|string',
            'lecturerId'      => 'sometimes|nullable|string',
            'instructor_id'   => 'sometimes|nullable|string',
            'assignment_type' => ['sometimes', 'string', Rule::in(['theory', 'practical'])],
        ]);

        $class = $this->classService->updateClass($id, $data);

        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'action'  => "Updated class {$class->id}",
        ]);

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function assignInstructor(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'assignment_type' => ['sometimes', 'nullable', Rule::in(['theory', 'practical'])],
            'instructor_id'   => ['sometimes', 'nullable', 'string'],
            'lecturer_id'     => ['sometimes', 'nullable', 'string'],
            'lecturerId'      => ['sometimes', 'nullable', 'string'],
        ]);

        $assignmentType = $data['assignment_type'] ?? 'practical';
        $instructorId = $data['instructor_id'] ?? $data['lecturer_id'] ?? $data['lecturerId'] ?? null;

        if ($instructorId === null) {
            abort(422, 'Cần cung cấp instructor_id hoặc lecturer_id.');
        }

        $class = $this->classService->assignInstructor($id, $assignmentType, $instructorId);

        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'action'  => "Assigned instructor {$instructorId} ({$assignmentType}) to class {$id}",
        ]);

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function syncInstructors(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'theory_instructor_id' => ['required', 'string', Rule::exists('users', 'id')->where(fn ($query) => $query
                ->whereIn('role', User::INSTRUCTOR_ROLES)
                ->where('status', 'Active'))],
            'practical_instructor_id' => ['required', 'string', Rule::exists('users', 'id')->where(fn ($query) => $query
                ->whereIn('role', User::INSTRUCTOR_ROLES)
                ->where('status', 'Active'))],
        ]);

        $class = $this->classService->syncInstructorAssignments(
            $id,
            $data['theory_instructor_id'],
            $data['practical_instructor_id']
        );

        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'action'  => "Synced instructors for class {$id}",
        ]);

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function removeInstructorAssignment(string $id, string $assignmentType): JsonResponse
    {
        $class = $this->classService->removeInstructorAssignment($id, $assignmentType);

        ActivityLog::create([
            'user_id' => request()->user()?->id,
            'action'  => "Removed {$assignmentType} instructor from class {$id}",
        ]);

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->classService->deleteClass($id);

        ActivityLog::create([
            'user_id' => request()->user()?->id,
            'action'  => "Deleted class {$id}",
        ]);

        return response()->json(['message' => 'Class deleted successfully']);
    }
}

