<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\RunQueryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalDbController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'database' => 'connected',
            'schema'   => 'user_' . substr(md5($request->user()->id), 0, 10),
        ]);
    }

    public function runQuery(RunQueryRequest $request): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'columns' => [],
            'rows'    => [],
        ]);
    }
}
