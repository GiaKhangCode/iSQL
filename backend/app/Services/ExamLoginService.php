<?php

namespace App\Services;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\ExamBrowserSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Đăng nhập bằng tài khoản ca thi (ExamAdmission) và trạng thái phiên thi của sinh viên.
 *
 * Tách từ ExamLoginProctorController. Mỗi trình duyệt đăng nhập là một ExamBrowserSession được nhận ra
 * qua cookie có chữ ký; trình duyệt thứ hai của cùng tài khoản bị "pending" và khóa sinh viên cho tới khi
 * giám thị duyệt (ProctorRoomService::approveBrowser).
 */
class ExamLoginService
{
    public const BROWSER_COOKIE = 'sql_exam_browser';
    private const BROWSER_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const COOKIE_MINUTES = 480;
    private const BAD_CREDENTIALS = 'Sai tài khoản hoặc mật khẩu ca thi.';

    public function __construct(protected readonly ExamAdmissionService $examAdmissionService)
    {
    }

    /** Chữ thường, I/l/1 coi như "l", O/o/0 coi như "0": để gõ nhầm các ký tự dễ lẫn vẫn vào được. */
    public static function normalizeAmbiguous(string $str): string
    {
        $s = strtolower(trim($str));
        $s = str_replace(['1', 'i'], 'l', $s);
        $s = str_replace('o', '0', $s);
        return $s;
    }

    /** Khớp chính xác, rồi không phân biệt hoa thường, rồi theo ký tự dễ lẫn (bỏ qua tài khoản đã thu hồi). */
    public function findAdmission(string $rawInput): ?ExamAdmission
    {
        $input = trim($rawInput);
        if ($input === '') {
            return null;
        }

        $row = ExamAdmission::where('username', $input)->first();
        if ($row) {
            return $row;
        }

        $row = ExamAdmission::whereRaw('LOWER(username) = ?', [strtolower($input)])->first();
        if ($row) {
            return $row;
        }

        $normalized = self::normalizeAmbiguous($input);
        $matched = null;
        ExamAdmission::where('status', '!=', 'revoked')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->chunk(500, function ($candidates) use ($normalized, &$matched) {
                foreach ($candidates as $cand) {
                    if (self::normalizeAmbiguous($cand->username) === $normalized) {
                        $matched = $cand;
                        return false;
                    }
                }
                return true;
            });

        return $matched;
    }

    /** POST /exam-auth/login: tìm tài khoản theo tên rồi đăng nhập. */
    public function login(Request $request, string $username, string $password): array
    {
        $row = $this->findAdmission(trim($username));
        if (!$row) {
            $this->spendHashTime($password);
            abort(401, self::BAD_CREDENTIALS);
        }

        return $this->loginWithAdmission($request, $row, $password);
    }

    /**
     * Tốn một lần băm mật khẩu khi không có tài khoản để so, để thời gian phản hồi của tên không tồn tại
     * không nhanh hơn tên tồn tại mà sai mật khẩu (không đoán được tên hợp lệ qua độ trễ).
     */
    public function spendHashTime(string $password): void
    {
        Hash::make($password);
    }

