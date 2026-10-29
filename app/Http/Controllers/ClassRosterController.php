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

    public function getAllStudents(Request $request): JsonResponse
    {
        $students = User::where('role', User::ROLE_STUDENT)
            ->where('status', 'Active')
            ->orderBy('name')
            ->get();

        return response()->json($students->map(fn ($s) => [
            'id'        => $s->id,
            'name'      => $s->name,
            'email'     => $s->email,
            'role'      => 'student',
            'joined_at' => $s->created_at ? $s->created_at->toIso8601String() : null,
            'joinedAt'  => $s->created_at ? $s->created_at->toIso8601String() : null,
        ]));
    }

    public function getMembers(Request $request, string $id): JsonResponse
    {
        $class = $this->accessibleClass($request->user(), $id);
        $members = $class->students()->orderBy('name')->get();

        return response()->json($members->map(fn ($m) => [
            'id'        => $m->id,
            'name'      => $m->name,
            'email'     => $m->email,
            'role'      => $m->pivot->role ?? 'Member',
            'joined_at' => $m->pivot->joined_at,
            'joinedAt'  => $m->pivot->joined_at,
        ]));
    }

    public function addMember(Request $request, string $id): JsonResponse
    {
        $this->assertCanManageRoster($request->user(), $id);

        $studentIdentifier = $request->input('student_id') ?? $request->input('email');
        if (! $studentIdentifier && $request->has('student_ids')) {
            $ids = (array) $request->input('student_ids');
            $studentIdentifier = $ids[0] ?? null;
        }

        if (! $studentIdentifier) {
            return response()->json(['detail' => 'student_id is required'], 422);
        }

        $student = User::find($studentIdentifier);
        if (! $student) {
            $student = User::whereRaw('LOWER(email) = ?', [mb_strtolower($studentIdentifier)])->first();
        }
        if (! $student && ! str_contains($studentIdentifier, '@')) {
            $student = User::whereRaw('LOWER(email) LIKE ?', [mb_strtolower($studentIdentifier) . '@%'])->first();
        }

        if (! $student) {
            return response()->json(['detail' => 'Không tìm thấy sinh viên.'], 404);
        }

        if ($student->role !== User::ROLE_STUDENT && $student->role !== 'student') {
            return response()->json(['detail' => 'Tài khoản này không phải là sinh viên.'], 422);
        }

        $class = $this->accessibleClass($request->user(), $id);
        $already = $class->students()->where('users.id', $student->id)->exists();
        if ($already) {
            return response()->json(['detail' => 'Sinh viên này đã được thêm vào lớp từ trước.'], 400);
        }

        $joinedAt = now();
        $class->students()->attach($student->id, [
            'role' => 'Member',
            'joined_at' => $joinedAt,
        ]);

        return response()->json([
            'id'        => $student->id,
            'name'      => $student->name,
            'email'     => $student->email,
            'role'      => 'Member',
            'joined_at' => $joinedAt->toIso8601String(),
            'joinedAt'  => $joinedAt->toIso8601String(),
        ], 201);
    }

    public function removeMember(Request $request, string $id, string $studentId): JsonResponse
    {
        $this->assertCanManageRoster($request->user(), $id);
        $class = $this->accessibleClass($request->user(), $id);

        $student = User::find($studentId) ?: User::whereRaw('LOWER(email) = ?', [mb_strtolower($studentId)])->first();
        $targetId = $student ? $student->id : $studentId;

        $exists = $class->students()->where('users.id', $targetId)->exists();
        if (! $exists) {
            return response()->json(['detail' => 'Sinh viên không có trong lớp học này.'], 404);
        }

        $class->students()->detach($targetId);

        return response()->json(['message' => 'Member removed successfully']);
    }

    /**
     * Import sinh viên vào lớp học từ tệp CSV hoặc TXT
     */
    public function importMembers(Request $request, string $id): JsonResponse
    {
        $this->assertCanManageRoster($request->user(), $id);
        $class = $this->accessibleClass($request->user(), $id);

        if (! $request->hasFile('file') || ! $request->file('file')->isValid()) {
            return response()->json(['detail' => 'Vui lòng tải lên tệp danh sách sinh viên hợp lệ (.csv hoặc .txt).'], 422);
        }

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'], true)) {
            return response()->json(['detail' => 'Định dạng tệp không được hỗ trợ. Vui lòng sử dụng tệp .csv hoặc .txt.'], 422);
        }

        $rawContent = file_get_contents($file->getRealPath());
        if ($rawContent === false || trim($rawContent) === '') {
            return response()->json([
                'total_processed' => 0,
                'success_count'   => 0,
                'skipped_count'   => 0,
                'failed_count'    => 0,
                'errors'          => [['email' => 'file', 'error' => 'Tệp rỗng hoặc không có dữ liệu.']],
            ]);
        }

        // Kiểm tra nếu tệp thực chất là tệp bảng tính Excel (.xlsx) nhị phân bị đổi đuôi
        if (str_starts_with($rawContent, "PK\x03\x04")) {
            return response()->json([
                'detail' => 'Tệp tải lên là tệp Excel (.xlsx), không phải tệp văn bản CSV. Vui lòng mở tệp trong Excel, chọn "Save As" (Lưu tệp dưới dạng) và chọn định dạng "CSV UTF-8 (Comma delimited) (*.csv)".',
            ], 422);
        }

        // Loại bỏ UTF-8 BOM nếu có
        $rawContent = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent);

        // Đảm bảo nội dung là UTF-8 hợp lệ
        if (! mb_check_encoding($rawContent, 'UTF-8')) {
            $converted = @mb_convert_encoding($rawContent, 'UTF-8', 'UTF-16,WINDOWS-1258,CP1252,ISO-8859-1');
            if ($converted && mb_check_encoding($converted, 'UTF-8')) {
                $rawContent = $converted;
            } else {
                $rawContent = mb_convert_encoding($rawContent, 'UTF-8', 'UTF-8');
            }
        }

        // Trích xuất danh sách sinh viên từ nội dung tệp
        $identifiers = $this->extractStudentIdentifiers($rawContent, $extension);

        $successCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $errors = [];
        $processedInThisBatch = [];

        foreach ($identifiers as $identifier) {
            $identifier = trim((string) $identifier);
            if ($identifier === '') {
                continue;
            }

            $lowerIdentifier = mb_strtolower($identifier);
            if (isset($processedInThisBatch[$lowerIdentifier])) {
                $skippedCount++;
                $errors[] = [
                    'email' => mb_convert_encoding($identifier, 'UTF-8', 'UTF-8'),
                    'error' => 'Trùng lặp trong tệp tải lên.',
                ];
                continue;
            }
            $processedInThisBatch[$lowerIdentifier] = true;

            // Tìm người dùng theo Email, ID, hoặc MSSV (tiền tố email)
            $user = null;
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                $user = User::whereRaw('LOWER(email) = ?', [$lowerIdentifier])->first();
            } else {
                $user = User::whereRaw('LOWER(email) = ?', [$lowerIdentifier])
                    ->orWhere('id', $identifier)
                    ->orWhereRaw('LOWER(email) LIKE ?', [$lowerIdentifier . '@%'])
                    ->first();
            }

            if (! $user) {
                $failedCount++;
                $errors[] = [
                    'email' => mb_convert_encoding($identifier, 'UTF-8', 'UTF-8'),
                    'error' => "Không tìm thấy người dùng với thông tin: {$identifier}.",
                ];
                continue;
            }

            if ($user->role !== User::ROLE_STUDENT && $user->role !== 'student') {
                $failedCount++;
                $errors[] = [
                    'email' => mb_convert_encoding($identifier, 'UTF-8', 'UTF-8'),
                    'error' => "Tài khoản ({$user->name}) không phải là sinh viên (vai trò: {$user->role}).",
                ];
                continue;
            }

            $alreadyEnrolled = $class->students()->where('users.id', $user->id)->exists();
            if ($alreadyEnrolled) {
                $skippedCount++;
                $errors[] = [
                    'email' => mb_convert_encoding($identifier, 'UTF-8', 'UTF-8'),
                    'error' => 'Sinh viên đã tham gia lớp học từ trước.',
                ];
                continue;
            }

            $class->students()->attach($user->id, [
                'role'      => 'Member',
                'joined_at' => now(),
            ]);
            $successCount++;
        }

        $totalProcessed = $successCount + $skippedCount + $failedCount;

        return response()->json([
            'total_processed' => $totalProcessed,
            'success_count'   => $successCount,
            'skipped_count'   => $skippedCount,
            'failed_count'    => $failedCount,
            'errors'          => $errors,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Trích xuất danh sách định danh sinh viên từ nội dung tệp CSV hoặc TXT.
     */
    private function extractStudentIdentifiers(string $content, string $extension): array
    {
        $lines = preg_split('/[\r\n]+/', trim($content));
        if (empty($lines)) {
            return [];
        }

        $identifiers = [];
        $firstLine = $lines[0];
        $delimiter = $this->detectDelimiter($firstLine);
        $firstRowCells = str_getcsv($firstLine, $delimiter);

        $emailColIdx = null;
        $mssvColIdx = null;
        $hasHeader = false;

        if (count($firstRowCells) > 1 || $extension === 'csv') {
            foreach ($firstRowCells as $idx => $cell) {
                $clean = mb_strtolower(trim($cell));
                if (in_array($clean, ['email', 'e-mail', 'mail', 'hộp thư', 'hop thu', 'email address'], true)) {
                    $emailColIdx = $idx;
                    $hasHeader = true;
                } elseif (in_array($clean, ['mssv', 'student_id', 'student id', 'mã số sinh viên', 'ma so sinh vien', 'mã sinh viên', 'ma sinh vien', 'student_code'], true)) {
                    $mssvColIdx = $idx;
                    $hasHeader = true;
                }
            }
        }

        $startIndex = $hasHeader ? 1 : 0;

        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $rowCells = str_getcsv($line, $delimiter);
            if (count($rowCells) > 1) {
                $foundIdentifier = null;

                if ($emailColIdx !== null && isset($rowCells[$emailColIdx]) && trim($rowCells[$emailColIdx]) !== '') {
                    $foundIdentifier = trim($rowCells[$emailColIdx]);
                }
                if (! $foundIdentifier && $mssvColIdx !== null && isset($rowCells[$mssvColIdx]) && trim($rowCells[$mssvColIdx]) !== '') {
                    $foundIdentifier = trim($rowCells[$mssvColIdx]);
                }
                if (! $foundIdentifier) {
                    foreach ($rowCells as $cell) {
                        $cell = trim($cell);
                        if (filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                            $foundIdentifier = $cell;
                            break;
                        }
                    }
                }
                if (! $foundIdentifier) {
                    foreach ($rowCells as $cell) {
                        $cell = trim($cell);
                        if (preg_match('/^[a-zA-Z0-9]{6,15}$/', $cell)) {
                            $foundIdentifier = $cell;
                            break;
                        }
                    }
                }

                if ($foundIdentifier) {
                    $identifiers[] = $foundIdentifier;
                }
            } else {
                // Định dạng TXT một dòng hoặc danh sách phân cách bởi dấu phẩy, chấm phẩy
                if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $line, $matches) && ! empty($matches[0])) {
                    foreach ($matches[0] as $em) {
                        $identifiers[] = trim($em);
                    }
                } else {
                    $tokens = preg_split('/[,;]+/', $line);
                    foreach ($tokens as $token) {
                        $token = trim($token, " \t\n\r\0\x0B\"'");
                        if ($token !== '' && ! in_array(mb_strtolower($token), ['email', 'name', 'mssv', 'stt'], true)) {
                            $identifiers[] = $token;
                        }
                    }
                }
            }
        }

        return $identifiers;
    }

    private function detectDelimiter(string $line): string
    {
        $delimiters = [',', ';', "\t"];
        $counts = [];
        foreach ($delimiters as $d) {
            $counts[$d] = substr_count($line, $d);
        }
        arsort($counts);
        $best = array_key_first($counts);

        return ($counts[$best] > 0) ? $best : ',';
    }

    public function resetStudentPassword(Request $request, string $id, string $studentId): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, [User::ROLE_INSTRUCTOR_PRACTICAL, User::LEGACY_INSTRUCTOR_ROLE, User::ROLE_ADMIN, 'admin'], true)) {
            abort(403, 'Only practical instructors can reset passwords for students in their own classes.');
        }

        $class = $this->classService->getClass($id, in_array($user->role, [User::ROLE_ADMIN, 'admin'], true) ? null : $user->id);
        if (! $class->students()->where('users.id', $studentId)->exists()) {
            throw ValidationException::withMessages(['student_id' => ['The student is not enrolled in this class.']]);
        }
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);
        $this->userService->resetStudentPassword($studentId, $data['password']);

        return response()->json(['message' => 'Student password reset successfully']);
    }

    private function accessibleClass(User $user, string $id): ClassModel
    {
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_DEPARTMENT_HEAD, 'admin'], true) || $user->canDo('CLASS_MGMT', 'view')) {
            return $this->classService->getClass($id);
        }
        if (in_array($user->role, [...User::INSTRUCTOR_ROLES, User::ROLE_DEPARTMENT_HEAD], true)) {
            return $this->classService->getClass($id, $user->id);
        }

        abort(403, 'Permission denied');
    }

    private function assertCanManageRoster(User $user, string $id): void
    {
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_DEPARTMENT_HEAD, 'admin'], true) || $user->canDo('CLASS_MGMT', 'edit')) {
            $this->classService->getClass($id);
            return;
        }
        if (in_array($user->role, [...User::INSTRUCTOR_ROLES, User::ROLE_DEPARTMENT_HEAD], true)) {
            $this->classService->getClass($id, $user->id);
            return;
        }

        abort(403, 'This role can view the class roster but cannot edit it.');
    }

    private function ownerFilter(User $user): ?string
    {
        return in_array($user->role, [...User::INSTRUCTOR_ROLES], true) && ! $user->isAdmin() && ! $user->canDo('CLASS_MGMT', 'edit')
            ? $user->id
            : null;
    }
}
