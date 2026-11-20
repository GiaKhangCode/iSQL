<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassEnrollment;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassService
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    /**
     * Người xem được MỌI lớp: siêu quản trị, người quản lý lớp (class.manage), người xem kết quả mọi lớp
     * (results.view_all) và người lập kỳ thi chọn lớp (examination.manage). Người còn lại chỉ thấy lớp mình dạy.
     * Không đọc users.role.
     */
    public function seesEveryClass(User $user): bool
    {
        return $this->access->isSuperAdmin($user)
            || $this->access->canAny($user, ['class.manage', 'results.view_all', 'examination.manage']);
    }

    /** Danh sách lớp người dùng được xem. */
    public function visibleClasses(User $user): Collection
    {
        return $this->seesEveryClass($user) ? $this->getAllClasses() : $this->getInstructorClasses($user->id);
    }

    /** Một lớp người dùng được xem (404 nếu không thuộc phạm vi). */
    public function visibleClass(User $user, string $classId): ClassModel
    {
        return $this->seesEveryClass($user)
            ? $this->getClass($classId)
            : $this->getClass($classId, $user->id);
    }

    /**
     * Người được phân công dạy lớp: tài khoản Active có quyền class.teach (qua vai trò thường hoặc gán riêng).
     * Không đọc users.role. Siêu quản trị không tự có class.teach (PermissionCatalog::NEVER_FOR_SUPER).
     */
    private function eligibleInstructorQuery(): Builder
    {
        return User::query()
            ->where('status', 'Active')
            ->whereIn('id', $this->access->holdersOf('class.teach'));
    }

    /** Danh sách để chọn giảng viên khi phân công (GET /api/admin/instructors), sắp theo tên. */
    public function eligibleInstructors(): array
    {
        return $this->eligibleInstructorQuery()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department'])
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'department' => $u->department,
            ])
            ->all();
    }

    public function isEligibleInstructor(?string $userId): bool
    {
        return $userId !== null && $userId !== ''
            && $this->eligibleInstructorQuery()->whereKey($userId)->exists();
    }

    /** Người này đang là giảng viên của lớp này? (Một lớp một giảng viên: cột classes.instructor_id.) */
    public function isCurrentInstructor(string $classId, ?string $userId): bool
    {
        return $userId !== null && $userId !== ''
            && ClassModel::whereKey($classId)->where('instructor_id', $userId)->exists();
    }

    /**
     * Được đặt người này làm giảng viên của lớp? Phân công mới cần class.teach; gửi lại đúng người đang dạy thì
     * không (người mất quyền dạy vẫn giữ lớp đang dạy, và form sửa lớp luôn gửi lại giảng viên).
     */
    public function canHoldClass(string $classId, ?string $userId): bool
    {
        return $this->isCurrentInstructor($classId, $userId)
            || $this->isEligibleInstructor($userId);
    }

    /** Quan hệ nạp kèm khi trả lớp ra API. */
    private const CLASS_RELATIONS = ['instructor', 'students', 'courseRecord', 'academicTerm'];

    /**
     * Get all classes for Admin view
     */
    public function getAllClasses(): Collection
    {
        return ClassModel::with(self::CLASS_RELATIONS)
            ->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get one class, optionally limited to its instructor.
     */
    public function getClass(string $classId, ?string $instructorId = null): ClassModel
    {
        $query = ClassModel::with(self::CLASS_RELATIONS)->whereKey($classId);

        if ($instructorId !== null) {
            $query->where('instructor_id', $instructorId);
        }

        return $query->firstOrFail();
    }

    /**
     * Get classes taught by a specific instructor
     */
    public function getInstructorClasses(string $instructorId): Collection
    {
        return ClassModel::with(self::CLASS_RELATIONS)
            ->where('instructor_id', $instructorId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getStudentClasses(string $studentId): Collection
    {
        return User::findOrFail($studentId)->enrolledClasses()
            ->with(['instructor', 'courseRecord', 'academicTerm'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Create a class for an administrator, department head, or owning instructor.
     */
    public function createManagedClass(array $data, ?string $instructorId = null): ClassModel
    {
        if (isset($data['semester']) && !isset($data['term'])) {
            $data['term'] = $data['semester'];
        }
        if (isset($data['startDate']) && !isset($data['start_date'])) {
            $data['start_date'] = $data['startDate'];
        }
        if (isset($data['endDate']) && !isset($data['end_date'])) {
            $data['end_date'] = $data['endDate'];
        }

        $lecturerInput = $data['lecturer_id'] ?? $data['lecturerId'] ?? $data['instructor_id'] ?? null;
        if ($lecturerInput !== null && $lecturerInput !== 'Unassigned' && $lecturerInput !== '') {
            $instructorId = $lecturerInput;
        }

        $data = $this->resolveCatalogLabels($data);
        $this->assertDateOrder($data['start_date'] ?? null, $data['end_date'] ?? null);

        $hasInstructor = $instructorId !== null && $instructorId !== 'Unassigned' && $instructorId !== '';
        if ($hasInstructor) {
            // Kiểm tra TRƯỚC khi tạo lớp để id lạ/không hợp lệ trả 422 thay vì lỗi khóa ngoại.
            $this->assertActiveInstructor($instructorId);
        } else {
            $instructorId = null;
        }

        $attributes = [
            'course'        => $data['course'],
            'course_id'     => $data['course_id'] ?? null,
            'term'          => $data['term'],
            'term_id'       => $data['term_id'] ?? null,
            'instructor_id' => $instructorId,
            'start_date'    => $data['start_date'] ?? null,
            'end_date'      => $data['end_date'] ?? null,
            'status'        => $data['status'] ?? 'Active',
        ];

        if (!empty($data['id'])) {
            $attributes['id'] = $data['id'];
        }

        $class = ClassModel::create($attributes);

        return $class->load(self::CLASS_RELATIONS);
    }

    /**
     * Đặt giảng viên của lớp (một lớp một giảng viên), hoặc bỏ phân công khi instructorId là null / '' / 'Unassigned'.
     */
    public function assignInstructor(string $classId, ?string $instructorId = null): ClassModel
    {
        $class = ClassModel::findOrFail($classId);

        if ($instructorId === null || $instructorId === 'Unassigned' || $instructorId === '') {
            $class->update(['instructor_id' => null]);
        } else {
            if (! $this->isCurrentInstructor($class->id, $instructorId)) {
                $this->assertActiveInstructor($instructorId);
            }
            $class->update(['instructor_id' => $instructorId]);
        }

        return $class->refresh()->load(self::CLASS_RELATIONS);
    }

    /**
     * Ngày kết thúc không được trước ngày bắt đầu (bằng nhau thì được). Thiếu một đầu thì bỏ qua.
     */
    private function assertDateOrder(mixed $start, mixed $end): void
    {
        if ($start === null || $start === '' || $end === null || $end === '') {
            return;
        }

        if (\Carbon\Carbon::parse($end)->lt(\Carbon\Carbon::parse($start))) {
            throw ValidationException::withMessages([
                'end_date' => ['The end date must be a date after or equal to the start date.'],
            ]);
        }
    }

    private function assertActiveInstructor(string $instructorId): void
    {
        if (! $this->isEligibleInstructor($instructorId)) {
            throw ValidationException::withMessages([
                'instructor_id' => ['The instructor must be an active account allowed to teach classes (class.teach).'],
            ]);
        }
    }

    /**
     * Update a class, optionally requiring it to belong to an instructor.
     */
    public function updateClass(string $classId, array $data, ?string $instructorId = null): ClassModel
    {
        $class = $this->findClassForManagement($classId, $instructorId);

        if (isset($data['semester']) && !isset($data['term'])) {
            $data['term'] = $data['semester'];
        }
        if (isset($data['startDate']) && !isset($data['start_date'])) {
            $data['start_date'] = $data['startDate'];
        }
        if (isset($data['endDate']) && !isset($data['end_date'])) {
            $data['end_date'] = $data['endDate'];
        }

        $data = $this->resolveCatalogLabels($data, creating: false);

        // Chỉ gửi một đầu thì so với ngày đang lưu của lớp.
        $this->assertDateOrder(
            array_key_exists('start_date', $data) ? $data['start_date'] : $class->start_date,
            array_key_exists('end_date', $data) ? $data['end_date'] : $class->end_date
        );

        $hasLecturerInput = array_key_exists('lecturer_id', $data)
            || array_key_exists('lecturerId', $data)
            || array_key_exists('instructor_id', $data);

        if ($hasLecturerInput) {
            $lecturerId = $data['lecturer_id'] ?? $data['lecturerId'] ?? $data['instructor_id'] ?? null;

            if ($lecturerId === 'Unassigned' || $lecturerId === null || $lecturerId === '') {
                $data['instructor_id'] = null;
            } else {
                if (! $this->isCurrentInstructor($class->id, $lecturerId)) {
                    $this->assertActiveInstructor($lecturerId);
                }
                $data['instructor_id'] = $lecturerId;
            }
        }

        $class->fill($data);
        $class->save();

        return $class->refresh()->load(self::CLASS_RELATIONS);
    }

    /**
     * Format a class model for JSON responses compatible with Frontend and AdminClassResponse.
     */
    public function formatClassResponse(ClassModel $class): array
    {
        $lecturerName = 'Unassigned';
        $lecturerId = $class->instructor_id;

        if ($class->relationLoaded('instructor') && $class->instructor) {
            $lecturerName = $class->instructor->name;
        } elseif ($class->instructor_id) {
            $inst = User::find($class->instructor_id);
            if ($inst) {
                $lecturerName = $inst->name;
            }
        }

        $studentsCount = $class->relationLoaded('students')
            ? $class->students->count()
            : $class->students()->count();

        $startDate = $class->start_date ? \Carbon\Carbon::parse($class->start_date)->format('Y-m-d') : null;
        $endDate = $class->end_date ? \Carbon\Carbon::parse($class->end_date)->format('Y-m-d') : null;
        $dates = ($startDate && $endDate) ? "{$startDate} – {$endDate}" : 'Sep 07 – Nov 28, 2026';

        $base = $class->toArray();

        return array_merge($base, [
            'id'                     => $class->id,
            'course'                 => $class->course,
            'course_code'            => $class->course_code ?? $class->courseRecord?->code ?? '',
            'theory_class'           => $class->theory_class ?? $class->id,
            'practice_class'         => $class->practice_class ?? $class->id,
            'lecturer'               => $lecturerName,
            'lecturer_id'            => $lecturerId,
            'lecturerId'             => $lecturerId,
            'students'               => $studentsCount,
            'status'                 => $class->status,
            'semester'               => $class->term,
            'term'                   => $class->term,
            'dates'                  => $dates,
            'start_date'             => $startDate,
            'startDate'              => $startDate,
            'end_date'               => $endDate,
            'endDate'                => $endDate,
        ]);
    }

    /**
     * Delete a class, optionally requiring it to belong to an instructor.
     */
    public function deleteClass(string $classId, ?string $instructorId = null): void
    {
        $this->findClassForManagement($classId, $instructorId)->delete();
    }

    private function findClassForManagement(string $classId, ?string $instructorId): ClassModel
    {
        $query = ClassModel::whereKey($classId);

        if ($instructorId !== null) {
            $query->where('instructor_id', $instructorId);
        }

        return $query->firstOrFail();
    }

    public function addStudents(string $classId, array $studentIds, ?string $instructorId = null): ClassModel
    {
        $class = $this->findClassForManagement($classId, $instructorId);
        $this->assertStudentAccounts($studentIds);

        DB::transaction(function () use ($class, $studentIds): void {
            $class->students()->syncWithoutDetaching($studentIds);
        });

        return $class->refresh()->load(self::CLASS_RELATIONS);
    }

    public function replaceStudents(string $classId, array $studentIds, ?string $instructorId = null): ClassModel
    {
        $class = $this->findClassForManagement($classId, $instructorId);
        $this->assertStudentAccounts($studentIds);

        DB::transaction(function () use ($class, $studentIds): void {
            $class->students()->sync($studentIds);
        });

        return $class->refresh()->load(self::CLASS_RELATIONS);
    }

    public function removeStudent(string $classId, string $studentId, ?string $instructorId = null): ClassModel
    {
        $class = $this->findClassForManagement($classId, $instructorId);
        if ($class->students()->detach($studentId) === 0) {
            abort(404, 'Student is not enrolled in this class.');
        }

        return $class->refresh()->load(self::CLASS_RELATIONS);
    }

    private function assertStudentAccounts(array $studentIds): void
    {
        if ($studentIds === []) {
            return;
        }

        $uniqueIds = array_values(array_unique($studentIds));
        $studentCount = User::whereIn('id', $uniqueIds)->where('account_type', User::ACCOUNT_STUDENT)->count();
        if ($studentCount !== count($uniqueIds) || count($uniqueIds) !== count($studentIds)) {
            throw ValidationException::withMessages([
                'student_ids' => ['Each student ID must identify a distinct student account.'],
            ]);
        }
    }

    private function resolveCatalogLabels(array $data, bool $creating = true): array
    {
        if (array_key_exists('course_id', $data) && $data['course_id'] !== null) {
            $course = Course::findOrFail($data['course_id']);
            if (isset($data['course']) && $data['course'] !== $course->name) {
                throw ValidationException::withMessages(['course_id' => ['The course name does not match course_id.']]);
            }
            $data['course'] = $course->name;
        } elseif (array_key_exists('course', $data)) {
            $data['course_id'] = Course::where('name', $data['course'])->value('id');
        } elseif ($creating) {
            throw ValidationException::withMessages(['course' => ['A course name or course_id is required.']]);
        }

        if (array_key_exists('term_id', $data) && $data['term_id'] !== null) {
            $term = AcademicTerm::findOrFail($data['term_id']);
            if (isset($data['term']) && $data['term'] !== $term->name) {
                throw ValidationException::withMessages(['term_id' => ['The term name does not match term_id.']]);
            }
            $data['term'] = $term->name;
        } elseif (array_key_exists('term', $data)) {
            $data['term_id'] = AcademicTerm::where('name', $data['term'])->value('id');
        } elseif ($creating) {
            throw ValidationException::withMessages(['term' => ['A term name or term_id is required.']]);
        }

        return $data;
    }

    /**
     * Enroll a student into a class
     */
    public function enrollStudent(string $classId, string $studentId, string $role = 'Member'): ClassEnrollment
    {
        return ClassEnrollment::firstOrCreate(
            ['class_id' => $classId, 'student_id' => $studentId],
            ['role' => $role]
        );
    }
}
