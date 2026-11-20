<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\DashboardRequest;
use App\Services\StudentDashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly StudentDashboardService $dashboardService)
    {
    }

    public function index(DashboardRequest $request): JsonResponse
    {
        return response()->json(
            $this->dashboardService->getDashboard($request->user(), $request->tzOffset())
        );
    }
}
