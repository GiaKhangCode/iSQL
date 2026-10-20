<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id, 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'initials' => ['sometimes', 'nullable', 'string', 'max:10'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->userService->updateUser($user->id, $data));
    }
}
