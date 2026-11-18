<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && (
            $user->isAdmin()
            || $user->isFacultyOffice()
            || $user->isInstructor()
            || $user->canDo('ASSIGNMENT_MGMT', 'add')
            || $user->canDo('ASSIGNMENT_MGMT', 'edit')
        );
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['status'])) {
            $data['published'] = in_array(strtolower((string) $data['status']), ['published', 'open'], true);
        }
        if (isset($data['classIds'])) {
            $data['class_ids'] = (array) $data['classIds'];
        }
        if (isset($data['studentEmails'])) {
            $data['student_emails'] = (array) $data['studentEmails'];
        }
        if (isset($data['scoringMode'])) {
            $data['scoring_mode'] = (string) $data['scoringMode'];
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
        if (isset($data['admissionRequired'])) {
            $data['admission_required'] = filter_var($data['admissionRequired'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['classTransfers']) && is_array($data['classTransfers'])) {
            $data['class_transfers'] = $data['classTransfers'];
        }

        // Student options mapping
        if (isset($data['studentOptions']) && is_array($data['studentOptions'])) {
            $opts = $data['studentOptions'];
            if (isset($opts['hints'])) $data['hints_enabled'] = filter_var($opts['hints'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['comments'])) $data['comments_enabled'] = filter_var($opts['comments'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['leaderboard'])) $data['leaderboard_enabled'] = filter_var($opts['leaderboard'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['aiAllowed'])) $data['ai_allowed'] = filter_var($opts['aiAllowed'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['examMode'])) $data['exam_mode'] = filter_var($opts['examMode'], FILTER_VALIDATE_BOOLEAN);
            if (isset($opts['examViolationLimit'])) $data['exam_violation_limit'] = (int) $opts['examViolationLimit'];
            if (isset($opts['showScoresAfterSubmit'])) $data['show_scores_after_submit'] = filter_var($opts['showScoresAfterSubmit'], FILTER_VALIDATE_BOOLEAN);
            if (array_key_exists('maxSubmissions', $opts)) $data['max_submissions'] = $opts['maxSubmissions'];
        }

        if (isset($data['opens']) && $data['opens']) {
            $ts = strtotime((string) $data['opens']);
            if ($ts !== false) {
                $data['opens'] = date('Y-m-d H:i:s', $ts);
            }
        }
        if (isset($data['closes']) && $data['closes']) {
            $ts = strtotime((string) $data['closes']);
            if ($ts !== false) {
                $data['closes'] = date('Y-m-d H:i:s', $ts);
            }
        }

        // Normalize problem items
        if (isset($data['problems']) && is_array($data['problems'])) {
            $normalizedProblems = [];
            foreach ($data['problems'] as $index => $item) {
                if (!is_array($item)) {
                    $normalizedProblems[] = ['id' => $item, 'points' => 10, 'order_index' => $index];
                    continue;
                }
                $normalizedProblems[] = [
                    'id'                           => $item['id'] ?? null,
                    'points'                       => isset($item['points']) ? (float) $item['points'] : 10.0,
                    'order_index'                  => $index,
                    'workspace_parts'              => $item['workspaceParts'] ?? $item['workspace_parts'] ?? ['DDL', 'DML', 'SQL'],
                    'dataset_visibility'           => $item['datasetVisibility'] ?? $item['dataset_visibility'] ?? [],
                    'question_selection'           => $item['questionSelection'] ?? $item['question_selection'] ?? null,
                    'database_structure_visible'   => $item['databaseStructureVisible'] ?? $item['database_structure_visible'] ?? true,
                    'database_description_visible' => $item['databaseDescriptionVisible'] ?? $item['database_description_visible'] ?? true,
                ];
            }
            $data['problems'] = $normalizedProblems;
        }

        $this->replace($data);
    }

    public function rules(): array
    {
        return [
            'title'                     => 'required|string|max:255',
            'scoring_mode'              => 'sometimes|string|max:30',
            'instructions'              => 'nullable|string',
            'audience_type'             => 'sometimes|string|in:classes',
            'short_description'         => 'nullable|string|max:180',
            'description'               => 'nullable|string',
            'rules'                     => 'nullable|string',
            'banner_url'                => 'nullable|string|max:255',
            'banner_source_url'         => 'nullable|string|max:255',
            'banner_crop'               => 'nullable|string|max:160',
            'opens'                     => 'nullable|date',
            'closes'                    => 'nullable|date' . ($this->filled('opens') ? '|after:opens' : ''),
            'published'                 => 'sometimes|boolean',
            'hints_enabled'             => 'sometimes|boolean',
            'comments_enabled'          => 'sometimes|boolean',
            'leaderboard_enabled'       => 'sometimes|boolean',
            'ai_allowed'                => 'sometimes|boolean',
            'exam_mode'                 => 'sometimes|boolean',
            'exam_violation_limit'      => 'sometimes|integer|min:0',
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
            'class_transfers'           => 'nullable|array',
            'student_emails'            => 'nullable|array',
            'proctors'                  => 'nullable|array',
            'room'                      => 'nullable|string|max:255',
            'capacity'                  => 'nullable|integer|min:1',
            'admission_required'        => 'sometimes|boolean',
            'class_transfers'           => 'nullable|array',
            'classTransfers'            => 'nullable|array',
        ];
    }
}
