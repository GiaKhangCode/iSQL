<?php

namespace App\Http\Controllers;

use App\Http\Requests\Teacher\AddClassStudentsRequest;
use App\Http\Requests\Teacher\ReplaceClassStudentsRequest;
use App\Http\Requests\Teacher\ResetClassStudentPasswordRequest;
use App\Services\ClassRosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Danh sách sinh viên của lớp. Toàn bộ phân quyền theo lớp và logic nằm ở ClassRosterService.
 */
class ClassRosterController extends Controller
{
    public function __construct(private readonly ClassRosterService $roster)
    {
    }

    public function index(Request $request, string $id): JsonResponse
    {
        return response()->json($this->roster->students($request->user(), $id));
    }

    public function add(AddClassStudentsRequest $request, string $id): JsonResponse
    {
        $students = $this->roster->addStudents($request->user(), $id, $request->validated('student_ids'));

        return response()->json($students, 201);
    }

    public function replace(ReplaceClassStudentsRequest $request, string $id): JsonResponse
    {
        $students = $this->roster->replaceStudents($request->user(), $id, $request->validated('student_ids'));

        return response()->json($students);
    }

    public function remove(Request $request, string $id, string $studentId): JsonResponse
    {
        return response()->json($this->roster->removeStudent($request->user(), $id, $studentId));
    }

    public function getAllStudents(Request $request): JsonResponse
    {
        return response()->json($this->roster->allActiveStudents($request->user()));
    }

    public function getMembers(Request $request, string $id): JsonResponse
    {
        return response()->json($this->roster->members($request->user(), $id));
    }

    public function addMember(Request $request, string $id): JsonResponse
    {
        $member = $this->roster->addMember(
            $request->user(),
            $id,
            $request->only(['student_id', 'email', 'student_ids'])
        );

        return response()->json($member, 201);
    }

    public function removeMember(Request $request, string $id, string $studentId): JsonResponse
    {
        $this->roster->removeMember($request->user(), $id, $studentId);

        return response()->json(['message' => 'Member removed successfully']);
    }

    /**
     * Import sinh viên vào lớp học từ tệp CSV hoặc TXT.
     */
    public function importMembers(Request $request, string $id): JsonResponse
    {
        $report = $this->roster->importMembers($request->user(), $id, $request->file('file'));

        return response()->json($report, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function resetStudentPassword(ResetClassStudentPasswordRequest $request, string $id, string $studentId): JsonResponse
    {
        $this->roster->resetStudentPassword($request->user(), $id, $studentId, $request->validated('password'));

        return response()->json(['message' => 'Student password reset successfully']);
    }
}
