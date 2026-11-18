<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubmissionAppeal extends Model
{
    protected $table = 'submission_appeals';
    protected $primaryKey = 'submission_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'submission_id',
        'student_id',
        'reason',
        'status',
        'previous_evaluated_score',
        'created_at',
        'resolved_at',
    ];

    protected $casts = [
        'previous_evaluated_score' => 'float',
        'created_at'               => 'datetime',
        'resolved_at'              => 'datetime',
    ];
}

