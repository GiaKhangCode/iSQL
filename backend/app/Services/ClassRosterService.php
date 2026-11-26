<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Danh sách sinh viên của lớp: phân quyền theo lớp, tra cứu sinh viên, thêm/bớt/thay thế,
 * import CSV/TXT và đặt lại mật khẩu sinh viên.
 *
 * Quyền và phạm vi (không dùng loại tài khoản để cấp quyền; account_type chỉ nhận diện sinh viên):
 *  - ĐỌC: người xem mọi lớp (ClassService::seesEveryClass) đọc mọi lớp; người khác chỉ đọc lớp mình dạy
 *    (lý thuyết hoặc thực hành); không dạy lớp nào thì nhận 404 (kể cả sinh viên);
 *  - GHI danh sách (thêm/bớt/thay thế/import) và danh bạ sinh viên: chỉ quyền class.manage;
 *  - ĐẶT LẠI MẬT KHẨU: quyền student.reset_password, trong lớp mình dạy thực hành; siêu quản trị: mọi lớp.
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
        private readonly AccessControlService $access,
    ) {
    }

    // ───────────────────────── phân quyền theo lớp ─────────────────────────

    public function classForReading(User $user, string $classId): ClassModel
    {
        if ($this->classService->seesEveryClass($user)) {
            return $this->classService->getClass($classId);
        }

        return $this->classService->getClass($classId, $user->id);
    }

    public function classForManaging(User $user, string $classId): ClassModel
    {
        $this->assertCanManageClasses($user);

        return $this->classService->getClass($classId);
    }

    private function assertCanManageClasses(User $user): void
    {
        abort_unless($this->access->can($user, 'class.manage'), 403, 'Chỉ người quản lý lớp mới được thay đổi danh sách lớp.');
    }

    // ───────────────────────── đọc ─────────────────────────

    /** Danh sách sinh viên Active để chọn thêm vào lớp. */
    public function allActiveStudents(User $user): array
    {
        $this->assertCanManageClasses($user);

        return User::where('account_type', User::ACCOUNT_STUDENT)
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
        if ($student->account_type !== User::ACCOUNT_STUDENT) {
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
        $class = $this->classService->addStudents($classId, $studentIds);

        return $class->students()->orderBy('name')->get();
    }

    public function replaceStudents(User $actor, string $classId, array $studentIds): Collection
    {
        $this->classForManaging($actor, $classId);
        $class = $this->classService->replaceStudents($classId, $studentIds);

        return $class->students()->orderBy('name')->get();
    }

    public function removeStudent(User $actor, string $classId, string $studentId): Collection
    {
        $this->classForManaging($actor, $classId);
        $class = $this->classService->removeStudent($classId, $studentId);

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
            if ($user->account_type !== User::ACCOUNT_STUDENT) {
                $failed++;
                $errors[] = ['email' => $label, 'error' => "Tài khoản ({$user->name}) không phải là sinh viên."];
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
        abort_unless($this->access->can($actor, 'student.reset_password'), 403, 'Bạn không có quyền đặt lại mật khẩu sinh viên.');

        $class = $this->classService->getClass($classId, $this->classService->seesEveryClass($actor) ? null : $actor->id);

        if (! $class->students()->where('users.id', $studentId)->exists()) {
            throw ValidationException::withMessages(['student_id' => ['The student is not enrolled in this class.']]);
        }

        $this->userService->resetStudentPassword($studentId, $password);
    }
}
