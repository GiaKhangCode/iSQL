<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamClassProctor extends Model
{
    protected $table = 'exam_class_proctors';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'session_id',
        'class_id',
        'proctor_id',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(Assignment::class, 'session_id');
    }

    public function classModel()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function proctor()
    {
        return $this->belongsTo(User::class, 'proctor_id');
    }
}

