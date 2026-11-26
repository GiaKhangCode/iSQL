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
            // Loại danh tính (student|staff) và vai trò khởi tạo (id của vai trò động; bỏ trống thì theo loại tài khoản).
            'account_type' => ['required', Rule::in(User::ACCOUNT_TYPES)],
            'role_id'    => ['nullable', 'string', Rule::exists('access_roles', 'id')],
            'status'     => 'sometimes|in:Active,Inactive',
            'department' => 'nullable|string|max:255',
        ];
    }
}
