<?php

namespace App\Services;

use App\Models\User;
use App\Mail\ResetPasswordOtpMail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class UserService
{
    public function registerStudent(array $data): User
    {
        $hashedPassword = Hash::make($data['password']);
        $initials = mb_strtoupper(mb_substr(trim($data['name']), 0, 2, 'UTF-8'), 'UTF-8');

        $user = User::create([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'hashed_password' => $hashedPassword,
            'initials'        => $initials,
            'role'            => 'student',
            'status'          => 'Active',
        ]);

        $studentGroup = \App\Models\RoleGroup::where('name', 'like', '%Sinh viên%')->first();
        if ($studentGroup) {
            $user->roleGroups()->syncWithoutDetaching([$studentGroup->id]);
        }

        return $user;
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
            'role'            => 'instructor',
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
        return User::with(['roleGroups', 'directRoles'])->orderBy('created_at', 'desc')->get();
    }

    /**
     * Create a user account from validated administrator input
     */
    public function createUser(array $data): User
    {
        return User::create([
            'email'           => $data['email'],
            'hashed_password' => Hash::make($data['password']),
            'name'            => $data['name'],
            'initials'        => $data['initials'] ?? strtoupper(substr($data['name'], 0, 2)),
            'role'            => $data['role'],
            'status'          => $data['status'] ?? (
                in_array($data['role'], User::INSTRUCTOR_ROLES, true) ? 'Pending' : 'Active'
            ),
            'department'      => $data['department'] ?? null,
        ]);

        if ($user->status === 'Active') {
            if ($user->role === User::ROLE_STUDENT) {
                $studentGroup = \App\Models\RoleGroup::where('name', 'like', '%Sinh viên%')->first();
                if ($studentGroup) {
                    $user->roleGroups()->syncWithoutDetaching([$studentGroup->id]);
                }
            } elseif (in_array($user->role, User::INSTRUCTOR_ROLES, true)) {
                $lecturerGroup = \App\Models\RoleGroup::where('name', 'like', '%Giảng viên%')->first();
                if ($lecturerGroup) {
                    $user->roleGroups()->syncWithoutDetaching([$lecturerGroup->id]);
                }
            }
        }

        return $user;
    }

    /**
     * Find one user by its ID.
     */
    public function getUser(string $id): User
    {
        return User::findOrFail($id);
    }

    /**
     * Update the supplied profile fields and optionally replace the password.
     */
    public function updateUser(string $id, array $data): User
    {
        $user = User::findOrFail($id);
        $credentialsChanged = false;

        if (isset($data['role']) && $data['role'] !== $user->role) {
            $this->assertRoleChangeAllowed($user, $data['role']);
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
     * Update user account status (Active, Inactive, Pending)
     */
    public function updateStatus(string $id, string $status): User
    {
        $user = User::findOrFail($id);
        $user->status = $status;
        $user->save();

        if ($status !== 'Active') {
            $user->tokens()->delete();
        } else {
            if ($user->role === User::ROLE_STUDENT) {
                $studentGroup = \App\Models\RoleGroup::where('name', 'like', '%Sinh viên%')->first();
                if ($studentGroup) {
                    $user->roleGroups()->syncWithoutDetaching([$studentGroup->id]);
                }
            } elseif (in_array($user->role, User::INSTRUCTOR_ROLES, true)) {
                $lecturerGroup = \App\Models\RoleGroup::where('name', 'like', '%Giảng viên%')->first();
                if ($lecturerGroup) {
                    $user->roleGroups()->syncWithoutDetaching([$lecturerGroup->id]);
                }
            }
        }

        return $user;
    }

    /**
     * Delete user account
     */
    public function deleteUser(string $id): void
    {
        $user = User::findOrFail($id);
        $user->delete();
    }

    public function resetStudentPassword(string $id, string $password): User
    {
        $user = User::findOrFail($id);

        if ($user->role !== User::ROLE_STUDENT) {
            throw ValidationException::withMessages([
                'user_id' => ['Only student passwords can be reset through this endpoint.'],
            ]);
        }

        $user->hashed_password = Hash::make($password);
        $user->save();
        $user->tokens()->delete();

        return $user->refresh();
    }

    public function importCsvRows(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $users = [];
            foreach ($rows as $row) {
                $users[] = $this->createUser($row);
            }

            return $users;
        });
    }

    private function assertRoleChangeAllowed(User $user, string $role): void
    {
        if (in_array($user->role, User::INSTRUCTOR_ROLES, true)
            && ! in_array($role, User::INSTRUCTOR_ROLES, true)
            && ($user->taughtClasses()->exists() || $user->assignedClasses()->exists())) {
            throw ValidationException::withMessages([
                'role' => ['Unassign this user from all classes before changing their role to a non-instructor role.'],
            ]);
        }
    }
}
