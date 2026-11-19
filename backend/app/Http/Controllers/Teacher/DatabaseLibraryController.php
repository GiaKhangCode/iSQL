<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreDatabaseLibraryRequest;
use App\Http\Requests\Teacher\TestRunDatabaseLibraryRequest;
use App\Http\Requests\Teacher\UpdateDatabaseLibraryRequest;
use App\Services\DatabaseLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DatabaseLibraryController extends Controller
{
    public function __construct(
        protected DatabaseLibraryService $library
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'scope'  => $request->query('scope', 'all'),
            'search' => $request->query('search', ''),
        ];
        foreach (['page', 'per_page'] as $key) {
            if ($request->has($key)) {
                $filters[$key] = $request->query($key);
            }
        }

        return response()->json($this->library->list($request->user(), $filters));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->library->show($request->user(), $id));
    }

    public function store(StoreDatabaseLibraryRequest $request): JsonResponse
    {
        return response()->json($this->library->create($request->user(), $request->all()), 201);
    }

    public function update(UpdateDatabaseLibraryRequest $request, string $id): JsonResponse
    {
        return response()->json($this->library->update($request->user(), $id, $request->all()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->library->delete($request->user(), $id);

        return response()->json(['ok' => true]);
    }

    /** Chạy thử DDL + DML trong sandbox để kiểm tra cú pháp. */
    public function testRun(TestRunDatabaseLibraryRequest $request): JsonResponse
    {
        [$status, $body] = $this->library->testRun($request->user(), $request->all());

        return response()->json($body, $status);
    }
}
