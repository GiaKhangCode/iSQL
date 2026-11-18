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

    public function index(): JsonResponse
    {
        return response()->json($this->overviewService->getCounts());
    }
}
