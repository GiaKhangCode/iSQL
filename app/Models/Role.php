<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = [
        'function_id',
        'name',
        'add',
        'edit',
        'delete',
        'download',
        'view',
    ];

    protected $casts = [
        'add' => 'boolean',
        'edit' => 'boolean',
        'delete' => 'boolean',
        'download' => 'boolean',
        'view' => 'boolean',
    ];

    public function functionModel(): BelongsTo
    {
        return $this->belongsTo(FunctionModel::class, 'function_id');
    }

    public function roleGroups(): BelongsToMany
    {
        return $this->belongsToMany(RoleGroup::class, 'role_group_assign_role', 'role_id', 'role_group_id')
                    ->withTimestamps();
    }

    public function directUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_assign_role', 'role_id', 'account_id')
                    ->withTimestamps();
    }
}
