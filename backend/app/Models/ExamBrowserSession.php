<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ExamBrowserSession extends Model
{
    use HasUuid;

    protected $table = 'exam_browser_sessions';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'admission_id',
        'session_id',
        'student_id',
        'browser_code',
        'status',
        'user_agent',
        'ip_address',
        'started_at',
        'last_seen_at',
        'approved_by',
        'approval_reason',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}

