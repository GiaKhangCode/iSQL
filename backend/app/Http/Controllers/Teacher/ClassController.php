<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Services\ClassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    public function __construct(private readonly ClassService $classService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $classes = $this->classService->visibleClasses($request->user());

        $formatted = $classes->map(fn ($class) => $this->classService->formatClassResponse($class));

        return response()->json($formatted);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $class = $this->classService->visibleClass($request->user(), $id);

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course' => ['required_without:course_id', 'string', 'max:255'],
            'course_id' => ['required_without:course', 'nullable', 'string', 'exists:courses,id'],
            'term' => ['required_without:term_id', 'string', 'max:100'],
            'term_id' => ['required_without:term', 'nullable', 'string', 'exists:academic_terms,id'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'in:Active,Archived'],
        ]);

        $class = $this->classService->createManagedClass($data);

        return response()->json($this->classService->formatClassResponse($class), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'course' => ['sometimes', 'required', 'string', 'max:255'],
            'course_id' => ['sometimes', 'nullable', 'string', 'exists:courses,id'],
            'term' => ['sometimes', 'required', 'string', 'max:100'],
            'term_id' => ['sometimes', 'nullable', 'string', 'exists:academic_terms,id'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'in:Active,Archived'],
        ]);

        $class = $this->classService->updateClass($id, $data);

        return response()->json($this->classService->formatClassResponse($class));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->classService->deleteClass($id);

        return response()->json(['message' => 'Class deleted successfully']);
    }

    public function assignInstructor(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'instructor_id'   => ['sometimes', 'nullable', 'string'],
            'lecturer_id'     => ['sometimes', 'nullable', 'string'],
            'lecturerId'      => ['sometimes', 'nullable', 'string'],
        ]);

        $instructorId = $data['instructor_id'] ?? $data['lecturer_id'] ?? $data['lecturerId'] ?? null;

        if ($instructorId === null) {
            abort(422, 'Cần cung cấp instructor_id hoặc lecturer_id.');
        }

        $class = $this->classService->assignInstructor($id, $instructorId);

        return response()->json($this->classService->formatClassResponse($class));
    }
}
