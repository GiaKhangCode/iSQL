<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\ClassModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'total_users'       => User::count(),
            'total_students'    => User::where('role', 'student')->count(),
            'total_instructors' => User::where('role', 'instructor')->count(),
            'total_problems'    => Problem::count(),
            'total_submissions' => Submission::count(),
            'total_classes'     => ClassModel::count(),
        ]);
    }
}
