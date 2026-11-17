<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        abort_unless($request->user()->role === User::ROLE_STUDENT, 403, 'Only students can view their enrolled classes here.');

        return response()->json($this->classService->getStudentClasses($request->user()->id));
    }
}
