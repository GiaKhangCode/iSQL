<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ClassModel extends Model
{
    use HasUuid;

    protected $table = 'classes';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'course',
        'course_id',
        'term',
        'term_id',
        'instructor_id',
        'start_date',
        'end_date',
        'status',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function instructors()
    {
        return $this->belongsToMany(User::class, 'class_instructors', 'class_id', 'instructor_id')
            ->withPivot('assignment_type', 'assigned_at');
    }

    public function courseRecord()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function academicTerm()
    {
        return $this->belongsTo(AcademicTerm::class, 'term_id');
    }

    public function enrollments()
    {
        return $this->hasMany(ClassEnrollment::class, 'class_id');
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'class_enrollments', 'class_id', 'student_id')
                    ->withPivot('role', 'joined_at');
    }

    public function assignments()
    {
        return $this->belongsToMany(Assignment::class, 'assignment_classes', 'class_id', 'assignment_id');
    }
}
