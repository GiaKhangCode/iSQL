<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Problem extends Model
{
    use HasUuid;

    public $timestamps = true;

    protected $fillable = [
        'id',
        'number',
        'title',
        'topic',
        'topics',
        'difficulty',
        'description',
        'requirements',
        'hint',
        'practice_listed',
        'database_type',
        'creator_id',
        'workspace_parts',
        'schema_file_name',
        'database_description',
        'database_description_visible',
        'tables',
        'expected',
        'test_cases',
    ];

    protected $casts = [
        'topics' => 'array',
        'tables' => 'array',
        'expected' => 'array',
        'test_cases' => 'array',
        'practice_listed' => 'boolean',
        'workspace_parts' => 'array',
        'database_description' => 'array',
        'database_description_visible' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function topicRelations()
    {
        return $this->belongsToMany(Topic::class, 'problem_topics', 'problem_id', 'topic_id');
    }

    public function testCases()
    {
        return $this->hasMany(TestCase::class, 'problem_id')->orderBy('order_index');
    }

    public function sqlSolutions()
    {
        return $this->hasMany(ProblemSqlSolution::class, 'problem_id')->orderBy('order_index');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class, 'problem_id');
    }
}
