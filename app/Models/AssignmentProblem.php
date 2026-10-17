<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class AssignmentProblem extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'assignment_id',
        'problem_id',
        'points',
        'order_index',
    ];

    protected $casts = [
        'points' => 'integer',
        'order_index' => 'integer',
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function problem()
    {
        return $this->belongsTo(Problem::class);
    }
}
