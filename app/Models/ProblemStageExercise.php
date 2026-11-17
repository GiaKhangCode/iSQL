<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProblemStageExercise extends Model
{
    use HasUuid;

    protected $table = 'problem_stage_exercises';

    protected $fillable = [
        'id',
        'problem_id',
        'part',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function problem()
    {
        return $this->belongsTo(Problem::class, 'problem_id');
    }
}

