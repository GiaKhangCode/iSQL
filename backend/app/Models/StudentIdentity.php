<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentIdentity extends Model
{
    protected $table = 'student_identities';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'student_code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

