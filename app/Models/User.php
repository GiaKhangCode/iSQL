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

    public function getAuthPasswordName(): string
    {
        return 'hashed_password';
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function enrollments()
    {
        return $this->hasMany(ClassEnrollment::class, 'student_id');
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }
}
