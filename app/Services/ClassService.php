<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassEnrollment;
use Illuminate\Database\Eloquent\Collection;

class ClassService
{
    /**
     * Get all classes for Admin view
     */
    public function getAllClasses(): Collection
    {
        return ClassModel::with(['instructor', 'students'])->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get classes taught by a specific instructor
     */
    public function getInstructorClasses(string $instructorId): Collection
    {
        return ClassModel::where('instructor_id', $instructorId)
            ->with(['students'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Create a new class
     */
    public function createClass(array $data, string $instructorId): ClassModel
    {
        return ClassModel::create([
            'course'        => $data['course'],
            'term'          => $data['term'],
            'instructor_id' => $instructorId,
            'start_date'    => $data['start_date'] ?? null,
            'end_date'      => $data['end_date'] ?? null,
            'status'        => 'Active',
        ]);
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
