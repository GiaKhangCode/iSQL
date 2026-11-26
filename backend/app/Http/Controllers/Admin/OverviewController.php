<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function __construct(private readonly AdminOverviewService $overviewService)
    {
    }

    public function health(): JsonResponse
    {
        return response()->json($this->overviewService->health());
    }

    public function attention(Request $request): JsonResponse
    {
        return response()->json($this->overviewService->attention($request->user()));
    }

    public function exams(): JsonResponse
    {
        return response()->json($this->overviewService->upcomingExams());
    }

    public function activities(): JsonResponse
    {
        return response()->json($this->overviewService->recentActivities());
    }
}
