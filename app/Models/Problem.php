<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Problem extends Model
{
    use HasUuid;

    public $timestamps = false;

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
        return $this->hasMany(TestCase::class, 'problem_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class, 'problem_id');
    }
}
