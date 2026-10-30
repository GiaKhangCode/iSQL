<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassEnrollment;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassService
{
    /**
     * Get all classes for Admin view
     */
    public function getAllClasses(): Collection
    {
        return ClassModel::with(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm'])
            ->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get one class, optionally limited to its instructor.
     */
    public function getClass(string $classId, ?string $instructorId = null): ClassModel
    {
        $query = ClassModel::with(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm'])->whereKey($classId);

        if ($instructorId !== null) {
            $query->where(function ($query) use ($instructorId): void {
                $query->where('instructor_id', $instructorId)
                    ->orWhereHas('instructors', fn ($instructors) => $instructors->where('users.id', $instructorId));
            });
        }

        return $query->firstOrFail();
    }

    /**
     * Get classes taught by a specific instructor
     */
    public function getInstructorClasses(string $instructorId): Collection
    {
        return ClassModel::where(function ($query) use ($instructorId): void {
                $query->where('instructor_id', $instructorId)
                    ->orWhereHas('instructors', fn ($instructors) => $instructors->where('users.id', $instructorId));
            })
            ->with(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm'])
            ->distinct()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getStudentClasses(string $studentId): Collection
    {
        return User::findOrFail($studentId)->enrolledClasses()
            ->with(['instructor', 'instructors', 'courseRecord', 'academicTerm'])
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

        if ($instructorId !== null && $instructorId !== 'Unassigned' && $instructorId !== '') {
            $this->assignInstructor($class->id, $data['assignment_type'] ?? 'practical', $instructorId);
        }

        return $class->load(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm']);
    }

    /**
     * Assign an instructor to a class (or unassign if instructorId is Unassigned / null).
     */
    public function assignInstructor(string $classId, string $assignmentType = 'practical', ?string $instructorId = null): ClassModel
    {
        $class = ClassModel::findOrFail($classId);

        if ($instructorId === null || $instructorId === 'Unassigned' || $instructorId === '') {
            return $this->removeInstructorAssignment($classId, $assignmentType);
        }

        $this->assertActiveInstructor($instructorId);

        DB::transaction(function () use ($class, $assignmentType, $instructorId): void {
            DB::table('class_instructors')
                ->where('class_id', $class->id)
                ->where('assignment_type', $assignmentType)
                ->delete();
            DB::table('class_instructors')->insert([
                'class_id' => $class->id,
                'instructor_id' => $instructorId,
                'assignment_type' => $assignmentType,
                'assigned_at' => now(),
            ]);
            $this->syncLegacyInstructorId($class->id);
        });

        return $class->refresh()->load(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm']);
    }

    public function syncInstructorAssignments(string $classId, string $theoryInstructorId, string $practicalInstructorId): ClassModel
    {
        $class = ClassModel::findOrFail($classId);
        $this->assertActiveInstructor($theoryInstructorId);
        $this->assertActiveInstructor($practicalInstructorId);

        DB::transaction(function () use ($class, $theoryInstructorId, $practicalInstructorId): void {
            DB::table('class_instructors')->where('class_id', $class->id)->delete();
            DB::table('class_instructors')->insert([
                [
                    'class_id' => $class->id,
                    'instructor_id' => $theoryInstructorId,
                    'assignment_type' => 'theory',
                    'assigned_at' => now(),
                ],
                [
                    'class_id' => $class->id,
                    'instructor_id' => $practicalInstructorId,
                    'assignment_type' => 'practical',
                    'assigned_at' => now(),
                ],
            ]);
            $this->syncLegacyInstructorId($class->id);
        });

        return $class->refresh()->load(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm']);
    }

    public function removeInstructorAssignment(string $classId, string $assignmentType): ClassModel
    {
        $class = ClassModel::findOrFail($classId);

        DB::transaction(function () use ($class, $assignmentType): void {
            DB::table('class_instructors')
                ->where('class_id', $class->id)
                ->where('assignment_type', $assignmentType)
                ->delete();
            $this->syncLegacyInstructorId($class->id);
        });

        return $class->refresh()->load(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm']);
    }

    private function assertActiveInstructor(string $instructorId): void
    {
        $valid = User::whereKey($instructorId)
            ->whereIn('role', User::INSTRUCTOR_ROLES)
            ->where('status', 'Active')
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'instructor_id' => ['The instructor must be an active practical or theory instructor.'],
            ]);
        }
    }

    private function syncLegacyInstructorId(string $classId): void
    {
        $practicalId = DB::table('class_instructors')
            ->where('class_id', $classId)
            ->where('assignment_type', 'practical')
            ->value('instructor_id');
        $theoryId = DB::table('class_instructors')
            ->where('class_id', $classId)
            ->where('assignment_type', 'theory')
            ->value('instructor_id');

        DB::table('classes')->where('id', $classId)->update([
            'instructor_id' => $practicalId ?? $theoryId,
        ]);
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

        $hasLecturerInput = array_key_exists('lecturer_id', $data)
            || array_key_exists('lecturerId', $data)
            || array_key_exists('instructor_id', $data);

        if ($hasLecturerInput) {
            $lecturerId = $data['lecturer_id'] ?? $data['lecturerId'] ?? $data['instructor_id'] ?? null;
            $assignmentType = $data['assignment_type'] ?? 'practical';

            if ($lecturerId === 'Unassigned' || $lecturerId === null || $lecturerId === '') {
                DB::table('class_instructors')->where('class_id', $class->id)->delete();
                $data['instructor_id'] = null;
            } else {
                $this->assignInstructor($class->id, $assignmentType, $lecturerId);
                $data['instructor_id'] = $lecturerId;
            }
        }

        $class->fill($data);
        $class->save();

        return $class->refresh()->load(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm']);
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
            'id'          => $class->id,
            'course'      => $class->course,
            'lecturer'    => $lecturerName,
            'lecturer_id' => $lecturerId,
            'lecturerId'  => $lecturerId,
            'students'    => $studentsCount,
            'status'      => $class->status,
            'semester'    => $class->term,
            'term'        => $class->term,
            'dates'       => $dates,
            'start_date'  => $startDate,
            'startDate'   => $startDate,
            'end_date'    => $endDate,
            'endDate'     => $endDate,
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
            $query->where(function ($query) use ($instructorId): void {
                $query->where('instructor_id', $instructorId)
                    ->orWhereHas('instructors', fn ($instructors) => $instructors->where('users.id', $instructorId));
            });
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

        return $class->refresh()->load(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm']);
    }

    public function replaceStudents(string $classId, array $studentIds, ?string $instructorId = null): ClassModel
    {
        $class = $this->findClassForManagement($classId, $instructorId);
        $this->assertStudentAccounts($studentIds);

        DB::transaction(function () use ($class, $studentIds): void {
            $class->students()->sync($studentIds);
        });

        return $class->refresh()->load(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm']);
    }

    public function removeStudent(string $classId, string $studentId, ?string $instructorId = null): ClassModel
    {
        $class = $this->findClassForManagement($classId, $instructorId);
        if ($class->students()->detach($studentId) === 0) {
            abort(404, 'Student is not enrolled in this class.');
        }

        return $class->refresh()->load(['instructor', 'instructors', 'students', 'courseRecord', 'academicTerm']);
    }

    private function assertStudentAccounts(array $studentIds): void
    {
        if ($studentIds === []) {
            return;
        }

        $uniqueIds = array_values(array_unique($studentIds));
        $studentCount = User::whereIn('id', $uniqueIds)->where('role', User::ROLE_STUDENT)->count();
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
