<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseLibraryEntry extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'instructor_id',
        'title',
        'database_file_name',
        'create_table_file_name',
        'insert_into_file_name',
        'create_table_sql',
        'insert_into_sql',
        'datasets_json',
    ];

    protected $casts = [
        'datasets_json' => 'array',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function getDatasetsAttribute(): array
    {
        if (is_array($this->datasets_json) && count($this->datasets_json) > 0) {
            return $this->datasets_json;
        }

        return [
            [
                'fileName' => $this->insert_into_file_name ?: 'seed_1.sql',
                'sql'      => $this->insert_into_sql ?: '',
            ],
        ];
    }

    public function toResponseArray(): array
    {
        return [
            'id'                  => (string) $this->id,
            'title'               => (string) $this->title,
            'databaseFileName'    => $this->database_file_name,
            'createTableFileName' => $this->create_table_file_name,
            'insertIntoFileName'  => $this->insert_into_file_name,
            'createTableSql'      => (string) $this->create_table_sql,
            'insertIntoSql'       => (string) $this->insert_into_sql,
            'datasets'            => $this->datasets,
            'createdAt'           => $this->created_at ? $this->created_at->toISOString() : null,
            'updatedAt'           => $this->updated_at ? $this->updated_at->toISOString() : null,
        ];
    }
}
