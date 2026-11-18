<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseRequest;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class CourseController extends Controller
{
    public function __construct(private readonly CatalogService $catalogService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->catalogService->listCourses());
    }

    public function store(CourseRequest $request): JsonResponse
    {
        return response()->json($this->catalogService->createCourse($request->validated()), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->catalogService->findCourse($id));
    }

    public function update(CourseRequest $request, string $id): JsonResponse
    {
        return response()->json($this->catalogService->updateCourse($id, $request->validated()));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->catalogService->deleteCourse($id);

        return response()->json(['message' => 'Course deleted successfully']);
    }
}
