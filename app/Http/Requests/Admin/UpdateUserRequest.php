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

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'email'      => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId, 'id')],
            'password'   => 'sometimes|nullable|string|min:8',
            'name'       => 'sometimes|required|string|max:255',
            'initials'   => 'sometimes|nullable|string|max:10',
            'role'       => ['sometimes', 'required', Rule::in(User::ROLES)],
            'status'     => 'sometimes|required|in:Active,Inactive,Pending',
            'department' => 'sometimes|nullable|string|max:255',
        ];
    }
}
