<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceSubmissionResult extends Model
{
    protected $table = 'workspace_submission_results';

    protected $primaryKey = 'submission_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'submission_id',
        'sections',
    ];

    protected $casts = [
        'sections' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(Submission::class, 'submission_id');
    }
}

