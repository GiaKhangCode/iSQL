<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterLecturerRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exception;

class AuthController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $user = $this->userService->registerStudent($validatedData);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'user'         => $this->formatAuthUser($user),
        ], 201);
    }

    public function registerLecturer(RegisterLecturerRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $user = $this->userService->registerLecturer($validatedData);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'user'         => $this->formatAuthUser($user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = $this->userService->authenticate($request->email, $request->password);
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'bearer',
                'user'         => $this->formatAuthUser($user),
            ], 200);
        } catch (Exception $e) {
            \Log::error('Login Exception: ' . $e->getMessage(), ['exception' => $e]);
            $code = (is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 401;
            return response()->json(['detail' => $e->getMessage()], $code);
        }
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->formatAuthUser($request->user()));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email'      => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id, 'id')],
            'name'       => ['sometimes', 'required', 'string', 'max:255'],
            'initials'   => ['sometimes', 'nullable', 'string', 'max:10'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $updated = $this->userService->updateUser($user->id, $data);
        return response()->json($this->formatAuthUser($updated));
    }

    protected function formatAuthUser(\App\Models\User $user): array
    {
        return $user->toAuthArray();
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $otp = $this->userService->sendPasswordResetOtp($request->email);
            return response()->json([
                'message'   => 'Mã xác thực đã được gửi đến email của bạn.',
                'debug_otp' => app()->isLocal() ? $otp : null,
            ], 200);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Lỗi khi gửi email OTP: ' . $e->getMessage());
            return response()->json([
                'detail' => 'Không thể gửi email OTP: ' . $e->getMessage() . '. Vui lòng kiểm tra cấu hình SMTP trong file .env.',
            ], 500);
        }
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $this->userService->verifyOtp($request->email, $request->otp);
            return response()->json(['message' => 'Xác thực mã OTP thành công.'], 200);
        } catch (Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->userService->resetPassword($request->email, $request->otp, $request->password);
            return response()->json(['message' => 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập lại.'], 200);
        } catch (Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }
}
