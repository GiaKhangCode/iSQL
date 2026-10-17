<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Services\ClassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    protected ClassService $classService;

    public function __construct(ClassService $classService)
    {
        $this->classService = $classService;
    }

    public function index(Request $request): JsonResponse
    {
        $classes = $this->classService->getInstructorClasses($request->user()->id);
        return response()->json($classes);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'course' => 'required|string|max:255',
            'term'   => 'required|string|max:100',
        ]);

        $class = $this->classService->createClass(
            $request->only(['course', 'term', 'start_date', 'end_date']),
            $request->user()->id
        );

        return response()->json($class, 201);
    }
}
