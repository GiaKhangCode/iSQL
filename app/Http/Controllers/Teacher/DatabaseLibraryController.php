<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreDatabaseLibraryRequest;
use App\Http\Requests\Teacher\UpdateDatabaseLibraryRequest;
use App\Models\DatabaseLibraryEntry;
use App\Models\User;
use App\Services\ProblemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DatabaseLibraryController extends Controller
{
    public function __construct(
        protected ProblemService $problemService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $query = DatabaseLibraryEntry::query();

        // Scope filter: 'my' (only mine), 'system' (system templates), 'all' (default: accessible to me)
        $scope = $request->query('scope', 'all');
        if ($scope === 'my') {
            $query->where('instructor_id', $user->id);
        } elseif ($scope === 'system') {
            $query->whereNull('instructor_id');
        } else {
            // Accessible: my entries + system entries (or all if admin)
            if (! $user->isAdmin()) {
                $query->where(function ($q) use ($user) {
                    $q->where('instructor_id', $user->id)
                      ->orWhereNull('instructor_id');
                });
            }
        }

        // Search by title or filenames
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('database_file_name', 'like', "%{$search}%")
                  ->orWhere('create_table_file_name', 'like', "%{$search}%");
            });
        }

        $query->orderBy('updated_at', 'desc');

        // Optional pagination: if 'page' or 'per_page' query parameter is present
        if ($request->has('page') || $request->has('per_page')) {
            $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
            $paginator = $query->paginate($perPage);

            return response()->json([
                'data'        => collect($paginator->items())->map->toResponseArray(),
                'total'       => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'lastPage'    => $paginator->lastPage(),
                'perPage'     => $paginator->perPage(),
            ]);
        }

        $entries = $query->get();

        return response()->json($entries->map->toResponseArray());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $entry = DatabaseLibraryEntry::findOrFail($id);

        if ($entry->instructor_id && $entry->instructor_id !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You do not have permission to view this database library entry.');
        }

        return response()->json($entry->toResponseArray());
    }

    public function store(StoreDatabaseLibraryRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $data = $request->validated();

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

    public function update(UpdateDatabaseLibraryRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $entry = DatabaseLibraryEntry::findOrFail($id);
        if ($entry->instructor_id && $entry->instructor_id !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You do not have permission to edit this database library entry.');
        }

        $data = $request->validated();

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

    /**
     * Test-run DDL and DML in isolated SQL Server transaction to verify syntax.
     */
    public function testRun(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCanAccess($user);

        $request->validate([
            'createTableSql'    => ['nullable', 'string'],
            'create_table_sql'  => ['nullable', 'string'],
            'insertIntoSql'     => ['nullable', 'string'],
            'insert_into_sql'   => ['nullable', 'string'],
        ]);

        $createSql = $request->input('createTableSql') ?? $request->input('create_table_sql') ?? '';
        $insertSql = $request->input('insertIntoSql') ?? $request->input('insert_into_sql') ?? '';

        if (trim($createSql) === '' && trim($insertSql) === '') {
            return response()->json([
                'status'  => 'Error',
                'message' => 'Please provide SQL statements to test.',
            ], 422);
        }

        // Validate via ProblemService sandbox execution
        $validation = $this->problemService->validateSolution([
            'schema'             => $createSql,
            'seedData'           => $insertSql,
            'reference_solution' => 'SELECT 1 AS test_connection;',
        ], $user);

        return response()->json([
            'status'          => $validation['status'] ?? 'Success',
            'message'         => 'SQL schema and dataset executed successfully without syntax errors.',
            'executionTimeMs' => $validation['executionTimeMs'] ?? 0,
        ]);
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
