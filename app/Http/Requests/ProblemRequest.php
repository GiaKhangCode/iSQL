<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProblemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number'         => 'required|string|max:50',
            'title'          => 'required|string|max:255',
            'difficulty'     => 'required|in:Easy,Medium,Hard',
            'description'    => 'required|string',
            'requirements'   => 'required|string',
            'hint'           => 'nullable|string',
            'practice_listed'=> 'boolean',
            'database_type'  => 'string|max:50',
            'topics'         => 'nullable|array',
            'tables'         => 'nullable|array',
            'expected'       => 'nullable|array',
        ];
    }
}
