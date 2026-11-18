<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'title',
        'message',
        'type',
        'is_read',
        'link',
        'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Định dạng JSON trả về cho frontend (camelCase).
     *
     * Mặc định Eloquent trả đúng tên cột trong DB (is_read, created_at), nhưng
     * frontend đọc isRead / createdAt. Ghi đè toArray() để mọi nơi trả về
     * Notification (response()->json($notification), collection, ...) đều dùng
     * chung một định dạng. user_id không cần cho client nên không xuất ra.
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'title'     => $this->title,
            'message'   => $this->message,
            'type'      => $this->type,
            'isRead'    => (bool) $this->is_read,
            'link'      => $this->link,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