    /**
     * Kiểm tra mật khẩu, tình trạng sinh viên và ca thi, gắn trình duyệt và cấp token.
     *
     * @return array{body: array, cookies: array<string, string>} nội dung phản hồi và các cookie cần đặt
     */
    public function loginWithAdmission(Request $request, ExamAdmission $row, string $password): array
    {
        // Luôn kiểm mật khẩu trước, kể cả tài khoản đã thu hồi: cùng lỗi, cùng thời gian với sai mật khẩu.
        $passwordOk = Hash::check($password, $row->hashed_password)
            || ($row->initial_password !== null && hash_equals((string) $row->initial_password, $password));

        if ($row->status === 'revoked' || !$passwordOk) {
            abort(401, self::BAD_CREDENTIALS);
        }

        $student = User::where('id', $row->student_id)
            ->where('account_type', User::ACCOUNT_STUDENT)
            ->first();

        if (!$student || $student->status !== 'Active') {
            abort(403, 'Tài khoản sinh viên không khả dụng.');
        }

        $activity = Assignment::find($row->session_id);
        if (!$activity || !$activity->published) {
            abort(403, 'Ca thi chưa được công bố.');
        }

        $now = now();
        if ($activity->closes && $now->greaterThanOrEqualTo($activity->closes)) {
            abort(403, 'Ca thi đã kết thúc.');
        }

        $state = $this->accessState($activity->id, $student->id);
        $currentBrowser = $this->readBrowserCookie($request->cookie(self::BROWSER_COOKIE), $row->id);

        $activeBrowsers = ExamBrowserSession::where('admission_id', $row->id)
            ->where('status', 'active')
            ->get();

        if ($currentBrowser && $currentBrowser->status === 'active') {
            $currentBrowser->last_seen_at = $now;
            $currentBrowser->save();
            $browser = $currentBrowser;
            $locked = (bool) $state->locked;
        } elseif ($activeBrowsers->isEmpty() && !$state->locked) {
            $browser = $this->createBrowserSession($request, $row, 'active', $now);
            $row->status = 'claimed';
            if (!$row->claimed_at) {
                $row->claimed_at = $now;
            }
            $row->save();
            $locked = false;
        } else {
            if ($currentBrowser && $currentBrowser->status === 'pending') {
                $currentBrowser->last_seen_at = $now;
                $currentBrowser->save();
                $browser = $currentBrowser;
            } else {
                $browser = $this->createBrowserSession($request, $row, 'pending', $now);
            }
            $state->locked = true;
            $state->lock_reason = 'duplicate_login';
            $state->incident_at = $now;
            $state->save();
            $locked = true;
        }

        $tokenName = "exam:{$activity->id}:{$browser->id}:{$row->id}:{$row->generation}";
        $accessToken = $student->createToken($tokenName)->plainTextToken;

        $userPayload = array_merge($student->toAuthArray(), [
            'examSessionId'   => (string) $activity->id,
            'exam_session_id' => (string) $activity->id,
        ]);

        return [
            'body' => [
                'access_token' => $accessToken,
                'token_type'   => 'bearer',
                'user'         => $userPayload,
                'locked'       => $locked,
            ],
            'cookies' => [
                ExamAdmissionService::COOKIE_NAME => $this->examAdmissionService->buildAdmissionCookieValue($student, $activity, $row),
                self::BROWSER_COOKIE              => $this->buildBrowserCookieValue($browser->id, $row->id),
            ],
        ];
    }

    /** Dựng phản hồi JSON từ kết quả đăng nhập, kèm các cookie (HttpOnly, SameSite=Lax, 8 giờ). */
    public function toResponse(array $login): JsonResponse
    {
        $response = response()->json($login['body']);
        foreach ($login['cookies'] as $name => $value) {
            $response->withCookie(cookie($name, $value, self::COOKIE_MINUTES, '/', null, false, true, false, 'Lax'));
        }

        return $response;
    }

