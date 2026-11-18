<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    use HasUuid;

    protected $fillable = [
        'id',
        'title',
        'scoring_mode',
        'instructions',
        'audience_type',
        'short_description',
        'description',
        'rules',
        'banner_url',
        'banner_source_url',
        'banner_crop',
        'opens',
        'closes',
        'published',
        'instructor_id',
        'examination_id',
        'session_index',
        'proctors',
        'room',
        'capacity',
        'admission_required',
        'hints_enabled',
        'comments_enabled',
        'leaderboard_enabled',
        'ai_allowed',
        'exam_mode',
        'exam_violation_limit',
        'show_scores_after_submit',
        'max_submissions',
        'exam_type',
        'ddl_reference_script',
        'dml_seed_script',
        'ddl_points',
        'dml_points',
        'sql_points',
    ];

    protected $casts = [
        'published'                => 'boolean',
        'hints_enabled'            => 'boolean',
        'comments_enabled'         => 'boolean',
        'leaderboard_enabled'      => 'boolean',
        'ai_allowed'               => 'boolean',
        'exam_mode'                => 'boolean',
        'exam_violation_limit'     => 'integer',
        'show_scores_after_submit' => 'boolean',
        'max_submissions'          => 'integer',
        'session_index'            => 'integer',
        'proctors'                 => 'array',
        'capacity'                 => 'integer',
        'admission_required'       => 'boolean',
        'opens'                    => 'datetime',
        'closes'                   => 'datetime',
        'ddl_points'               => 'integer',
        'dml_points'               => 'integer',
        'sql_points'               => 'integer',
    ];

    /** Ca thi của một kỳ thi: nhận biết bằng examination_id (không còn cột is_contest). */
    public function getIsExamSessionAttribute(): bool
    {
        return $this->examination_id !== null;
    }

    /** Chỉ lấy các ca thi (assignments gắn với một kỳ thi). */
    public function scopeExamSessions($query)
    {
        return $query->whereNotNull('examination_id');
    }

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function examination()
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }

    public function classes()
    {
        return $this->belongsToMany(ClassModel::class, 'assignment_classes', 'assignment_id', 'class_id')
                    ->using(AssignmentClass::class);
    }

    public function selectedStudents()
    {
        return $this->belongsToMany(User::class, 'assignment_students', 'assignment_id', 'student_id')
                    ->using(AssignmentStudent::class);
    }

    public function assignmentProblems()
    {
        return $this->hasMany(AssignmentProblem::class, 'assignment_id')->orderBy('order_index');
    }

    public function problems()
    {
        return $this->belongsToMany(Problem::class, 'assignment_problems', 'assignment_id', 'problem_id')
                    ->using(AssignmentProblem::class)
                    ->withPivot('id', 'points', 'order_index', 'workspace_parts', 'dataset_visibility', 'question_selection', 'database_structure_visible', 'database_description_visible');
    }

    public function classProctors()
    {
        return $this->hasMany(ExamClassProctor::class, 'session_id');
    }
}
