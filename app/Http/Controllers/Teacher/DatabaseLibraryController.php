<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\DatabaseLibraryEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DatabaseLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $entries = DatabaseLibraryEntry::where('instructor_id', $user->id)
            ->orWhereNull('instructor_id')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json($entries->map->toResponseArray());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $entry = DatabaseLibraryEntry::findOrFail($id);

        return response()->json($entry->toResponseArray());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $data = $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'databaseFileName'    => ['nullable', 'string', 'max:255'],
            'database_file_name'  => ['nullable', 'string', 'max:255'],
            'createTableFileName' => ['nullable', 'string', 'max:255'],
            'create_table_file_name' => ['nullable', 'string', 'max:255'],
            'createTableSql'      => ['nullable', 'string'],
            'create_table_sql'    => ['nullable', 'string'],
            'insertIntoFileName'  => ['nullable', 'string', 'max:255'],
            'insert_into_file_name' => ['nullable', 'string', 'max:255'],
            'insertIntoSql'       => ['nullable', 'string'],
            'insert_into_sql'     => ['nullable', 'string'],
            'datasets'            => ['nullable', 'array'],
        ]);

        $datasets = $request->input('datasets', []);
        $firstDataset = is_array($datasets) && count($datasets) > 0 ? $datasets[0] : [];
        $insertIntoFileName = $firstDataset['fileName'] ?? $request->input('insertIntoFileName') ?? $request->input('insert_into_file_name');
        $insertIntoSql = $firstDataset['sql'] ?? $request->input('insertIntoSql') ?? $request->input('insert_into_sql') ?? '';

        $entry = DatabaseLibraryEntry::create([
            'id'                     => (string) Str::uuid(),
            'instructor_id'          => $user->id,
            'title'                  => trim($data['title']),
            'database_file_name'     => $request->input('databaseFileName') ?? $request->input('database_file_name'),
            'create_table_file_name' => $request->input('createTableFileName') ?? $request->input('create_table_file_name'),
            'insert_into_file_name'  => $insertIntoFileName,
            'create_table_sql'       => $request->input('createTableSql') ?? $request->input('create_table_sql') ?? '',
            'insert_into_sql'        => $insertIntoSql,
            'datasets_json'          => $datasets,
        ]);

        return response()->json($entry->toResponseArray(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $entry = DatabaseLibraryEntry::findOrFail($id);
        if ($entry->instructor_id && $entry->instructor_id !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You do not have permission to edit this database library entry.');
        }

        $data = $request->validate([
            'title'               => ['sometimes', 'required', 'string', 'max:255'],
            'databaseFileName'    => ['nullable', 'string', 'max:255'],
            'database_file_name'  => ['nullable', 'string', 'max:255'],
            'createTableFileName' => ['nullable', 'string', 'max:255'],
            'create_table_file_name' => ['nullable', 'string', 'max:255'],
            'createTableSql'      => ['nullable', 'string'],
            'create_table_sql'    => ['nullable', 'string'],
            'insertIntoFileName'  => ['nullable', 'string', 'max:255'],
            'insert_into_file_name' => ['nullable', 'string', 'max:255'],
            'insertIntoSql'       => ['nullable', 'string'],
            'insert_into_sql'     => ['nullable', 'string'],
            'datasets'            => ['nullable', 'array'],
        ]);

        $datasets = $request->has('datasets') ? $request->input('datasets', []) : $entry->datasets_json;
        $firstDataset = is_array($datasets) && count($datasets) > 0 ? $datasets[0] : [];

        $entry->update([
            'title'                  => $request->has('title') ? trim($data['title']) : $entry->title,
            'database_file_name'     => $request->input('databaseFileName') ?? $request->input('database_file_name') ?? $entry->database_file_name,
            'create_table_file_name' => $request->input('createTableFileName') ?? $request->input('create_table_file_name') ?? $entry->create_table_file_name,
            'insert_into_file_name'  => $firstDataset['fileName'] ?? $request->input('insertIntoFileName') ?? $request->input('insert_into_file_name') ?? $entry->insert_into_file_name,
            'create_table_sql'       => $request->input('createTableSql') ?? $request->input('create_table_sql') ?? $entry->create_table_sql,
            'insert_into_sql'        => $firstDataset['sql'] ?? $request->input('insertIntoSql') ?? $request->input('insert_into_sql') ?? $entry->insert_into_sql,
            'datasets_json'          => $datasets,
        ]);

        return response()->json($entry->toResponseArray());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $entry = DatabaseLibraryEntry::findOrFail($id);
        if ($entry->instructor_id && $entry->instructor_id !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You do not have permission to delete this database library entry.');
        }

        $entry->delete();

        return response()->json(['ok' => true]);
    }

    private function assertCanAccess(User $user): void
    {
        $isAdmin = $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isTeacher = in_array($user->role, User::INSTRUCTOR_ROLES)
            || $user->role === User::ROLE_DEPARTMENT_HEAD
            || $user->canDo('PROBLEM_MGMT', 'view');

        abort_unless($isAdmin || $isTeacher, 403, 'Instructor access required.');
    }
}
