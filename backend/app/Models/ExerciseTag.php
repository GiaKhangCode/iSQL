<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ExerciseTag extends Model
{
    use HasFactory;

    protected $table = 'exercise_tags';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'kind',
        'name',
        'part',
    ];

    protected $casts = [
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Format response matching React frontend (CamelCase + snake_case)
     */
    public function toArray(): array
    {
        return [
            'id'          => (string) $this->id,
            'kind'        => (string) $this->kind,
            'name'        => (string) $this->name,
            'part'        => (string) ($this->part ?: 'ALL'),
            'createdAt'   => $this->created_at ? $this->created_at->toISOString() : null,
            'created_at'  => $this->created_at ? $this->created_at->toISOString() : null,
        ];
    }
}

