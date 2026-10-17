<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                => 'required|string|max:255',
            'is_contest'           => 'boolean',
            'instructions'         => 'nullable|string',
            'audience_type'        => 'string|max:20',
            'short_description'    => 'nullable|string|max:180',
            'description'          => 'nullable|string',
            'rules'                => 'nullable|string',
            'opens'                => 'nullable|date',
            'closes'               => 'nullable|date',
            'published'            => 'boolean',
            'hints_enabled'        => 'boolean',
            'comments_enabled'     => 'boolean',
            'leaderboard_enabled'  => 'boolean',
            'ai_allowed'           => 'boolean',
            'exam_type'            => 'string|in:classic,comprehensive',
            'ddl_reference_script' => 'nullable|string',
            'dml_seed_script'      => 'nullable|string',
            'ddl_points'           => 'integer',
            'dml_points'           => 'integer',
            'sql_points'           => 'integer',
            'problem_ids'          => 'nullable|array',
            'class_ids'            => 'nullable|array',
        ];
    }
}
