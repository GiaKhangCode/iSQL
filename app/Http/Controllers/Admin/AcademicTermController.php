<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicTermController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(AcademicTerm::withCount('classes')->orderByDesc('start_date')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:academic_terms,name'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in(['Active', 'Archived'])],
        ]);

        return response()->json(AcademicTerm::create($data), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(AcademicTerm::withCount('classes')->findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('academic_terms', 'name')->ignore($id, 'id')],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in(['Active', 'Archived'])],
        ]);

        $term->update($data);

        return response()->json($term->refresh());
    }

    public function destroy(string $id): JsonResponse
    {
        AcademicTerm::findOrFail($id)->delete();

        return response()->json(['message' => 'Academic term deleted successfully']);
    }
}
