<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route applies auth:sanctum and role:admin middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'email'      => 'required|email|max:255|unique:users,email',
            'password'   => 'required|string|min:8',
            'name'       => 'required|string|max:255',
            'initials'   => 'nullable|string|max:10',
            'role'       => ['required', Rule::in(User::ROLES)],
            'status'     => 'sometimes|in:Active,Inactive,Pending',
            'department' => 'nullable|string|max:255',
        ];
    }
}
