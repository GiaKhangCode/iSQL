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
            'number'                       => 'required|string|max:50',
            'title'                        => 'required|string|max:255',
            'difficulty'                   => 'sometimes|nullable|string|in:Easy,Medium,Hard',
            'visibility'                   => 'sometimes|nullable|string|in:Public,Private',
            'practice_listed'              => 'sometimes|nullable|boolean',
            'practiceListed'               => 'sometimes|nullable|boolean',
            'topics'                       => 'sometimes|nullable',
            'statement'                    => 'sometimes|nullable|string',
            'description'                  => 'sometimes|nullable|string',
            'requirements'                 => 'sometimes|nullable|string',
            'hints'                        => 'sometimes|nullable|array',
            'hint'                         => 'sometimes|nullable|string',
            'database'                     => 'sometimes|nullable|string|max:50',
            'database_type'                => 'sometimes|nullable|string|max:50',
            'schemaFileName'               => 'sometimes|nullable|string|max:255',
            'schema_file_name'             => 'sometimes|nullable|string|max:255',
            'schema'                       => 'sometimes|nullable|string',
            'schema_sql'                   => 'sometimes|nullable|string',
            'seedData'                     => 'sometimes|nullable|string',
            'seed_data'                    => 'sometimes|nullable|string',
            'referenceSolution'            => 'sometimes|nullable|string',
            'reference_solution'           => 'sometimes|nullable|string',
            'workspaceParts'               => 'sometimes|nullable|array',
            'workspace_parts'              => 'sometimes|nullable|array',
            'databaseDescription'          => 'sometimes|nullable|array',
            'database_description'         => 'sometimes|nullable|array',
            'databaseDescriptionVisible'   => 'sometimes|nullable|boolean',
            'database_description_visible' => 'sometimes|nullable|boolean',
            'expectedOutputs'              => 'sometimes|nullable|array',
            'expected_outputs'             => 'sometimes|nullable|array',
            'expectedOutputVisible'        => 'sometimes|nullable|boolean',
            'expected_output_visible'      => 'sometimes|nullable|boolean',
            'sqlSolutions'                 => 'sometimes|nullable|array',
            'sqlSolutions.*.id'            => 'sometimes|nullable|string|max:50',
            'sqlSolutions.*.timeLimitMs'   => 'sometimes|nullable|integer|min:100|max:60000',
            'sqlSolutions.*.time_limit_ms' => 'sometimes|nullable|integer|min:100|max:60000',
            // Mỗi trường con phải được khai báo: validated() bỏ các khóa con không có quy tắc.
            'sqlSolutions.*.query'             => 'sometimes|nullable|string',
            'sqlSolutions.*.description'       => 'sometimes|nullable|string',
            'sqlSolutions.*.setupSql'          => 'sometimes|nullable|string',
            'sqlSolutions.*.setup_sql'         => 'sometimes|nullable|string',
            'sqlSolutions.*.enabled'           => 'sometimes|nullable|boolean',
            'sqlSolutions.*.expectedOutputs'   => 'sometimes|nullable|array',
            'sqlSolutions.*.expected_outputs'  => 'sometimes|nullable|array',
            'sqlSolutions.*.expectedOutputVisible'=> 'sometimes|nullable|boolean',
            'sqlSolutions.*.expected_output_visible'=> 'sometimes|nullable|boolean',
            'sqlSolutions.*.difficulty'        => 'sometimes|nullable|string|max:50',
            'sqlSolutions.*.category'          => 'sometimes|nullable|string|max:255',
            'sqlSolutions.*.checkRowOrder'     => 'sometimes|nullable|boolean',
            'sqlSolutions.*.check_row_order'   => 'sometimes|nullable|boolean',
            'sqlSolutions.*.ignoreColumnNames' => 'sometimes|nullable|boolean',
            'sqlSolutions.*.ignore_column_names'=> 'sometimes|nullable|boolean',
            'sql_solutions'                => 'sometimes|nullable|array',
            'sql_solutions.*.id'           => 'sometimes|nullable|string|max:50',
            'sql_solutions.*.timeLimitMs'  => 'sometimes|nullable|integer|min:100|max:60000',
            'sql_solutions.*.time_limit_ms'=> 'sometimes|nullable|integer|min:100|max:60000',
            'sql_solutions.*.query'            => 'sometimes|nullable|string',
            'sql_solutions.*.description'      => 'sometimes|nullable|string',
            'sql_solutions.*.setupSql'         => 'sometimes|nullable|string',
            'sql_solutions.*.setup_sql'        => 'sometimes|nullable|string',
            'sql_solutions.*.enabled'          => 'sometimes|nullable|boolean',
            'sql_solutions.*.expectedOutputs'  => 'sometimes|nullable|array',
            'sql_solutions.*.expected_outputs' => 'sometimes|nullable|array',
            'sql_solutions.*.expectedOutputVisible'=> 'sometimes|nullable|boolean',
            'sql_solutions.*.expected_output_visible'=> 'sometimes|nullable|boolean',
            'sql_solutions.*.difficulty'       => 'sometimes|nullable|string|max:50',
            'sql_solutions.*.category'         => 'sometimes|nullable|string|max:255',
            'sql_solutions.*.checkRowOrder'    => 'sometimes|nullable|boolean',
            'sql_solutions.*.check_row_order'  => 'sometimes|nullable|boolean',
            'sql_solutions.*.ignoreColumnNames'=> 'sometimes|nullable|boolean',
            'sql_solutions.*.ignore_column_names'=> 'sometimes|nullable|boolean',
            'triggerExercise'              => 'sometimes|nullable|array',
            'trigger_exercise'             => 'sometimes|nullable|array',
            'ddlExercise'                  => 'sometimes|nullable|array',
            'ddl_exercise'                 => 'sometimes|nullable|array',
            'dmlExercise'                  => 'sometimes|nullable|array',
            'dml_exercise'                 => 'sometimes|nullable|array',
            'testCases'                    => 'sometimes|nullable|array',
            'test_cases'                   => 'sometimes|nullable|array',
            'tables'                       => 'sometimes|nullable|array',
            'expected'                     => 'sometimes|nullable|array',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (trim((string) $this->input('number', '')) === '') {
                $v->errors()->add('number', 'Problem number cannot be blank.');
            }
            if (trim((string) $this->input('title', '')) === '') {
                $v->errors()->add('title', 'Problem title cannot be blank.');
            }

            $workspaceParts = $this->input('workspaceParts') ?? $this->input('workspace_parts');
            if (is_array($workspaceParts)) {
                $allowed = ['DDL', 'DML', 'SQL', 'TRIGGER'];
                $filtered = array_values(array_filter($allowed, fn ($p) => in_array($p, $workspaceParts, true)));
                if (empty($filtered)) {
                    $v->errors()->add('workspaceParts', 'Select at least one workspace part.');
                }
            }

            $sqlSolutions = $this->input('sqlSolutions') ?? $this->input('sql_solutions');
            if (is_array($sqlSolutions)) {
                $ids = [];
                foreach ($sqlSolutions as $sol) {
                    $id = (string) ($sol['id'] ?? '');
                    if (str_starts_with($id, '__base_')) {
                        $v->errors()->add('sqlSolutions', 'Question IDs starting with __base_ are reserved.');
                    }
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
                if (count($ids) !== count(array_unique($ids))) {
                    $v->errors()->add('sqlSolutions', 'Solution IDs must be unique.');
                }
            }

            foreach (['ddlExercise', 'ddl_exercise', 'dmlExercise', 'dml_exercise', 'triggerExercise', 'trigger_exercise'] as $field) {
                $exercise = $this->input($field);
                if (is_array($exercise) && isset($exercise['questions']) && is_array($exercise['questions'])) {
                    $ids = [];
                    foreach ($exercise['questions'] as $question) {
                        $id = (string) ($question['id'] ?? '');
                        if (str_starts_with($id, '__base_')) {
                            $v->errors()->add($field, 'Question IDs starting with __base_ are reserved.');
                        }
                        if (mb_strlen($id) > 50) {
                            $v->errors()->add($field, 'Question ID cannot exceed 50 characters.');
                        }
                        if ($id !== '') {
                            $ids[] = $id;
                        }
                    }
                    if (count($ids) !== count(array_unique($ids))) {
                        $v->errors()->add($field, 'Question IDs must be unique within a section.');
                    }
                }
            }
        });
    }
}
