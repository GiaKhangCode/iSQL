<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminOverviewService;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __construct(private readonly AdminOverviewService $overviewService)
    {
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->overviewService->stats());
    }

    public function activities(): JsonResponse
    {
        return response()->json($this->overviewService->recentActivities());
    }

    public function errors(): JsonResponse
    {
        return response()->json($this->overviewService->recentGradingErrors());
    }
}
