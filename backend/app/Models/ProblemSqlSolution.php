<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProblemSqlSolution extends Model
{
    use HasUuid;

    protected $table = 'problem_sql_solutions';

    protected $fillable = [
        'id',
        'problem_id',
        'name',
        'query',
        'description',
        'expected',
        'enabled',
        'time_limit_ms',
        'order_index',
    ];

    protected $casts = [
        'expected' => 'array',
        'enabled' => 'boolean',
        'time_limit_ms' => 'integer',
        'order_index' => 'integer',
    ];

    public function problem()
    {
        return $this->belongsTo(Problem::class, 'problem_id');
    }
}
