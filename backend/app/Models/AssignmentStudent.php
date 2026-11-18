<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AssignmentStudent extends Pivot
{
    public $incrementing = false;
    public $timestamps = false;
    protected $table = 'assignment_students';

    protected $fillable = [
        'assignment_id',
        'student_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}

