<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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

        // `initials` is non-nullable in the users table. Treat an explicit
        // null as "leave the current initials unchanged".
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
