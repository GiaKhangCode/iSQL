<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\User;
use App\Services\ClassService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClassRosterController extends Controller
{
    public function __construct(
        private readonly ClassService $classService,
        private readonly UserService $userService,
    ) {
    }

    public function index(Request $request, string $id): JsonResponse
    {
        $class = $this->accessibleClass($request->user(), $id);

        return response()->json($class->students()->orderBy('name')->get());
    }

    public function add(Request $request, string $id): JsonResponse
    {
        $this->assertCanManageRoster($request->user(), $id);
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'student_ids.*' => ['required', 'string', 'distinct', 'exists:users,id'],
        ]);

        $class = $this->classService->addStudents($id, $data['student_ids'], $this->ownerFilter($request->user()));

        return response()->json($class->students()->orderBy('name')->get(), 201);
    }

    public function replace(Request $request, string $id): JsonResponse
    {
        $this->assertCanManageRoster($request->user(), $id);
        $data = $request->validate([
            'student_ids' => ['present', 'array', 'max:1000'],
            'student_ids.*' => ['required', 'string', 'distinct', 'exists:users,id'],
        ]);

        $class = $this->classService->replaceStudents($id, $data['student_ids'], $this->ownerFilter($request->user()));

        return response()->json($class->students()->orderBy('name')->get());
    }

    public function remove(Request $request, string $id, string $studentId): JsonResponse
    {
        $this->assertCanManageRoster($request->user(), $id);
        $class = $this->classService->removeStudent($id, $studentId, $this->ownerFilter($request->user()));

        return response()->json($class->students()->orderBy('name')->get());
    }

    public function resetStudentPassword(Request $request, string $id, string $studentId): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, [User::ROLE_INSTRUCTOR_PRACTICAL, User::LEGACY_INSTRUCTOR_ROLE], true)) {
            abort(403, 'Only practical instructors can reset passwords for students in their own classes.');
        }

        $class = $this->classService->getClass($id, $user->id);
        if (! $class->students()->where('users.id', $studentId)->exists()) {
            throw ValidationException::withMessages(['student_id' => ['The student is not enrolled in this class.']]);
        }
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);
        $this->userService->resetStudentPassword($studentId, $data['password']);

        return response()->json(['message' => 'Student password reset successfully']);
    }

    private function accessibleClass(User $user, string $id): ClassModel
    {
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_DEPARTMENT_HEAD], true)) {
            return $this->classService->getClass($id);
        }
        if (in_array($user->role, [...User::INSTRUCTOR_ROLES, User::ROLE_DEPARTMENT_HEAD], true)) {
            return $this->classService->getClass($id, $user->id);
        }

        abort(403, 'Permission denied');
    }

    private function assertCanManageRoster(User $user, string $id): void
    {
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_DEPARTMENT_HEAD], true)) {
            $this->classService->getClass($id);
            return;
        }
        if (in_array($user->role, [User::ROLE_INSTRUCTOR_PRACTICAL, User::LEGACY_INSTRUCTOR_ROLE], true)) {
            $this->classService->getClass($id, $user->id);
            return;
        }

        abort(403, 'This role can view the class roster but cannot edit it.');
    }

    private function ownerFilter(User $user): ?string
    {
        return in_array($user->role, [User::ROLE_INSTRUCTOR_PRACTICAL, User::LEGACY_INSTRUCTOR_ROLE], true)
            ? $user->id
            : null;
    }
}
