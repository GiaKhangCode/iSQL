<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ClassService;
use Illuminate\Http\JsonResponse;

class ClassController extends Controller
{
    protected ClassService $classService;

    public function __construct(ClassService $classService)
    {
        $this->classService = $classService;
    }

    public function index(): JsonResponse
    {
        $classes = $this->classService->getAllClasses();
        return response()->json($classes);
    }
}
