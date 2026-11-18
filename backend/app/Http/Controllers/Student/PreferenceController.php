<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\PreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    public function __construct(private readonly PreferenceService $preferenceService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->preferenceService->getPreferences($request->user()->id)
        );
    }

    public function toggleFavorite(Request $request, string $id): JsonResponse
    {
        return response()->json(
            $this->preferenceService->toggleFavorite($request->user()->id, $id)
        );
    }
}