    /**
     * GET /exam-auth/status: ca thi, mã trình duyệt và có đang bị khóa không (trùng đăng nhập hoặc vi phạm).
     * Token ca thi mang sẵn mã ca và trình duyệt; token thường thì lấy tài khoản ca thi mới nhất chưa thu hồi.
     */
    public function status(User $user): array
    {
        $sessionId = null;
        $browserId = null;

        $token = $user->currentAccessToken();
        if ($token && is_string($token->name) && str_starts_with($token->name, 'exam:')) {
            $parts = explode(':', $token->name);
            $sessionId = $parts[1] ?? null;
            $browserId = $parts[2] ?? null;
        }

        if (!$sessionId) {
            $latestAdm = ExamAdmission::where('student_id', $user->id)
                ->where('status', '!=', 'revoked')
                ->orderByDesc('updated_at')
                ->first();
            $sessionId = $latestAdm?->session_id;
        }

        abort_unless($sessionId !== null, 403, 'Chỉ dùng cho tài khoản ca thi.');

        $activity = Assignment::findOrFail($sessionId);
        $state = $this->accessState($activity->id, $user->id);

        $browser = $browserId
            ? ExamBrowserSession::find($browserId)
            : ExamBrowserSession::where('session_id', $activity->id)
                ->where('student_id', $user->id)
                ->orderByDesc('started_at')
                ->first();

        if ($browser) {
            $browser->last_seen_at = now();
            $browser->save();
        }

        $attempt = ActivityAttempt::where('assignment_id', $activity->id)
            ->where('user_id', $user->id)
            ->first();

        $violationLocked = $attempt && $attempt->status === 'submitted' && in_array($attempt->finish_reason, ['violation_limit', 'violations'], true);
        $browserLocked = !$browser || $browser->status !== 'active' || (bool) $state->locked || $violationLocked;

        return [
            'sessionId'       => (string) $activity->id,
            'title'           => (string) $activity->title,
            'locked'          => $browserLocked,
            'violationLocked' => $violationLocked,
            'browserCode'     => $browser ? (string) $browser->browser_code : '',
            'opens'           => $activity->opens ? $activity->opens->toISOString() : null,
            'closes'          => $activity->closes ? $activity->closes->toISOString() : null,
            'serverTime'      => now()->toISOString(),
        ];
    }

    /** Trạng thái truy cập (khóa/mở) của một sinh viên trong một ca; tạo dòng mới (chưa khóa) nếu chưa có. */
    public function accessState(string $sessionId, string $studentId): ExamAccessState
    {
        $state = ExamAccessState::where('session_id', $sessionId)
            ->where('student_id', $studentId)
            ->first();

        if (!$state) {
            $state = ExamAccessState::create([
                'session_id' => $sessionId,
                'student_id' => $studentId,
                'locked'     => false,
            ]);
        }

        return $state;
    }

    protected function createBrowserSession(Request $request, ExamAdmission $row, string $status, $now): ExamBrowserSession
    {
        return ExamBrowserSession::create([
            'id'           => (string) Str::uuid(),
            'admission_id' => $row->id,
            'session_id'   => $row->session_id,
            'student_id'   => $row->student_id,
            'browser_code' => $this->uniqueBrowserCode(),
            'status'       => $status,
            'user_agent'   => substr((string) $request->userAgent(), 0, 500),
            'ip_address'   => $request->ip(),
            'started_at'   => $now,
            'last_seen_at' => $now,
        ]);
    }

    protected function uniqueBrowserCode(): string
    {
        $max = strlen(self::BROWSER_ALPHABET) - 1;
        for ($try = 0; $try < 30; $try++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::BROWSER_ALPHABET[random_int(0, $max)];
            }
            if (!ExamBrowserSession::where('browser_code', $code)->exists()) {
                return $code;
            }
        }
        return strtoupper(substr(Str::uuid()->toString(), 0, 6));
    }

    protected function buildBrowserCookieValue(string $browserId, string $admissionId): string
    {
        $payload = json_encode(['b' => $browserId, 'a' => $admissionId]);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $encoded, (string) config('app.key', 'isql-secret'));
        return "{$encoded}.{$sig}";
    }

    /** Đọc cookie trình duyệt: phải đúng chữ ký và đúng tài khoản ca thi đang đăng nhập. */
    protected function readBrowserCookie(?string $token, string $admissionId): ?ExamBrowserSession
    {
        if (!is_string($token) || !str_contains($token, '.')) {
            return null;
        }
        [$encoded, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $encoded, (string) config('app.key', 'isql-secret'));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $pad = strlen($encoded) % 4;
        $b64 = $encoded . ($pad ? str_repeat('=', 4 - $pad) : '');
        $data = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);
        if (!is_array($data) || ($data['a'] ?? null) !== $admissionId || empty($data['b'])) {
            return null;
        }
        return ExamBrowserSession::find($data['b']);
    }
}
