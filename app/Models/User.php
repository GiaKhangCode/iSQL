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

    public const ROLE_STUDENT = 'student';
    public const ROLE_INSTRUCTOR_PRACTICAL = 'instructor_practical';
    public const ROLE_INSTRUCTOR_THEORY = 'instructor_theory';
    public const ROLE_DEPARTMENT_HEAD = 'department_head';
    public const ROLE_ADMIN = 'admin';

    /** New role values accepted for account creation and role changes. */
    public const ROLES = [
        self::ROLE_STUDENT,
        self::ROLE_INSTRUCTOR_PRACTICAL,
        self::ROLE_INSTRUCTOR_THEORY,
        self::ROLE_DEPARTMENT_HEAD,
        self::ROLE_ADMIN,
    ];

    /** `instructor` is retained for accounts created before the role split. */
    public const LEGACY_INSTRUCTOR_ROLE = 'instructor';

    public const INSTRUCTOR_ROLES = [
        self::ROLE_INSTRUCTOR_PRACTICAL,
        self::ROLE_INSTRUCTOR_THEORY,
        self::LEGACY_INSTRUCTOR_ROLE,
    ];

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

    /**
     * Kiểm tra quyền hạn theo ma trận Function & Action (view, add, edit, delete, download)
     */
    public function canDo(string $functionCode, string $action = 'view'): bool
    {
        if ($this->role === self::ROLE_ADMIN || $this->role === 'admin') {
            return true;
        }

        $permissions = app(\App\Services\PermissionService::class)->getUserPermission($this->id);
        return !empty($permissions[$functionCode][$action]);
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

    public function enrolledClasses()
    {
        return $this->belongsToMany(ClassModel::class, 'class_enrollments', 'student_id', 'class_id')
            ->withPivot('role', 'joined_at');
    }

    public function taughtClasses()
    {
        return $this->hasMany(ClassModel::class, 'instructor_id');
    }

    public function assignedClasses()
    {
        return $this->belongsToMany(ClassModel::class, 'class_instructors', 'instructor_id', 'class_id')
            ->withPivot('assigned_at');
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }
}
