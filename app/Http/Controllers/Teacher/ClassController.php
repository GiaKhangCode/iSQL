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
        $canManageAll = $user->isAdmin()
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->role === 'admin';

        $classes = $canManageAll
            ? $this->classService->getAllClasses()
            : $this->classService->getInstructorClasses($user->id);

        $formatted = $classes->map(fn ($class) => $this->classService->formatClassResponse($class));

        return response()->json($formatted);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $canManageAll = $user->isAdmin()
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->role === 'admin';

        $class = $canManageAll
            ? $this->classService->getClass($id)
            : $this->classService->getClass($id, $user->id);

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->canDo('CLASS_MGMT', 'add')) {
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

        $canManageAll = $user->isAdmin() || $user->role === User::ROLE_DEPARTMENT_HEAD || $user->role === 'admin';

        $class = $this->classService->createManagedClass(
            $data,
            !$canManageAll ? $user->id : null
        );

        return response()->json($this->classService->formatClassResponse($class), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user->canDo('CLASS_MGMT', 'edit')) {
            abort(403, 'Permission denied');
        }

        $canManageAll = $user->isAdmin() || $user->role === User::ROLE_DEPARTMENT_HEAD || $user->role === 'admin';
        $instructorId = !$canManageAll ? $user->id : null;

        $data = $request->validate([
            'course' => ['sometimes', 'required', 'string', 'max:255'],
            'course_id' => ['sometimes', 'nullable', 'string', 'exists:courses,id'],
            'term' => ['sometimes', 'required', 'string', 'max:100'],
            'term_id' => ['sometimes', 'nullable', 'string', 'exists:academic_terms,id'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'in:Active,Archived'],
        ]);

        $class = $this->classService->updateClass($id, $data, $instructorId);

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user->canDo('CLASS_MGMT', 'delete')) {
            abort(403, 'Permission denied');
        }

        $canManageAll = $user->isAdmin() || $user->role === User::ROLE_DEPARTMENT_HEAD || $user->role === 'admin';
        $instructorId = !$canManageAll ? $user->id : null;

        $this->classService->deleteClass($id, $instructorId);

        return response()->json(['message' => 'Class deleted successfully']);
    }

    public function assignInstructor(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->role === User::ROLE_DEPARTMENT_HEAD, 403, 'Chỉ Trưởng bộ môn hoặc Quản trị viên mới có thể phân công giảng viên.');

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

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function syncInstructors(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->role === User::ROLE_DEPARTMENT_HEAD, 403, 'Chỉ Trưởng bộ môn hoặc Quản trị viên mới có thể phân công giảng viên.');

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

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function removeInstructorAssignment(Request $request, string $id, string $assignmentType): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->role === User::ROLE_DEPARTMENT_HEAD, 403, 'Chỉ Trưởng bộ môn hoặc Quản trị viên mới có thể phân công giảng viên.');

        $class = $this->classService->removeInstructorAssignment($id, $assignmentType);

        return response()->json($this->classService->formatClassResponse($class));
    }
}
