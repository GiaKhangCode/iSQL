<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class TestRunDatabaseLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'createTableSql'   => ['nullable', 'string'],
            'create_table_sql' => ['nullable', 'string'],
            'insertIntoSql'    => ['nullable', 'string'],
            'insert_into_sql'  => ['nullable', 'string'],
        ];
    }
}
