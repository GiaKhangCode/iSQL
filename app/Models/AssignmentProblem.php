<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AssignmentProblem extends Pivot
{
    use HasUuid;

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $table = 'assignment_problems';

    protected $fillable = [
        'id',
        'assignment_id',
        'problem_id',
        'points',
        'order_index',
        'workspace_parts',
        'dataset_visibility',
        'database_structure_visible',
        'database_description_visible',
    ];

    protected $casts = [
        'points' => 'integer',
        'order_index' => 'integer',
        'workspace_parts' => 'array',
        'dataset_visibility' => 'array',
        'database_structure_visible' => 'boolean',
        'database_description_visible' => 'boolean',
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
