<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProblemListItem extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'list_id',
        'problem_id',
    ];

    public function problemList()
    {
        return $this->belongsTo(ProblemList::class, 'list_id');
    }

    public function problem()
    {
        return $this->belongsTo(Problem::class, 'problem_id');
    }
}
