<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\StudentDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly StudentDashboardService $dashboardService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->dashboardService->getDashboard($request->user())
        );
    }
}
