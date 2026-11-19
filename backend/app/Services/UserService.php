<?php

namespace App\Services;

use App\Models\User;
use App\Mail\ResetPasswordOtpMail;
use App\Services\Access\AccessControlService;
use App\Models\AccessRole;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class UserService
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    /** Không được để hệ thống không còn siêu quản trị Active nào. */
    private function assertNotLastSuperAdmin(User $user, string $field, string $message): void
    {
        if ($this->access->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages([$field => [$message]]);
        }
    }

    public function registerStudent(array $data): User
    {
        $hashedPassword = Hash::make($data['password']);
        $initials = mb_strtoupper(mb_substr(trim($data['name']), 0, 2, 'UTF-8'), 'UTF-8');

        // Vai trò "Sinh viên" mặc định được gán bởi hook User::created (AccessRoleAssigner).
        return User::create([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'hashed_password' => $hashedPassword,
            'initials'        => $initials,
            'account_type'    => User::ACCOUNT_STUDENT,
            'status'          => 'Active',
        ]);
    }

    public function registerLecturer(array $data): User
    {
        $hashedPassword = Hash::make($data['password']);
        $initials = mb_strtoupper(mb_substr(trim($data['name']), 0, 2, 'UTF-8'), 'UTF-8');

        return User::create([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'hashed_password' => $hashedPassword,
            'initials'        => $initials,
            'account_type'    => User::ACCOUNT_STAFF,
            'initial_role'    => 'instructor',
            'status'          => 'Pending',
            'department'      => $data['department'],
        ]);
    }

    public function authenticate(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->hashed_password)) {
            throw new \Exception('Email hoặc mật khẩu không chính xác.', 401);
        }

        if ($user->status === 'Pending') {
            throw new \Exception('Tài khoản của bạn đang chờ quản trị viên phê duyệt.', 403);
        }

        if ($user->status === 'Inactive') {
            throw new \Exception('Tài khoản của bạn đã bị khóa.', 403);
        }

        return $user;
    }

    public function sendPasswordResetOtp(string $email): string
    {
        $user = User::where('email', $email)->first();
        $otp = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        DB::table('password_reset_tokens')->insert([
            'email'      => $email,
            'token'      => $otp,
            'created_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        Log::info("Password reset OTP for {$email}: {$otp}");
        Mail::to($email)->send(new ResetPasswordOtpMail($otp, $user?->name));

        return $otp;
    }

    public function verifyOtp(string $email, string $otp): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $otp)
            ->first();

        if (!$record) {
            throw new \Exception('Mã xác thực không chính xác.', 400);
        }

        if (Carbon::parse($record->expires_at)->isPast()) {
            throw new \Exception('Mã xác thực đã hết hạn. Vui lòng yêu cầu mã mới.', 400);
        }

        return true;
    }

    public function resetPassword(string $email, string $otp, string $newPassword): void
    {
        $this->verifyOtp($email, $otp);

        $user = User::where('email', $email)->first();
        if (!$user) {
            throw new \Exception('Không tìm thấy tài khoản người dùng.', 404);
        }

        $user->hashed_password = Hash::make($newPassword);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }

    /**
     * Get all users ordered by creation date
     */
    public function getAllUsers(): Collection
    {
        return User::with('accessRoles')->orderBy('created_at', 'desc')->get();
    }

    /**
     * Vai trò chọn được khi tạo tài khoản (danh sách vai trò động), sắp theo tên.
     *
     * @return array<int, array{id: string, name: string, isSuper: bool}>
     */
    public function assignableRoles(): array
    {
        return AccessRole::orderBy('name')->get(['id', 'name', 'is_super'])
            ->map(fn (AccessRole $role) => ['id' => (string) $role->id, 'name' => $role->name, 'isSuper' => (bool) $role->is_super])
            ->all();
    }

    /**
     * Create a user account from validated administrator input.
     *
     * $data['account_type'] là loại danh tính (student|staff); $data['role_id'] (hoặc $data['role']: id, slug
     * hoặc tên vai trò, dùng cho file CSV) là vai trò khởi tạo, không lưu thành cột. Không chọn vai trò thì tài
     * khoản sinh viên nhận vai trò "Sinh viên", tài khoản nhân sự chưa có vai trò nào.
     */
    public function createUser(array $data, ?User $actor = null): User
    {
        $role = $this->resolveInitialRole($data['role_id'] ?? $data['role'] ?? null);
        if ($role !== null) {
            $this->assertMayGrantRole($role, $actor);
        }

        $initials = $data['initials'] ?? null;
        if (!$initials && !empty($data['name'])) {
            $initials = mb_strtoupper(mb_substr(trim($data['name']), 0, 2, 'UTF-8'), 'UTF-8');
        }

        // Vai trò khởi tạo được gán khi tạo (User::created → AccessRoleAssigner);
        // quyền chỉ có hiệu lực khi tài khoản Active.
        return User::create([
            'email'           => $data['email'],
            'hashed_password' => Hash::make($data['password']),
            'name'            => $data['name'],
            'initials'        => $initials ?: 'US',
            'account_type'    => $data['account_type'],
            'initial_role'    => $role ? (string) $role->id : null,
            'status'          => $data['status'] ?? 'Active',
            'department'      => $data['department'] ?? null,
        ]);
    }

    /** Tìm vai trò theo id, slug hoặc tên (không phân biệt hoa thường); null nếu không nhập. */
    private function resolveInitialRole(mixed $key): ?AccessRole
    {
        if ($key === null || trim((string) $key) === '') {
            return null;
        }

        $key = trim((string) $key);
        $role = AccessRole::where('id', $key)->orWhere('slug', $key)->first()
            ?? AccessRole::whereRaw('LOWER(name) = ?', [mb_strtolower($key)])->first();

        if ($role === null) {
            throw ValidationException::withMessages(['role_id' => ["Vai trò '{$key}' không tồn tại."]]);
        }

        return $role;
    }

    /** Vai trò siêu quản trị hoặc có access.manage chỉ người có access.manage mới được cấp. */
    private function assertMayGrantRole(AccessRole $role, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        $sensitive = $role->is_super || in_array('access.manage', $role->permissionCodes(), true);
        if ($sensitive && ! $this->access->can($actor, 'access.manage')) {
            throw new HttpException(403, 'Chỉ người có quyền quản lý vai trò mới được cấp vai trò này.');
        }
    }

    /**
     * Find one user by ID or Email.
     */
    public function getUser(string $idOrEmail): User
    {
        return User::where('id', $idOrEmail)->orWhere('email', $idOrEmail)->firstOrFail();
    }

    /**
     * Update the supplied profile fields and optionally replace the password.
     */
    public function updateUser(string $idOrEmail, array $data): User
    {
        $user = $this->getUser($idOrEmail);
        $credentialsChanged = false;

        if (isset($data['status']) && $data['status'] !== 'Active') {
            $this->assertNotLastSuperAdmin($user, 'status', 'Không thể khóa quản trị viên đang hoạt động cuối cùng.');
        }

        if (array_key_exists('initials', $data) && $data['initials'] === null) {
            unset($data['initials']);
        }

        if (array_key_exists('password', $data)) {
            if ($data['password'] !== null && $data['password'] !== '') {
                $user->hashed_password = Hash::make($data['password']);
                $credentialsChanged = true;
            }
            unset($data['password']);
        }

        $user->fill($data);
        $user->save();

        if ($credentialsChanged || (array_key_exists('status', $data) && $data['status'] !== 'Active')) {
            $user->tokens()->delete();
        }

        return $user->refresh();
    }

    /**
     * Format a user model for Admin frontend response
     */
    public function formatUserResponse(User $user): array
    {
        $joined = $user->created_at ? $user->created_at->format('M d, Y') : 'Unknown';
        $lastActive = $user->last_active ? $user->last_active->format('M d, Y') : ($user->updated_at ? $user->updated_at->format('M d, Y') : 'Unknown');

        return array_merge($user->toArray(), [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'account_type'   => $user->account_type,
            'roles'          => $user->roleSummaries(),
            'status'         => $user->status ?? 'Active',
            'lastActive'     => $lastActive,
            'last_active'    => $lastActive,
            'joined'         => $joined,
            'detail'         => $user->department ?: '',
        ]);
    }

    /**
     * Update user account status (Active, Inactive, Pending)
     */
    public function updateStatus(string $id, string $status): User
    {
        $user = User::findOrFail($id);
        if ($status !== 'Active') {
            $this->assertNotLastSuperAdmin($user, 'status', 'Không thể khóa quản trị viên đang hoạt động cuối cùng.');
        }
        $user->status = $status;
        $user->save();

        if ($status !== 'Active') {
            $user->tokens()->delete();
        }
        // Vai trò đã được gán từ lúc tạo tài khoản; chuyển sang Active là quyền có hiệu lực (không cần gán lại).
        $this->access->forget($user->id);

        return $user;
    }

    /**
     * Delete user account
     */
    public function deleteUser(string $id): void
    {
        $user = User::findOrFail($id);
        $this->assertNotLastSuperAdmin($user, 'user_id', 'Không thể xóa quản trị viên đang hoạt động cuối cùng.');
        $user->delete();
    }

    public function resetStudentPassword(string $id, string $password): User
    {
        $user = User::findOrFail($id);

        if (! $user->isStudentAccount()) {
            throw ValidationException::withMessages([
                'user_id' => ['Only student passwords can be reset through this endpoint.'],
            ]);
        }

        $user->hashed_password = Hash::make($password);
        $user->save();
        $user->tokens()->delete();

        return $user->refresh();
    }

    public function importCsvRows(array $rows, ?User $actor = null): array
    {
        return DB::transaction(function () use ($rows, $actor): array {
            $users = [];
            foreach ($rows as $row) {
                $users[] = $this->createUser($row, $actor);
            }

            return $users;
        });
    }
}
