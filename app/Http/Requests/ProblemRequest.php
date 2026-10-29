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
            'number'                     => 'required|string|max:50',
            'title'                      => 'required|string|max:255',
            'difficulty'                 => 'required|string|in:Easy,Medium,Hard',
            'visibility'                 => 'sometimes|nullable|string|in:Public,Private',
            'practice_listed'            => 'sometimes|nullable|boolean',
            'topics'                     => 'sometimes|nullable',
            'statement'                  => 'required_without:description|nullable|string',
            'description'                => 'required_without:statement|nullable|string',
            'requirements'               => 'nullable|string',
            'hints'                      => 'sometimes|nullable|array',
            'hint'                       => 'sometimes|nullable|string',
            'database'                   => 'sometimes|nullable|string|max:50',
            'database_type'              => 'sometimes|nullable|string|max:50',
            'schemaFileName'             => 'sometimes|nullable|string|max:255',
            'schema_file_name'           => 'sometimes|nullable|string|max:255',
            'schema'                     => 'sometimes|nullable|string',
            'schema_sql'                 => 'sometimes|nullable|string',
            'seedData'                   => 'sometimes|nullable|string',
            'seed_data'                  => 'sometimes|nullable|string',
            'referenceSolution'          => 'sometimes|nullable|string',
            'reference_solution'         => 'sometimes|nullable|string',
            'workspaceParts'             => 'sometimes|nullable|array',
            'workspace_parts'            => 'sometimes|nullable|array',
            'databaseDescription'        => 'sometimes|nullable|array',
            'database_description'       => 'sometimes|nullable|array',
            'databaseDescriptionVisible' => 'sometimes|nullable|boolean',
            'database_description_visible' => 'sometimes|nullable|boolean',
            'expectedOutputs'            => 'sometimes|nullable|array',
            'expected_outputs'           => 'sometimes|nullable|array',
            'expectedOutputVisible'      => 'sometimes|nullable|boolean',
            'expected_output_visible'    => 'sometimes|nullable|boolean',
            'sqlSolutions'               => 'sometimes|nullable|array',
            'sql_solutions'              => 'sometimes|nullable|array',
            'testCases'                  => 'sometimes|nullable|array',
            'test_cases'                 => 'sometimes|nullable|array',
            'tables'                     => 'sometimes|nullable|array',
            'expected'                   => 'sometimes|nullable|array',
        ];
    }
}
