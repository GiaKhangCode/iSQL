<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class AssignmentClass extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'assignment_id',
        'class_id',
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function class()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }
}
