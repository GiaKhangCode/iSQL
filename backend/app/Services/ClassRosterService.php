<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Danh sách sinh viên của lớp: phân quyền theo lớp, tra cứu sinh viên, thêm/bớt/thay thế,
 * import CSV/TXT và đặt lại mật khẩu sinh viên.
 *
 * Phạm vi truy cập (theo vai trò):
 *  - admin, trưởng bộ môn: mọi lớp;
 *  - giảng viên (INSTRUCTOR_ROLES): ĐỌC lớp mình dạy (lý thuyết hoặc thực hành), GHI chỉ lớp mình
 *    là giảng viên thực hành; kể cả khi có quyền CLASS_MGMT:edit từ nhóm quyền (quyền đó cho phép
 *    THAO TÁC, không mở rộng PHẠM VI lớp);
 *  - sinh viên: không được xem danh sách lớp/sinh viên qua các endpoint này;
 *  - vai trò khác (ví dụ giáo vụ): mọi lớp nếu ma trận quyền cho CLASS_MGMT view/edit.
 *
 * Lỗi được ném dưới dạng HttpException/ValidationException để bootstrap/app.php trả JSON
 * `{message, detail, errors?}`.
 */
class ClassRosterService
{
    public function __construct(
        private readonly ClassService $classService,
        private readonly UserService $userService,
        private readonly RosterImportParser $parser,
    ) {
    }

    // ───────────────────────── phân quyền theo lớp ─────────────────────────

    public function classForReading(User $user, string $classId): ClassModel
    {
        if ($this->seesEveryClass($user)) {
            return $this->classService->getClass($classId);
        }
        if ($this->isInstructor($user)) {
            return $this->classService->getClassTaughtBy($classId, $user->id);
        }
        if ($user->role !== User::ROLE_STUDENT && $user->canDo('CLASS_MGMT', 'view')) {
            return $this->classService->getClass($classId);
        }

        abort(403, 'Permission denied');
    }

    public function classForManaging(User $user, string $classId): ClassModel
    {
        if ($this->seesEveryClass($user)) {
            return $this->classService->getClass($classId);
        }
        if ($this->isInstructor($user)) {
            return $this->classService->getClass($classId, $user->id);
        }
        if ($user->role !== User::ROLE_STUDENT && $user->canDo('CLASS_MGMT', 'edit')) {
            return $this->classService->getClass($classId);
        }

        abort(403, 'This role can view the class roster but cannot edit it.');
    }

    private function seesEveryClass(User $user): bool
    {
        return $user->isAdmin() || $user->role === User::ROLE_DEPARTMENT_HEAD;
    }

    private function isInstructor(User $user): bool
    {
        return in_array($user->role, User::INSTRUCTOR_ROLES, true);
    }

    /** Giá trị truyền cho ClassService để giới hạn theo giảng viên (null = không giới hạn). */
    private function ownerFilter(User $user): ?string
    {
        return $this->isInstructor($user) && ! $this->seesEveryClass($user) ? $user->id : null;
    }

    // ───────────────────────── đọc ─────────────────────────

