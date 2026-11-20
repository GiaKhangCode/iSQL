<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/sharing/{kind}/{resourceId}: chia sẻ cho một giảng viên theo email. */
class ShareContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'min:3', 'max:255'],
            'can_edit' => ['nullable', 'boolean'],
            'canEdit'  => ['nullable', 'boolean'],
        ];
    }

    public function canEdit(): bool
    {
        $data = $this->validated();

        return (bool) ($data['can_edit'] ?? $data['canEdit'] ?? false);
    }
}
