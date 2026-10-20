<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuid;

    protected $table = 'users';

    protected $fillable = [
        'id',
        'email',
        'hashed_password',
        'name',
        'initials',
        'role',
        'status',
        'department',
        'current_streak',
        'longest_streak',
        'last_streak_date',
        'last_active',
    ];

    protected $hidden = [
        'hashed_password',
        'remember_token',
    ];

    /**
     * Chỉ định cột dùng làm mật khẩu xác thực (mặc định của Laravel là 'password')
     */
    public function getAuthPasswordName(): string
    {
        return 'hashed_password';
    }

    public function roleGroups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(RoleGroup::class, 'account_assign_role_group', 'account_id', 'role_group_id')
                    ->withTimestamps();
    }

    public function directRoles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'account_assign_role', 'account_id', 'role_id')
                    ->withTimestamps();
    }
}
