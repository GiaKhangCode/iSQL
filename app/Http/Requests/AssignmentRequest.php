<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && ($user->isAdmin() || $user->isInstructor());
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['status'])) {
            $data['published'] = in_array(strtolower((string) $data['status']), ['published', 'open'], true);
        }
        if (isset($data['isContest'])) {
            $data['is_contest'] = filter_var($data['isContest'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['classIds'])) {
            $data['class_ids'] = $data['classIds'];
        }
        if (isset($data['audienceType'])) {
            $data['audience_type'] = $data['audienceType'];
        }
        if (isset($data['shortDescription'])) {
            $data['short_description'] = $data['shortDescription'];
        }
        if (isset($data['bannerUrl'])) {
            $data['banner_url'] = $data['bannerUrl'];
        }
        if (isset($data['bannerSourceUrl'])) {
            $data['banner_source_url'] = $data['bannerSourceUrl'];
        }
        if (isset($data['bannerCrop'])) {
            $data['banner_crop'] = is_array($data['bannerCrop']) ? json_encode($data['bannerCrop']) : $data['bannerCrop'];
        }

        // Student options mapping
        if (isset($data['studentOptions']) && is_array($data['studentOptions'])) {
            $opts = $data['studentOptions'];
            if (isset($opts['hints'])) $data['hints_enabled'] = filter_var($opts['hints'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['comments'])) $data['comments_enabled'] = filter_var($opts['comments'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['leaderboard'])) $data['leaderboard_enabled'] = filter_var($opts['leaderboard'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['aiAllowed'])) $data['ai_allowed'] = filter_var($opts['aiAllowed'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['examMode'])) $data['exam_mode'] = filter_var($opts['examMode'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['showScoresAfterSubmit'])) $data['show_scores_after_submit'] = filter_var($opts['showScoresAfterSubmit'], FILTER_VALIDATE_BOOLEAN);
            if (array_key_exists('maxSubmissions', $opts)) $data['max_submissions'] = $opts['maxSubmissions'];
        }

        if (isset($data['opens']) && $data['opens']) {
            $data['opens'] = date('Y-m-d H:i:s', strtotime($data['opens']));
        }
        if (isset($data['closes']) && $data['closes']) {
            $data['closes'] = date('Y-m-d H:i:s', strtotime($data['closes']));
        }

        $this->replace($data);
    }

    public function rules(): array
    {
        return [
            'title'                     => 'required|string|max:255',
            'is_contest'                => 'sometimes|boolean',
            'instructions'              => 'nullable|string',
            'audience_type'             => 'sometimes|string|in:classes,all_students',
            'short_description'         => 'nullable|string|max:180',
            'description'               => 'nullable|string',
            'rules'                     => 'nullable|string',
            'banner_url'                => 'nullable|string|max:255',
            'banner_source_url'         => 'nullable|string|max:255',
            'banner_crop'               => 'nullable|string|max:160',
            'opens'                     => 'nullable|date',
            'closes'                    => 'nullable|date',
            'published'                 => 'sometimes|boolean',
            'hints_enabled'             => 'sometimes|boolean',
            'comments_enabled'          => 'sometimes|boolean',
            'leaderboard_enabled'       => 'sometimes|boolean',
            'ai_allowed'                => 'sometimes|boolean',
            'exam_mode'                 => 'sometimes|boolean',
            'show_scores_after_submit'  => 'sometimes|boolean',
            'max_submissions'           => 'nullable|integer|min:1',
            'exam_type'                 => 'sometimes|string|in:classic,comprehensive',
            'ddl_reference_script'      => 'nullable|string',
            'dml_seed_script'           => 'nullable|string',
            'ddl_points'                => 'sometimes|integer',
            'dml_points'                => 'sometimes|integer',
            'sql_points'                => 'sometimes|integer',
            'problems'                  => 'nullable|array',
            'problem_ids'               => 'nullable|array',
            'class_ids'                 => 'nullable|array',
        ];
    }
}
