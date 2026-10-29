<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email'      => 'required|email|max:255|unique:users,email',
            'password'   => 'required|string|min:8',
            'name'       => 'required|string|max:255',
            'initials'   => 'nullable|string|max:10',
            'role'       => ['required', Rule::in(array_merge(User::ROLES, ['lecturer', 'instructor', 'student', 'admin']))],
            'status'     => 'sometimes|in:Active,Inactive,Pending',
            'department' => 'nullable|string|max:255',
        ];
    }
}
