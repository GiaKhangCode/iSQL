<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RegisterLecturerRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class AuthController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = $this->userService->authenticate($request->email, $request->password);
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'bearer',
                'user'         => $user,
            ]);
        } catch (Exception $e) {
            $status = $e->getCode() ?: 401;
            return response()->json(['detail' => $e->getMessage()], $status);
        }
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->userService->registerStudent($request->validated());
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'user'         => $user,
        ], 201);
    }

    public function registerLecturer(RegisterLecturerRequest $request): JsonResponse
    {
        $user = $this->userService->registerLecturer($request->validated());

        return response()->json([
            'message' => 'Registration submitted. Please wait for administrator approval.',
            'user'    => $user,
        ], 201);
    }

    public function demoLogin(Request $request): JsonResponse
    {
        $role = $request->input('role', 'student');
        $user = User::where('role', $role)->first();

        if (!$user) {
            return response()->json(['detail' => 'Demo user not found'], 404);
        }

        $token = $user->createToken('demo-token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'user'         => $user,
        ]);
    }

    public function tokenLogin(Request $request): JsonResponse
    {
        return $this->login(app(LoginRequest::class));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
