<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'name',
    ];

    public function problems()
    {
        return $this->belongsToMany(Problem::class, 'problem_topics', 'topic_id', 'problem_id');
    }
}
