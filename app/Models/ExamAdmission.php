<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ExamAdmission extends Model
{
    use HasUuid;

    protected $table = 'exam_admissions';

    protected $fillable = [
        'id',
        'examination_id',
        'session_id',
        'student_id',
        'username',
        'hashed_password',
        'initial_password',
        'status',
        'variant_code',
        'generation',
        'claimed_at',
        'revoked_at',
        'reason',
    ];

    protected $casts = [
        'generation' => 'integer',
        'claimed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function examination()
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }

    public function session()
    {
        return $this->belongsTo(Assignment::class, 'session_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}

