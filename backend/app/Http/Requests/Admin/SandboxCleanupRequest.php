<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SandboxCleanupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target'  => 'required|string|in:class,orphans',
            'classId' => 'required_if:target,class|string',
            'confirm' => 'required|string',
        ];
    }
}
