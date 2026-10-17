<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterLecturerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'      => 'required|email|max:255|unique:users,email',
            'password'   => 'required|string|min:6',
            'name'       => 'required|string|max:255',
            'initials'   => 'nullable|string|max:10',
            'department' => 'required|string|max:255',
        ];
    }
}
