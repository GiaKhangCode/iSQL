<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SandboxCleanupRequest;
use App\Services\SqlServer\SandboxCleanupService;
use Illuminate\Http\JsonResponse;

/** Dọn schema bài làm trên SQL Server (quyền sandbox.cleanup). Logic ở SandboxCleanupService. */
class SandboxController extends Controller
{
    public function __construct(private readonly SandboxCleanupService $cleanup)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->cleanup->summary());
    }

    public function cleanup(SandboxCleanupRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json($data['target'] === 'class'
            ? $this->cleanup->cleanClass($request->user(), $data['classId'], $data['confirm'])
            : $this->cleanup->cleanOrphans($request->user(), $data['confirm']));
    }
}