    /** Danh sách sinh viên Active để chọn thêm vào lớp. */
    public function allActiveStudents(User $user): array
    {
        if ($user->role === User::ROLE_STUDENT) {
            abort(403, 'Permission denied');
        }

        return User::where('role', User::ROLE_STUDENT)
            ->where('status', 'Active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $s) => $this->memberPayload($s, 'student', $s->created_at?->toIso8601String()))
            ->all();
    }

    /** Bản ghi người dùng thô của sinh viên trong lớp (route /students). */
    public function students(User $user, string $classId): Collection
    {
        return $this->classForReading($user, $classId)->students()->orderBy('name')->get();
    }

    /** Thành viên lớp theo dạng frontend dùng (route /members). */
    public function members(User $user, string $classId): array
    {
        return $this->classForReading($user, $classId)
            ->students()->orderBy('name')->get()
            ->map(fn (User $m) => $this->memberPayload($m, $m->pivot->role ?? 'Member', $m->pivot->joined_at))
            ->all();
    }

    private function memberPayload(User $user, ?string $role, mixed $joinedAt): array
    {
        return [
            'id'        => $user->id,
            'name'      => $user->name,
            'email'     => $user->email,
            'role'      => $role,
            'joined_at' => $joinedAt,
            'joinedAt'  => $joinedAt,
        ];
    }

    // ───────────────────────── thêm / bớt từng người ─────────────────────────

    /**
     * @param  array{student_id?:mixed,email?:mixed,student_ids?:mixed}  $input
     */
    public function addMember(User $actor, string $classId, array $input): array
    {
        $class = $this->classForManaging($actor, $classId);

        $identifier = $this->requestedIdentifier($input);
        if ($identifier === null) {
            abort(422, 'student_id is required');
        }

        $candidates = $this->findUsersByIdentifier($identifier);
        if ($candidates->isEmpty()) {
            abort(404, 'Không tìm thấy sinh viên.');
        }
        if ($candidates->count() > 1) {
            abort(422, "Có nhiều tài khoản khớp với \"{$identifier}\". Hãy nhập email đầy đủ.");
        }

        $student = $candidates->first();
        if ($student->role !== User::ROLE_STUDENT) {
            abort(422, 'Tài khoản này không phải là sinh viên.');
        }

        if ($class->students()->where('users.id', $student->id)->exists()) {
            abort(400, 'Sinh viên này đã được thêm vào lớp từ trước.');
        }

        $joinedAt = now();
        $class->students()->attach($student->id, ['role' => 'Member', 'joined_at' => $joinedAt]);

        return $this->memberPayload($student, 'Member', $joinedAt->toIso8601String());
    }

    public function removeMember(User $actor, string $classId, string $studentIdentifier): void
    {
        $class = $this->classForManaging($actor, $classId);

        $student = $this->findUsersByIdentifier($studentIdentifier, allowPrefix: false)->first();
        $targetId = $student ? $student->id : $studentIdentifier;

        if (! $class->students()->where('users.id', $targetId)->exists()) {
            abort(404, 'Sinh viên không có trong lớp học này.');
        }

        $class->students()->detach($targetId);
    }

    /** Ưu tiên `student_id`, rồi `email`, rồi phần tử đầu của `student_ids`; null nếu không có. */
    private function requestedIdentifier(array $input): ?string
    {
        $raw = $input['student_id'] ?? $input['email'] ?? null;
        if ($raw === null && array_key_exists('student_ids', $input)) {
            $raw = ((array) $input['student_ids'])[0] ?? null;
        }
        if (! is_scalar($raw)) {
            return null;
        }

        $identifier = trim((string) $raw);

        return $identifier === '' ? null : $identifier;
    }

    // ───────────────────────── thêm / thay thế / xóa hàng loạt (/students) ─────────────────────────

    public function addStudents(User $actor, string $classId, array $studentIds): Collection
    {
        $this->classForManaging($actor, $classId);
        $class = $this->classService->addStudents($classId, $studentIds, $this->ownerFilter($actor));

        return $class->students()->orderBy('name')->get();
    }

    public function replaceStudents(User $actor, string $classId, array $studentIds): Collection
    {
        $this->classForManaging($actor, $classId);
        $class = $this->classService->replaceStudents($classId, $studentIds, $this->ownerFilter($actor));

        return $class->students()->orderBy('name')->get();
    }

    public function removeStudent(User $actor, string $classId, string $studentId): Collection
    {
        $this->classForManaging($actor, $classId);
        $class = $this->classService->removeStudent($classId, $studentId, $this->ownerFilter($actor));

        return $class->students()->orderBy('name')->get();
    }

    // ───────────────────────── import CSV / TXT ─────────────────────────

    /**
     * @return array{total_processed:int,success_count:int,skipped_count:int,failed_count:int,errors:array<int,array{email:string,error:string}>}
     */
    public function importMembers(User $actor, string $classId, ?UploadedFile $file): array
    {
        $class = $this->classForManaging($actor, $classId);

        if ($file === null || ! $file->isValid()) {
            abort(422, 'Vui lòng tải lên tệp danh sách sinh viên hợp lệ (.csv hoặc .txt).');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'], true)) {
            abort(422, 'Định dạng tệp không được hỗ trợ. Vui lòng sử dụng tệp .csv hoặc .txt.');
        }

        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || trim($raw) === '') {
            return $this->importReport(0, 0, 0, [['email' => 'file', 'error' => 'Tệp rỗng hoặc không có dữ liệu.']]);
        }

        if ($this->parser->looksLikeXlsx($raw)) {
            abort(422, 'Tệp tải lên là tệp Excel (.xlsx), không phải tệp văn bản CSV. Vui lòng mở tệp trong Excel, chọn "Save As" (Lưu tệp dưới dạng) và chọn định dạng "CSV UTF-8 (Comma delimited) (*.csv)".');
        }

        $identifiers = $this->parser->extractIdentifiers($this->parser->normalizeEncoding($raw), $extension);

        $success = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $seen = [];

        foreach ($identifiers as $identifier) {
            $identifier = trim((string) $identifier);
            if ($identifier === '') {
                continue;
            }
            $label = mb_convert_encoding($identifier, 'UTF-8', 'UTF-8');

            $key = mb_strtolower($identifier);
            if (isset($seen[$key])) {
                $skipped++;
                $errors[] = ['email' => $label, 'error' => 'Trùng lặp trong tệp tải lên.'];
                continue;
            }
            $seen[$key] = true;

            $candidates = $this->findUsersByIdentifier($identifier);
            if ($candidates->isEmpty()) {
                $failed++;
                $errors[] = ['email' => $label, 'error' => "Không tìm thấy người dùng với thông tin: {$identifier}."];
                continue;
            }
            if ($candidates->count() > 1) {
                $failed++;
                $errors[] = ['email' => $label, 'error' => "Có nhiều tài khoản khớp với: {$identifier}. Hãy dùng email đầy đủ."];
                continue;
            }

            $user = $candidates->first();
            if ($user->role !== User::ROLE_STUDENT) {
                $failed++;
                $errors[] = ['email' => $label, 'error' => "Tài khoản ({$user->name}) không phải là sinh viên (vai trò: {$user->role})."];
                continue;
            }

            if ($class->students()->where('users.id', $user->id)->exists()) {
                $skipped++;
                $errors[] = ['email' => $label, 'error' => 'Sinh viên đã tham gia lớp học từ trước.'];
                continue;
            }

            $class->students()->attach($user->id, ['role' => 'Member', 'joined_at' => now()]);
            $success++;
        }

        return $this->importReport($success, $skipped, $failed, $errors);
    }

