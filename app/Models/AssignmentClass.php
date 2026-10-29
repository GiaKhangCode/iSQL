<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AssignmentClass extends Pivot
{
    use HasUuid;

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $table = 'assignment_classes';

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
