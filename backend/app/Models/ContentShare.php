<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentShare extends Model
{
    protected $table = 'content_shares';

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'kind',
        'resource_id',
        'recipient_id',
        'can_edit',
        'created_at',
    ];

    protected $casts = [
        'can_edit'   => 'boolean',
        'created_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}