    private function importReport(int $success, int $skipped, int $failed, array $errors): array
    {
        return [
            'total_processed' => $success + $skipped + $failed,
            'success_count'   => $success,
            'skipped_count'   => $skipped,
            'failed_count'    => $failed,
            'errors'          => $errors,
        ];
    }

    // ───────────────────────── tra cứu sinh viên ─────────────────────────

    /**
     * Tìm tài khoản theo định danh, theo thứ tự ưu tiên (bậc đầu tiên có kết quả thắng):
     * id chính xác → email chính xác (không phân biệt hoa thường) → tiền tố email trước `@`
     * (MSSV, chỉ khi định danh không chứa `@`). Có thể trả về nhiều hơn một tài khoản ở bậc
     * tiền tố; người gọi phải coi đó là mơ hồ.
     *
     * Ký tự `%` và `_` trong định danh được escape, không bị coi là ký tự đại diện của LIKE.
     *
     * @return Collection<int, User>
     */
    public function findUsersByIdentifier(string $identifier, bool $allowPrefix = true): Collection
    {
        $identifier = trim($identifier);
        $lower = mb_strtolower($identifier);

        $byId = User::whereKey($identifier)->get();
        if ($byId->isNotEmpty()) {
            return $byId;
        }

        $byEmail = User::whereRaw('LOWER(email) = ?', [$lower])->get();
        if ($byEmail->isNotEmpty() || ! $allowPrefix || str_contains($identifier, '@')) {
            return $byEmail;
        }

        return User::whereRaw('LOWER(email) LIKE ?', [addcslashes($lower, '\\%_') . '@%'])->limit(2)->get();
    }

    // ───────────────────────── đặt lại mật khẩu ─────────────────────────

    public function resetStudentPassword(User $actor, string $classId, string $studentId, string $password): void
    {
        $class = $this->classService->getClass($classId, $actor->isAdmin() ? null : $actor->id);

        if (! $class->students()->where('users.id', $studentId)->exists()) {
            throw ValidationException::withMessages(['student_id' => ['The student is not enrolled in this class.']]);
        }

        $this->userService->resetStudentPassword($studentId, $password);
    }
}
