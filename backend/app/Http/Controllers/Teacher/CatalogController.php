<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalogService)
    {
    }

    public function courses(): JsonResponse
    {
        return response()->json($this->catalogService->listActiveCourses());
    }

    public function terms(): JsonResponse
    {
        return response()->json($this->catalogService->listActiveTerms());
    }
}
