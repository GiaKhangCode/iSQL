<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class TestCase extends Model
{
    use HasUuid;

    protected $table = 'test_cases_rel';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'problem_id',
        'is_hidden',
        'order_index',
        'expected_query',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
        'order_index' => 'integer',
    ];

    public function problem()
    {
        return $this->belongsTo(Problem::class, 'problem_id');
    }

    public function scripts()
    {
        return $this->hasMany(TestCaseScript::class, 'test_case_id');
    }
}
