<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityAttempt extends Model
{
    protected $table = 'activity_attempts';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'assignment_id',
        'user_id',
        'status',
        'schema_name',
        'drafts',
        'violations',
        'started_at',
        'submitted_at',
        'finish_reason',
    ];

    protected $casts = [
        'drafts'       => 'array',
        'violations'   => 'array',
        'started_at'   => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected function setKeysForSaveQuery($query)
    {
        return $query->where('assignment_id', $this->getAttribute('assignment_id'))
                     ->where('user_id', $this->getAttribute('user_id'));
    }
}
