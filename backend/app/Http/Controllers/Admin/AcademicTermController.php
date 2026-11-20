<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AcademicTermRequest;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class AcademicTermController extends Controller
{
    public function __construct(private readonly CatalogService $catalogService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->catalogService->listTerms());
    }

    public function store(AcademicTermRequest $request): JsonResponse
    {
        return response()->json($this->catalogService->createTerm($request->validated()), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->catalogService->findTerm($id));
    }

    public function update(AcademicTermRequest $request, string $id): JsonResponse
    {
        return response()->json($this->catalogService->updateTerm($id, $request->validated()));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->catalogService->deleteTerm($id);

        return response()->json(['message' => 'Academic term deleted successfully']);
    }
}
