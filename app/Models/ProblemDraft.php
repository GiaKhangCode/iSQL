<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProblemDraft extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'problem_id',
        'draft_query',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function problem()
    {
        return $this->belongsTo(Problem::class);
    }
}
