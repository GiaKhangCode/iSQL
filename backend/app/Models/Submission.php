<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'problem_id',
        'source',
        'context',
        'result',
        'score',
        'submitted_at',
        'query_text',
        'database_type',
        'schema_name',
        'evaluated_score',
        'feedback',
    ];

    protected $casts = [
        'score' => 'float',
        'evaluated_score' => 'float',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function problem()
    {
        return $this->belongsTo(Problem::class);
    }

    public function workspaceResult()
    {
        return $this->hasOne(WorkspaceSubmissionResult::class, 'submission_id');
    }
}
