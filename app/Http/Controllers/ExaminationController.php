<?php

namespace App\Http\Controllers;

use App\Services\ExaminationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExaminationController extends Controller
{
    public function __construct(
        protected readonly ExaminationService $examinationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->examinationService->listExaminations($request->user()));
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->examinationService->previewExamination($request->all(), $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->examinationService->createExamination($request->all(), $request->user()));
    }

    public function show(Request $request, string $examinationId): JsonResponse
    {
        return response()->json($this->examinationService->getExamination($examinationId, $request->user()));
    }

    public function update(Request $request, string $examinationId): JsonResponse
    {
        return response()->json($this->examinationService->updateExamination($examinationId, $request->all(), $request->user()));
    }

    public function studentAccounts(Request $request, string $examinationId, string $sessionId): JsonResponse
    {
        return response()->json(
            $this->examinationService->getSessionStudentAccounts($examinationId, $sessionId, $request->user())
        );
    }

    public function accountsZip(Request $request, string $examinationId): BinaryFileResponse
    {
        [$tmpFile, $filename] = $this->examinationService->buildAccountsZip($examinationId, $request->user());

        return response()->download($tmpFile, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}

