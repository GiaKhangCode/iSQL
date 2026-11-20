<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LecturerRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LecturerRequestController extends Controller
{
    public function __construct(private readonly LecturerRequestService $requests)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->requests->list());
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->requests->approve($id, $request->user());

        return response()->json(['message' => 'Approved']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $this->requests->reject($id, $request->user());

        return response()->json(['message' => 'Rejected']);
    }
}
