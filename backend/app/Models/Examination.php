<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Examination extends Model
{
    use HasUuid;

    protected $table = 'examinations';

    protected $fillable = [
        'id',
        'owner_id',
        'title',
        'exam_date',
        'timezone',
        'template',
        'revision',
    ];

    protected $casts = [
        'template' => 'array',
        'revision' => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function sessions()
    {
        return $this->hasMany(Assignment::class, 'examination_id')->orderBy('session_index');
    }

    public function admissions()
    {
        return $this->hasMany(ExamAdmission::class, 'examination_id');
    }
}

