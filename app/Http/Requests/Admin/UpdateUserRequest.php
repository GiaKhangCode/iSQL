<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route applies auth:sanctum and role:admin middleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('role')) {
            $role = strtolower(trim((string) $this->input('role')));
            if ($role === 'lecturer' || $role === 'instructor') {
                $role = User::ROLE_INSTRUCTOR_PRACTICAL;
            } elseif ($role === 'student') {
                $role = User::ROLE_STUDENT;
            } elseif ($role === 'admin') {
                $role = User::ROLE_ADMIN;
            }
            $this->merge(['role' => $role]);
        }
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'email'      => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId, 'id')],
            'password'   => 'sometimes|nullable|string|min:8',
            'name'       => 'sometimes|required|string|max:255',
            'initials'   => 'sometimes|nullable|string|max:10',
            'role'       => ['sometimes', 'required', Rule::in(array_merge(User::ROLES, ['lecturer', 'instructor', 'student', 'admin']))],
            'status'     => 'sometimes|required|in:Active,Inactive,Pending',
            'department' => 'sometimes|nullable|string|max:255',
        ];
    }
}
