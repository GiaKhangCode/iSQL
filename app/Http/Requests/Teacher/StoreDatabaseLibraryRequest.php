<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class StoreDatabaseLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                  => ['required', 'string', 'max:255'],
            'databaseFileName'       => ['nullable', 'string', 'max:255'],
            'database_file_name'     => ['nullable', 'string', 'max:255'],
            'createTableFileName'    => ['nullable', 'string', 'max:255'],
            'create_table_file_name' => ['nullable', 'string', 'max:255'],
            'createTableSql'         => ['nullable', 'string'],
            'create_table_sql'       => ['nullable', 'string'],
            'insertIntoFileName'     => ['nullable', 'string', 'max:255'],
            'insert_into_file_name'  => ['nullable', 'string', 'max:255'],
            'insertIntoSql'          => ['nullable', 'string'],
            'insert_into_sql'        => ['nullable', 'string'],
            'datasets'               => ['nullable', 'array'],
        ];
    }

    /**
     * Inspect SQL safety to prevent hazardous administration commands.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $createSql = $this->input('createTableSql') ?? $this->input('create_table_sql') ?? '';
            $insertSql = $this->input('insertIntoSql') ?? $this->input('insert_into_sql') ?? '';

            $dangerousPatterns = [
                '/\bxp_cmdshell\b/i'         => 'xp_cmdshell command is not permitted.',
                '/\bsp_configure\b/i'        => 'sp_configure command is not permitted.',
                '/\bDROP\s+DATABASE\b/i'     => 'DROP DATABASE command is not permitted.',
                '/\bALTER\s+DATABASE\b/i'    => 'ALTER DATABASE command is not permitted.',
                '/\bSHUTDOWN\b/i'            => 'SHUTDOWN command is not permitted.',
                '/\bCREATE\s+LOGIN\b/i'      => 'CREATE LOGIN command is not permitted.',
                '/\bALTER\s+LOGIN\b/i'       => 'ALTER LOGIN command is not permitted.',
                '/\bDROP\s+LOGIN\b/i'        => 'DROP LOGIN command is not permitted.',
            ];

            foreach (['createTableSql' => $createSql, 'insertIntoSql' => $insertSql] as $field => $sql) {
                if (!empty($sql)) {
                    foreach ($dangerousPatterns as $pattern => $message) {
                        if (preg_match($pattern, $sql)) {
                            $v->errors()->add($field, 'SQL contains disallowed pattern: ' . $message);
                        }
                    }
                }
            }
        });
    }
}
