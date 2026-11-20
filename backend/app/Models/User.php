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

    /**
     * Loại danh tính của tài khoản (KHÔNG phải phân quyền): `student` là người học (được thêm vào lớp, có
     * tài khoản thi, đặt lại mật khẩu...), `staff` là nhân sự. Quyền nằm ở vai trò (user_access_roles).
     */
    public const ACCOUNT_STUDENT = 'student';
    public const ACCOUNT_STAFF = 'staff';

    public const ACCOUNT_TYPES = [self::ACCOUNT_STUDENT, self::ACCOUNT_STAFF];

    /** Vai trò khởi tạo (id hoặc slug) cho tài khoản đang được tạo; không phải cột, xem setInitialRoleAttribute. */
    private ?string $pendingInitialRole = null;

    protected $table = 'users';

    protected $fillable = [
        'id',
        'email',
        'hashed_password',
        'name',
        'initials',
        'account_type',
        'initial_role',
        'status',
        'department',
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

    protected static function booted(): void
    {
        // Tài khoản mới nhận vai trò khởi tạo (initial_role); không có thì sinh viên nhận vai trò "Sinh viên".
        static::created(fn (User $user) => app(\App\Services\Access\AccessRoleAssigner::class)->assignInitial($user, $user->pendingInitialRole()));
    }

    /** Vai trò phân quyền kiểu capability (AccessRole). */
    public function accessRoles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(AccessRole::class, 'user_access_roles', 'user_id', 'access_role_id')
                    ->withTimestamps();
    }

    /**
     * `User::create([... 'initial_role' => 'instructor'])`: vai trò gán lúc tạo (id hoặc slug của AccessRole).
     * Chỉ để khởi tạo, không được lưu thành cột; xem User::booted và AccessRoleAssigner::assignInitial.
     */
    public function setInitialRoleAttribute(?string $value): void
    {
        $this->pendingInitialRole = $value;
    }

    public function pendingInitialRole(): ?string
    {
        return $this->pendingInitialRole;
    }

    public function isStudentAccount(): bool
    {
        return $this->account_type === self::ACCOUNT_STUDENT;
    }

    /**
     * Hồ sơ trả cho frontend khi đăng nhập / /me. Không có `role`: quyền và khu vực lấy từ
     * GET /api/auth/permissions; `roles` chỉ để hiển thị.
     */
    public function toAuthArray(): array
    {
        return array_merge($this->toArray(), [
            'id'           => (string) $this->id,
            'name'         => $this->name,
            'email'        => $this->email,
            'initials'     => $this->initials ?? mb_strtoupper(mb_substr(trim($this->name ?: ''), 0, 2, 'UTF-8'), 'UTF-8'),
            'account_type' => $this->account_type,
            'roles'        => $this->roleSummaries(),
            'status'       => $this->status,
        ]);
    }

    /** @return array<int, array{id: string, name: string}> */
    public function roleSummaries(): array
    {
        return $this->accessRoles
            ->map(fn (AccessRole $role) => ['id' => (string) $role->id, 'name' => $role->name])
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function studentIdentity(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StudentIdentity::class, 'user_id', 'id');
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

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }
}
