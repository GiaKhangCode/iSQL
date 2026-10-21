<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        return response()->json($classes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course'        => 'required_without:course_id|string|max:255',
            'course_id'     => 'required_without:course|nullable|string|exists:courses,id',
            'term'          => 'required_without:term_id|string|max:100',
            'term_id'       => 'required_without:term|nullable|string|exists:academic_terms,id',
            'start_date'    => 'sometimes|nullable|date',
            'end_date'      => 'sometimes|nullable|date|after_or_equal:start_date',
            'status'        => 'sometimes|in:Active,Archived',
        ]);

        return response()->json($this->classService->createManagedClass($data), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->classService->getClass($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'course'        => 'sometimes|required|string|max:255',
            'course_id'     => 'sometimes|nullable|string|exists:courses,id',
            'term'          => 'sometimes|required|string|max:100',
            'term_id'       => 'sometimes|nullable|string|exists:academic_terms,id',
            'start_date'    => 'sometimes|nullable|date',
            'end_date'      => 'sometimes|nullable|date',
            'status'        => 'sometimes|in:Active,Archived',
        ]);

        return response()->json($this->classService->updateClass($id, $data));
    }

    public function assignInstructor(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'assignment_type' => ['required', Rule::in(['theory', 'practical'])],
            'instructor_id' => [
                'required',
                'string',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereIn('role', User::INSTRUCTOR_ROLES)
                    ->where('status', 'Active')),
            ],
        ]);

        return response()->json(
            $this->classService->assignInstructor($id, $data['assignment_type'], $data['instructor_id'])
        );
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

        return response()->json($this->classService->syncInstructorAssignments(
            $id,
            $data['theory_instructor_id'],
            $data['practical_instructor_id']
        ));
    }

    public function removeInstructorAssignment(string $id, string $assignmentType): JsonResponse
    {
        return response()->json($this->classService->removeInstructorAssignment($id, $assignmentType));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->classService->deleteClass($id);

        return response()->json(['message' => 'Class deleted successfully']);
    }
}
