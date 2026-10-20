<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Course;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function courses(): JsonResponse
    {
        return response()->json(Course::where('status', 'Active')->orderBy('name')->get());
    }

    public function terms(): JsonResponse
    {
        return response()->json(AcademicTerm::where('status', 'Active')->orderByDesc('start_date')->get());
    }
}
