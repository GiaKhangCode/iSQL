<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClassController extends Controller
{
    public function __construct(private readonly ClassService $classService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $classes = $user->role === User::ROLE_DEPARTMENT_HEAD
            ? $this->classService->getAllClasses()
            : $this->classService->getInstructorClasses($user->id);

        return response()->json($classes);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $class = $user->role === User::ROLE_DEPARTMENT_HEAD
            ? $this->classService->getClass($id)
            : $this->classService->getClass($id, $user->id);

        return response()->json($class);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role === User::ROLE_INSTRUCTOR_THEORY) {
            throw ValidationException::withMessages(['role' => ['Theory instructors cannot create classes.']]);
        }
        if (! in_array($user->role, [User::ROLE_DEPARTMENT_HEAD, User::ROLE_INSTRUCTOR_PRACTICAL, User::LEGACY_INSTRUCTOR_ROLE], true)) {
            abort(403, 'Permission denied');
        }

        $data = $request->validate([
            'course' => ['required_without:course_id', 'string', 'max:255'],
            'course_id' => ['required_without:course', 'nullable', 'string', 'exists:courses,id'],
            'term' => ['required_without:term_id', 'string', 'max:100'],
            'term_id' => ['required_without:term', 'nullable', 'string', 'exists:academic_terms,id'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'in:Active,Archived'],
        ]);

        $class = $this->classService->createManagedClass(
            $data,
            in_array($user->role, [User::ROLE_INSTRUCTOR_PRACTICAL, User::LEGACY_INSTRUCTOR_ROLE], true) ? $user->id : null
        );

        return response()->json($class, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user->role === User::ROLE_INSTRUCTOR_THEORY) {
            throw ValidationException::withMessages(['role' => ['Theory instructors have read-only access to classes.']]);
        }

        $instructorId = in_array($user->role, [User::ROLE_INSTRUCTOR_PRACTICAL, User::LEGACY_INSTRUCTOR_ROLE], true)
            ? $user->id
            : null;
        $data = $request->validate([
            'course' => ['sometimes', 'required', 'string', 'max:255'],
            'course_id' => ['sometimes', 'nullable', 'string', 'exists:courses,id'],
            'term' => ['sometimes', 'required', 'string', 'max:100'],
            'term_id' => ['sometimes', 'nullable', 'string', 'exists:academic_terms,id'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'in:Active,Archived'],
        ]);

        return response()->json($this->classService->updateClass($id, $data, $instructorId));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user->role === User::ROLE_INSTRUCTOR_THEORY) {
            throw ValidationException::withMessages(['role' => ['Theory instructors cannot delete classes.']]);
        }
        $instructorId = in_array($user->role, [User::ROLE_INSTRUCTOR_PRACTICAL, User::LEGACY_INSTRUCTOR_ROLE], true)
            ? $user->id
            : null;

        $this->classService->deleteClass($id, $instructorId);

        return response()->json(['message' => 'Class deleted successfully']);
    }

    public function assignInstructor(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()->role === User::ROLE_DEPARTMENT_HEAD, 403, 'Only department heads can assign instructors.');

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

        return response()->json($this->classService->assignInstructor($id, $data['assignment_type'], $data['instructor_id']));
    }

    public function syncInstructors(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()->role === User::ROLE_DEPARTMENT_HEAD, 403, 'Only department heads can assign instructors.');

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

    public function removeInstructorAssignment(Request $request, string $id, string $assignmentType): JsonResponse
    {
        abort_unless($request->user()->role === User::ROLE_DEPARTMENT_HEAD, 403, 'Only department heads can assign instructors.');

        return response()->json($this->classService->removeInstructorAssignment($id, $assignmentType));
    }
}
