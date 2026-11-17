<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSessionPlan extends Model
{
    protected $table = 'exam_session_plans';
    protected $primaryKey = 'session_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'session_id',
        'confirmed_at',
        'confirmed_by',
        'reopened_at',
        'reopen_reason',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'reopened_at'  => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(Assignment::class, 'session_id');
    }
}

