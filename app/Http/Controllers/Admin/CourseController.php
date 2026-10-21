<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Course::withCount('classes')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:courses,code'],
            'name' => ['required', 'string', 'max:255', 'unique:courses,name'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['Active', 'Archived'])],
        ]);

        return response()->json(Course::create($data), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Course::withCount('classes')->findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $course = Course::findOrFail($id);
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('courses', 'code')->ignore($id, 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('courses', 'name')->ignore($id, 'id')],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['Active', 'Archived'])],
        ]);

        $course->update($data);

        return response()->json($course->refresh());
    }

    public function destroy(string $id): JsonResponse
    {
        Course::findOrFail($id)->delete();

        return response()->json(['message' => 'Course deleted successfully']);
    }
}
