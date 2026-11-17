<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasUuid;

    protected $fillable = ['id', 'code', 'name', 'description', 'status'];

    public function classes()
    {
        return $this->hasMany(ClassModel::class, 'course_id');
    }
}
