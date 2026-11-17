<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubmissionRegradeAssignment extends Model
{
    protected $table = 'submission_regrade_assignments';
    protected $primaryKey = 'submission_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'submission_id',
        'assigned_instructor_id',
        'assigned_by_id',
        'note',
        'previous_evaluated_score',
        'previous_feedback',
        'assigned_at',
        'completed_at',
    ];

    protected $casts = [
        'previous_evaluated_score' => 'float',
        'assigned_at'              => 'datetime',
        'completed_at'             => 'datetime',
    ];
}

