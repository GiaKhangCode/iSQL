<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAccessState extends Model
{
    protected $table = 'exam_access_states';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'session_id',
        'student_id',
        'locked',
        'lock_reason',
        'incident_at',
        'unlocked_at',
        'unlocked_by',
    ];

    protected $casts = [
        'locked'      => 'boolean',
        'incident_at' => 'datetime',
        'unlocked_at' => 'datetime',
    ];

    protected function setKeysForSaveQuery($query)
    {
        return $query->where('session_id', $this->getAttribute('session_id'))
                     ->where('student_id', $this->getAttribute('student_id'));
    }
}
