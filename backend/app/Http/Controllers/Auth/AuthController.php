<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterLecturerRequest;
use App\Services\ExamAdmissionService;
use App\Services\ExamLoginService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exception;

class AuthController extends Controller
{
    protected UserService $userService;

    protected ExamAdmissionService $examAdmissionService;

    protected ExamLoginService $examLoginService;

    public function __construct(UserService $userService, ExamAdmissionService $examAdmissionService, ExamLoginService $examLoginService)
    {
        $this->userService = $userService;
        $this->examAdmissionService = $examAdmissionService;
        $this->examLoginService = $examLoginService;
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
        $accountInput = trim((string) ($request->email ?? $request->input('email', '')));
        $password = (string) $request->input('password', '');

        // 1. First try standard user authentication
        try {
            $user = $this->userService->authenticate($accountInput, $password);
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'bearer',
                'user'         => $this->formatAuthUser($user),
            ], 200);
        } catch (Exception $e) {
            // If user account is specifically pending or disabled, return 403 immediately
            if ($e->getCode() === 403 || str_contains($e->getMessage(), 'chờ phê duyệt') || str_contains($e->getMessage(), 'bị vô hiệu hóa')) {
                return response()->json(['detail' => $e->getMessage()], 403);
            }
            // Otherwise, fall through to check temporary exam admission
        }

        // 2. Check if this is a temporary exam credential (ExamAdmission)
        // Case A: Temporary exam username (exact, case-insensitive, or visually normalized)
        $admission = $this->examLoginService->findAdmission($accountInput);
        if ($admission) {
            try {
                return $this->examLoginService->toResponse(
                    $this->examLoginService->loginWithAdmission($request, $admission, $password)
                );
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
            } catch (\Throwable $e) {
                \Log::error('Exam Admission Login Exception: ' . $e->getMessage(), ['exception' => $e]);
                return response()->json(['detail' => $e->getMessage() ?: 'Đăng nhập ca thi thất bại.'], 400);
            }
        }

        // Case B: Student entered their school email / MSSV + exam temporary password
        $candidateAdmission = $this->examAdmissionService->findAdmissionByStudentCredentials($accountInput, $password);
        if ($candidateAdmission) {
            try {
                return $this->examLoginService->toResponse(
                    $this->examLoginService->loginWithAdmission($request, $candidateAdmission, $password)
                );
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
            } catch (\Throwable $e) {
                \Log::error('Exam Admission Login Exception: ' . $e->getMessage(), ['exception' => $e]);
                return response()->json(['detail' => $e->getMessage() ?: 'Đăng nhập ca thi thất bại.'], 400);
            }
        }

        return response()->json(['detail' => 'Email hoặc mật khẩu không chính xác.'], 401);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = $this->formatAuthUser($user);
        $token = $user?->currentAccessToken();
        if ($token && is_string($token->name) && str_starts_with($token->name, 'exam:')) {
            $parts = explode(':', $token->name);
            if (!empty($parts[1])) {
                $payload['examSessionId'] = $parts[1];
                $payload['exam_session_id'] = $parts[1];
            }
        }
        return response()->json($payload);
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
