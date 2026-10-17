<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    /**
     * Get all assignments with associated classes and problems
     */
    public function getAllAssignments(): Collection
    {
        return Assignment::with(['classes', 'problems'])->get();
    }

    /**
     * Get assignments published for student's enrolled classes
     */
    public function getStudentAssignments(User $student): Collection
    {
        $classIds = $student->enrollments()->pluck('class_id');

        return Assignment::whereHas('classes', function ($query) use ($classIds) {
            $query->whereIn('classes.id', $classIds);
        })->where('published', true)->get();
    }

    /**
     * Get detailed assignment by ID
     */
    public function getAssignmentById(string $id): Assignment
    {
        return Assignment::with(['classes', 'problems'])->findOrFail($id);
    }

    /**
     * Create a new assignment or contest with class and problem relations
     */
    public function createAssignment(array $data, string $instructorId): Assignment
    {
        return DB::transaction(function () use ($data, $instructorId) {
            $data['instructor_id'] = $instructorId;
            $assignment = Assignment::create($data);

            if (!empty($data['class_ids'])) {
                $assignment->classes()->sync($data['class_ids']);
            }
            if (!empty($data['problem_ids'])) {
                $assignment->problems()->sync($data['problem_ids']);
            }

            return $assignment->load(['classes', 'problems']);
        });
    }

    /**
     * Update an existing assignment and its relations
     */
    public function updateAssignment(string $id, array $data): Assignment
    {
        return DB::transaction(function () use ($id, $data) {
            $assignment = Assignment::findOrFail($id);
            $assignment->update($data);

            if (isset($data['class_ids'])) {
                $assignment->classes()->sync($data['class_ids']);
            }
            if (isset($data['problem_ids'])) {
                $assignment->problems()->sync($data['problem_ids']);
            }

            return $assignment->load(['classes', 'problems']);
        });
    }

    /**
     * Delete an assignment and related pivot entries
     */
    public function deleteAssignment(string $id): void
    {
        DB::transaction(function () use ($id) {
            $assignment = Assignment::findOrFail($id);
            $assignment->classes()->detach();
            $assignment->problems()->detach();
            $assignment->delete();
        });
    }
}
