<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FunctionModel extends Model
{
    use HasFactory;

    protected $table = 'functions';

    protected $fillable = [
        'name',
        'code',
        'description',
    ];

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'function_id');
    }
}
