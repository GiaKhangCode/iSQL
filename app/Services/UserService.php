<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    /**
     * Get all users ordered by creation date
     */
    public function getAllUsers(): Collection
    {
        return User::orderBy('created_at', 'desc')->get();
    }

    /**
     * Authenticate user credentials and return user model
     */
    public function authenticate(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->hashed_password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password'],
            ]);
        }

        if ($user->status !== 'Active') {
            throw new \Exception('Account is pending approval or inactive', 403);
        }

        return $user;
    }

    /**
     * Register a new student account
     */
    public function registerStudent(array $data): User
    {
        return User::create([
            'email'           => $data['email'],
            'hashed_password' => Hash::make($data['password']),
            'name'            => $data['name'],
            'initials'        => $data['initials'] ?? strtoupper(substr($data['name'], 0, 2)),
            'role'            => 'student',
            'status'          => 'Active',
            'department'      => $data['department'] ?? null,
        ]);
    }

    /**
     * Register a new lecturer account (Pending approval)
     */
    public function registerLecturer(array $data): User
    {
        return User::create([
            'email'           => $data['email'],
            'hashed_password' => Hash::make($data['password']),
            'name'            => $data['name'],
            'initials'        => $data['initials'] ?? strtoupper(substr($data['name'], 0, 2)),
            'role'            => 'instructor',
            'status'          => 'Pending',
            'department'      => $data['department'],
        ]);
    }

    /**
     * Update user account status (Active, Inactive, Pending)
     */
    public function updateStatus(string $id, string $status): User
    {
        $user = User::findOrFail($id);
        $user->status = $status;
        $user->save();

        return $user;
    }

    /**
     * Update user role (student, instructor, admin)
     */
    public function updateRole(string $id, string $role): User
    {
        $user = User::findOrFail($id);
        $user->role = $role;
        $user->save();

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
}
