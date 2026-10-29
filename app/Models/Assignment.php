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
        'is_contest',
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
        'hints_enabled',
        'comments_enabled',
        'leaderboard_enabled',
        'ai_allowed',
        'exam_mode',
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
        'is_contest' => 'boolean',
        'published' => 'boolean',
        'hints_enabled' => 'boolean',
        'comments_enabled' => 'boolean',
        'leaderboard_enabled' => 'boolean',
        'ai_allowed' => 'boolean',
        'exam_mode' => 'boolean',
        'show_scores_after_submit' => 'boolean',
        'max_submissions' => 'integer',
        'opens' => 'datetime',
        'closes' => 'datetime',
        'ddl_points' => 'integer',
        'dml_points' => 'integer',
        'sql_points' => 'integer',
    ];

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function classes()
    {
        return $this->belongsToMany(ClassModel::class, 'assignment_classes', 'assignment_id', 'class_id')
                    ->using(AssignmentClass::class);
    }

    public function assignmentProblems()
    {
        return $this->hasMany(AssignmentProblem::class, 'assignment_id')->orderBy('order_index');
    }

    public function problems()
    {
        return $this->belongsToMany(Problem::class, 'assignment_problems', 'assignment_id', 'problem_id')
                    ->using(AssignmentProblem::class)
                    ->withPivot('id', 'points', 'order_index', 'workspace_parts', 'dataset_visibility', 'database_structure_visible', 'database_description_visible');
    }
}
