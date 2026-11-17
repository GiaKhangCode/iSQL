<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class AcademicTerm extends Model
{
    use HasUuid;

    protected $table = 'academic_terms';

    protected $fillable = ['id', 'name', 'start_date', 'end_date', 'status'];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function classes()
    {
        return $this->hasMany(ClassModel::class, 'term_id');
    }
}
