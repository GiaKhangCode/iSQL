<?php

namespace Tests\Feature;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\ExamBrowserSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProctorFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả POST /api/exam-auth/login và GET /api/exam-auth/status (ExamLoginProctorController::login/status).
 *
 * Đăng nhập qua POST /api/auth/login (cùng logic) đã có ở ExamAdmissionLoginTest; ở đây khóa phần còn lại:
 * endpoint trực tiếp, cookie trình duyệt, đăng nhập trùng, khớp tên tài khoản gần giống, và trạng thái phiên thi.
 */
class ExamAuthEndpointTest extends TestCase
{
    use DatabaseTransactions;
    use ProctorFixtures;
    use UsesDefaultRolePermissions;

    private const PW = 'Tmp-Pass-9z';
    private const BAD = 'Sai tài khoản hoặc mật khẩu ca thi.';
    private const BROWSER_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected User $student;
    protected Assignment $session;
    protected ExamAdmission $admission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();

        $this->student = $this->makeStudent('2152' . random_int(1000, 9999));
        $this->session = $this->makeExamSession(['admission_required' => true]);
        $this->admission = $this->makeAdmission($this->student, $this->session);
    }

    /** @param array<string,string> $cookies cookie gửi kèm (đặt lại mỗi lần để không dính sang request sau) */
    private function examLogin(string $username, string $password = self::PW, array $cookies = [], array $server = [])
    {
        $this->unencryptedCookies = $cookies;
        $this->defaultCookies = [];

        // Request JSON chỉ mang cookie khi bật withCredentials().
        return $this->withCredentials()->withServerVariables($server)->postJson('/api/exam-auth/login', ['username' => $username, 'password' => $password]);
    }

    private function examStatus(?string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/exam-auth/status', $token ? ['Authorization' => "Bearer {$token}"] : []);
    }

    private function cookieOf($response, string $name): ?string
    {
        return $response->getCookie($name, false)?->getValue();
    }

    private function browserCookie(string $browserId, string $admissionId, ?string $signature = null): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode(['b' => $browserId, 'a' => $admissionId])), '+/', '-_'), '=');

        return $encoded . '.' . ($signature ?? hash_hmac('sha256', $encoded, (string) config('app.key', 'isql-secret')));
    }

    private function state(?User $student = null): ?ExamAccessState
    {
        return ExamAccessState::where('session_id', $this->session->id)->where('student_id', ($student ?? $this->student)->id)->first();
    }

    // ══ login ════════════════════════════════════════════════════════════════

    public function test_login_signs_in_and_creates_the_browser_session_and_cookies(): void
    {
        $res = $this->examLogin($this->admission->username, self::PW, [], ['HTTP_USER_AGENT' => 'PX-Agent/1.0', 'REMOTE_ADDR' => '10.1.2.3'])
            ->assertOk()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('locked', false)
            ->assertJsonPath('user.id', $this->student->id)
            ->assertJsonPath('user.examSessionId', $this->session->id)
            ->assertJsonPath('user.exam_session_id', $this->session->id);

        $browser = ExamBrowserSession::where('admission_id', $this->admission->id)->sole();
        $this->assertSame('active', $browser->status);
        $this->assertSame($this->session->id, $browser->session_id);
        $this->assertSame($this->student->id, $browser->student_id);
        $this->assertSame('PX-Agent/1.0', $browser->user_agent);
        $this->assertSame('10.1.2.3', $browser->ip_address);
        $this->assertMatchesRegularExpression('/^[' . self::BROWSER_ALPHABET . ']{6}$/', $browser->browser_code);

        $admission = $this->admission->fresh();
        $this->assertSame('claimed', $admission->status);
        $this->assertNotNull($admission->claimed_at);

        $this->assertNotEmpty($this->cookieOf($res, 'sql_exam_browser'));
        $this->assertNotEmpty($this->cookieOf($res, 'sql_exam_admission'));
    }

    public function test_token_name_encodes_session_browser_admission_and_generation(): void
    {
        $this->admission->update(['generation' => 3]);
        $this->examLogin($this->admission->username)->assertOk();

        $browser = ExamBrowserSession::where('admission_id', $this->admission->id)->sole();
        $name = DB::table('personal_access_tokens')->where('tokenable_id', $this->student->id)->latest('id')->value('name');

        $this->assertSame("exam:{$this->session->id}:{$browser->id}:{$this->admission->id}:3", $name);
    }

    public function test_user_agent_is_truncated_to_500_characters(): void
    {
        $this->examLogin($this->admission->username, self::PW, [], ['HTTP_USER_AGENT' => str_repeat('u', 700)])->assertOk();

        $this->assertSame(500, strlen(ExamBrowserSession::where('admission_id', $this->admission->id)->sole()->user_agent));
    }

    public function test_login_rejects_empty_and_unknown_usernames_and_wrong_passwords(): void
    {
        $this->examLogin('')->assertStatus(401)->assertJsonPath('detail', self::BAD);
        $this->examLogin('   ')->assertStatus(401)->assertJsonPath('detail', self::BAD);
        $this->examLogin('K7_KHONG_TON_TAI')->assertStatus(401)->assertJsonPath('detail', self::BAD);
        $this->examLogin($this->admission->username, 'sai')->assertStatus(401)->assertJsonPath('detail', self::BAD);
        $this->examLogin($this->admission->username, '')->assertStatus(401)->assertJsonPath('detail', self::BAD);

        $this->assertSame(0, ExamBrowserSession::where('admission_id', $this->admission->id)->count());
        $this->assertSame('issued', $this->admission->fresh()->status);
    }

    public function test_exam_login_is_throttled_per_username_so_passwords_cannot_be_guessed(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->examLogin($this->admission->username, 'sai')->assertStatus(401);
        }

        $this->examLogin($this->admission->username)->assertStatus(429)->assertJsonStructure(['message', 'detail']);
        $this->examLogin('K7_TEN_KHAC', 'sai')->assertStatus(401)->assertJsonPath('detail', self::BAD);
    }

    public function test_username_is_trimmed_and_case_insensitive(): void
    {
        $this->examLogin('  ' . strtolower($this->admission->username) . '  ')->assertOk()->assertJsonPath('user.id', $this->student->id);
    }

    public function test_visually_ambiguous_characters_still_match(): void
    {
        $this->admission->update(['username' => 'K7_ZIO1']);

        // I, l, 1 coi như nhau; O, o, 0 coi như nhau.
        $this->examLogin('k7_zl0l')->assertOk()->assertJsonPath('user.id', $this->student->id);
    }

    public function test_ambiguous_match_skips_revoked_admissions_and_prefers_the_newest_active_one(): void
    {
        $other = $this->makeStudent();
        $otherSession = $this->makeExamSession();
        $this->admission->update(['username' => 'K7_ZIO1']);
        $newerRevoked = $this->makeAdmission($other, $otherSession, ['username' => 'K7_ZlO1', 'status' => 'revoked']);
        $newerRevoked->forceFill(['created_at' => now()->addMinute()])->save();

        $this->examLogin('k7_zl0l')->assertOk()->assertJsonPath('user.id', $this->student->id);
    }

    public function test_ambiguous_match_prefers_the_newest_admission(): void
    {
        $other = $this->makeStudent();
        $otherSession = $this->makeExamSession(['admission_required' => true]);
        $this->admission->update(['username' => 'K7_ZIO1']);
        $this->admission->forceFill(['created_at' => now()->subHour()])->save();
        $newer = $this->makeAdmission($other, $otherSession, ['username' => 'K7_ZlO1']);
        $newer->forceFill(['created_at' => now()->addHour()])->save();

        $this->examLogin('k7_zl0l')->assertOk()->assertJsonPath('user.id', $other->id);
    }

    public function test_login_cookies_are_http_only_lax_and_last_eight_hours(): void
    {
        $res = $this->examLogin($this->admission->username)->assertOk();

        foreach (['sql_exam_browser', 'sql_exam_admission'] as $name) {
            $cookie = $res->getCookie($name, false);
            $this->assertTrue($cookie->isHttpOnly(), $name);
            $this->assertSame('lax', $cookie->getSameSite(), $name);
            $this->assertSame('/', $cookie->getPath(), $name);
            $this->assertEqualsWithDelta(now()->addMinutes(480)->timestamp, $cookie->getExpiresTime(), 120, $name);
        }
    }

    public function test_first_login_keeps_the_original_claim_time(): void
    {
        $claimedAt = now()->subDay()->startOfSecond();
        $this->admission->update(['status' => 'claimed', 'claimed_at' => $claimedAt]);

        $this->examLogin($this->admission->username)->assertOk()->assertJsonPath('locked', false);

        $this->assertSame($claimedAt->toISOString(), $this->admission->fresh()->claimed_at->toISOString());
    }

    public function test_exact_match_beats_ambiguous_match(): void
    {
        $other = $this->makeStudent();
        $otherSession = $this->makeExamSession(['admission_required' => true]);
        $this->admission->update(['username' => 'K7_ZIO1']);
        $this->makeAdmission($other, $otherSession, ['username' => 'K7_ZlO1']);

        $this->examLogin('K7_ZlO1')->assertOk()->assertJsonPath('user.id', $other->id);
        $this->examLogin('K7_ZIO1')->assertOk()->assertJsonPath('user.id', $this->student->id);
    }

    public function test_revoked_admission_is_rejected_even_with_the_right_password(): void
    {
        $this->admission->update(['status' => 'revoked']);

        $this->examLogin($this->admission->username)->assertStatus(401)->assertJsonPath('detail', self::BAD);
    }

    public function test_initial_password_works_when_the_hash_was_changed(): void
    {
        $this->admission->update(['hashed_password' => \Illuminate\Support\Facades\Hash::make('doi-roi')]);

        $this->examLogin($this->admission->username, self::PW)->assertOk();
        $this->examLogin($this->admission->username, 'doi-roi')->assertOk();
    }

    public function test_null_initial_password_only_accepts_the_hash(): void
    {
        $this->admission->update(['initial_password' => null]);

        $this->examLogin($this->admission->username, self::PW)->assertOk();
        $this->examLogin($this->admission->username, 'khac')->assertStatus(401);
        // Mật khẩu rỗng không được trùng với "không có mật khẩu khởi tạo".
        $this->examLogin($this->admission->username, '')->assertStatus(401);
    }

    public function test_session_and_account_conditions_are_enforced(): void
    {
        $this->session->update(['published' => false]);
        $this->examLogin($this->admission->username)->assertStatus(403)->assertJsonPath('detail', 'Ca thi chưa được công bố.');
        $this->session->update(['published' => true, 'closes' => now()->subSecond()]);
        $this->examLogin($this->admission->username)->assertStatus(403)->assertJsonPath('detail', 'Ca thi đã kết thúc.');
        $this->session->update(['closes' => null]);
        $this->examLogin($this->admission->username)->assertOk();
    }

    public function test_missing_session_is_reported_as_not_published(): void
    {
        DB::table('assignments')->where('id', $this->session->id)->delete();

        $this->examLogin($this->admission->username)->assertStatus(403)->assertJsonPath('detail', 'Ca thi chưa được công bố.');
    }

    public function test_only_active_student_accounts_can_sign_in(): void
    {
        $this->student->update(['status' => 'Inactive']);
        $this->examLogin($this->admission->username)->assertStatus(403)->assertJsonPath('detail', 'Tài khoản sinh viên không khả dụng.');

        $this->student->update(['status' => 'Active']);
        $staff = $this->makeAccount('instructor');
        $this->admission->update(['student_id' => $staff->id]);
        $this->examLogin($this->admission->username)->assertStatus(403)->assertJsonPath('detail', 'Tài khoản sinh viên không khả dụng.');
    }

    // ══ đăng nhập trùng ══════════════════════════════════════════════════════

    public function test_relogin_with_the_same_browser_cookie_keeps_the_session_unlocked(): void
    {
        $first = $this->examLogin($this->admission->username)->assertOk();
        $cookie = $this->cookieOf($first, 'sql_exam_browser');
        $browser = ExamBrowserSession::where('admission_id', $this->admission->id)->sole();
        $browser->update(['last_seen_at' => now()->subHour()]);

        $this->examLogin($this->admission->username, self::PW, ['sql_exam_browser' => $cookie])
            ->assertOk()->assertJsonPath('locked', false);

        $this->assertSame(1, ExamBrowserSession::where('admission_id', $this->admission->id)->count());
        $this->assertTrue($browser->fresh()->last_seen_at->greaterThan(now()->subMinute()));
        $this->assertFalse((bool) ($this->state()?->locked));
    }

    public function test_second_browser_is_pending_and_locks_the_student(): void
    {
        $this->examLogin($this->admission->username)->assertOk()->assertJsonPath('locked', false);

        $this->examLogin($this->admission->username)->assertOk()->assertJsonPath('locked', true);

        $statuses = ExamBrowserSession::where('admission_id', $this->admission->id)->orderBy('started_at')->pluck('status')->all();
        $this->assertEqualsCanonicalizing(['active', 'pending'], $statuses);
        $state = $this->state();
        $this->assertTrue((bool) $state->locked);
        $this->assertSame('duplicate_login', $state->lock_reason);
        $this->assertNotNull($state->incident_at);
    }

    public function test_pending_browser_is_reused_by_its_own_cookie(): void
    {
        $this->examLogin($this->admission->username)->assertOk();
        $second = $this->examLogin($this->admission->username)->assertOk();
        $pendingCookie = $this->cookieOf($second, 'sql_exam_browser');

        $this->examLogin($this->admission->username, self::PW, ['sql_exam_browser' => $pendingCookie])
            ->assertOk()->assertJsonPath('locked', true);

        $this->assertSame(2, ExamBrowserSession::where('admission_id', $this->admission->id)->count());
    }

    public function test_third_cookieless_login_adds_another_pending_browser(): void
    {
        $this->examLogin($this->admission->username)->assertOk();
        $this->examLogin($this->admission->username)->assertOk();
        $this->examLogin($this->admission->username)->assertOk()->assertJsonPath('locked', true);

        $this->assertSame(3, ExamBrowserSession::where('admission_id', $this->admission->id)->count());
        $this->assertSame(1, ExamBrowserSession::where('admission_id', $this->admission->id)->where('status', 'active')->count());
    }

    public function test_active_browser_stays_active_but_reports_locked_while_the_student_is_locked(): void
    {
        $first = $this->examLogin($this->admission->username)->assertOk();
        $cookie = $this->cookieOf($first, 'sql_exam_browser');
        ExamAccessState::where('session_id', $this->session->id)->where('student_id', $this->student->id)->update(['locked' => true]);

        $this->examLogin($this->admission->username, self::PW, ['sql_exam_browser' => $cookie])
            ->assertOk()->assertJsonPath('locked', true);

        $this->assertSame('active', ExamBrowserSession::where('admission_id', $this->admission->id)->sole()->status);
    }

    public function test_first_login_while_locked_creates_a_pending_browser_and_does_not_claim(): void
    {
        ExamAccessState::create(['session_id' => $this->session->id, 'student_id' => $this->student->id, 'locked' => true, 'lock_reason' => 'x']);

        $this->examLogin($this->admission->username)->assertOk()->assertJsonPath('locked', true);

        $this->assertSame('pending', ExamBrowserSession::where('admission_id', $this->admission->id)->sole()->status);
        $this->assertSame('issued', $this->admission->fresh()->status);
        $this->assertSame('duplicate_login', $this->state()->lock_reason);
    }

    public function test_cookie_of_another_admission_or_with_a_bad_signature_is_ignored(): void
    {
        $other = $this->makeStudent();
        $otherSession = $this->makeExamSession(['admission_required' => true]);
        $otherAdmission = $this->makeAdmission($other, $otherSession);
        $otherCookie = $this->cookieOf($this->examLogin($otherAdmission->username)->assertOk(), 'sql_exam_browser');

        $first = $this->examLogin($this->admission->username)->assertOk();
        $ownBrowser = ExamBrowserSession::where('admission_id', $this->admission->id)->sole();

        // Cookie của tài khoản khác: coi như không có cookie => thành trình duyệt thứ hai.
        $this->examLogin($this->admission->username, self::PW, ['sql_exam_browser' => $otherCookie])->assertOk()->assertJsonPath('locked', true);
        // Chữ ký sai.
        $forged = $this->browserCookie($ownBrowser->id, $this->admission->id, 'chu-ky-gia');
        $this->examLogin($this->admission->username, self::PW, ['sql_exam_browser' => $forged])->assertOk()->assertJsonPath('locked', true);
        // Rác không có dấu chấm.
        $this->examLogin($this->admission->username, self::PW, ['sql_exam_browser' => 'rac'])->assertOk()->assertJsonPath('locked', true);

        $this->assertSame(4, ExamBrowserSession::where('admission_id', $this->admission->id)->count());
        // Đối chứng: cookie hợp lệ của chính nó được nhận ra (không sinh trình duyệt mới). Các lần trùng ở trên đã
        // khóa trạng thái truy cập nên phản hồi vẫn locked.
        $this->examLogin($this->admission->username, self::PW, ['sql_exam_browser' => $this->cookieOf($first, 'sql_exam_browser')])
            ->assertOk()->assertJsonPath('locked', true);
        $this->assertSame(4, ExamBrowserSession::where('admission_id', $this->admission->id)->count());
        $this->assertSame('active', $ownBrowser->fresh()->status);
    }

    // ══ status ═══════════════════════════════════════════════════════════════

    public function test_status_requires_authentication(): void
    {
        $this->examStatus(null)->assertStatus(401);
    }

    public function test_status_of_an_active_exam_login(): void
    {
        $token = $this->examLogin($this->admission->username)->json('access_token');
        $browser = ExamBrowserSession::where('admission_id', $this->admission->id)->sole();

        $this->examStatus($token)
            ->assertOk()
            ->assertJsonPath('sessionId', $this->session->id)
            ->assertJsonPath('title', $this->session->title)
            ->assertJsonPath('locked', false)
            ->assertJsonPath('violationLocked', false)
            ->assertJsonPath('browserCode', $browser->browser_code)
            ->assertJsonPath('opens', $this->session->opens->toISOString())
            ->assertJsonPath('closes', $this->session->closes->toISOString())
            ->assertJsonStructure(['serverTime']);
    }

    public function test_status_marks_the_browser_as_seen_and_creates_the_access_state(): void
    {
        $token = $this->examLogin($this->admission->username)->json('access_token');
        ExamBrowserSession::where('admission_id', $this->admission->id)->update(['last_seen_at' => now()->subDay()]);
        ExamAccessState::where('session_id', $this->session->id)->delete();

        $this->examStatus($token)->assertOk();

        $this->assertTrue(ExamBrowserSession::where('admission_id', $this->admission->id)->sole()->last_seen_at->greaterThan(now()->subMinute()));
        $this->assertNotNull($this->state());
        $this->assertFalse((bool) $this->state()->locked);
    }

    public function test_status_is_locked_for_a_pending_browser_and_unlocked_for_the_active_one(): void
    {
        $active = $this->examLogin($this->admission->username)->json('access_token');
        $pending = $this->examLogin($this->admission->username)->json('access_token');

        $this->examStatus($pending)->assertOk()->assertJsonPath('locked', true)->assertJsonPath('violationLocked', false);
        // Người dùng bị khóa theo trạng thái truy cập (đăng nhập trùng) nên trình duyệt đang hoạt động cũng bị khóa.
        $this->examStatus($active)->assertOk()->assertJsonPath('locked', true);

        ExamAccessState::where('session_id', $this->session->id)->update(['locked' => false]);
        $this->examStatus($active)->assertOk()->assertJsonPath('locked', false);
        $this->examStatus($pending)->assertOk()->assertJsonPath('locked', true);
    }

    public function test_status_reports_violation_lock_only_for_a_submitted_attempt_finished_by_violations(): void
    {
        $token = $this->examLogin($this->admission->username)->json('access_token');
        $attempt = ActivityAttempt::create([
            'assignment_id' => $this->session->id, 'user_id' => $this->student->id, 'status' => 'submitted',
            'drafts' => [], 'violations' => [], 'started_at' => now(), 'finish_reason' => 'violations',
        ]);

        $this->examStatus($token)->assertOk()->assertJsonPath('violationLocked', true)->assertJsonPath('locked', true);

        $attempt->update(['finish_reason' => 'violation_limit']);
        $this->examStatus($token)->assertOk()->assertJsonPath('violationLocked', true);

        $attempt->update(['finish_reason' => 'timeout']);
        $this->examStatus($token)->assertOk()->assertJsonPath('violationLocked', false)->assertJsonPath('locked', false);

        $attempt->update(['finish_reason' => 'violations', 'status' => 'active']);
        $this->examStatus($token)->assertOk()->assertJsonPath('violationLocked', false);
    }

    public function test_status_with_a_regular_login_falls_back_to_the_latest_non_revoked_admission(): void
    {
        $older = $this->makeExamSession(['title' => 'Ca cũ']);
        $oldAdmission = $this->makeAdmission($this->student, $older);
        $oldAdmission->forceFill(['updated_at' => now()->subDay()])->save();
        $revokedSession = $this->makeExamSession(['title' => 'Ca bị thu hồi']);
        $revoked = $this->makeAdmission($this->student, $revokedSession, ['status' => 'revoked']);
        $revoked->forceFill(['updated_at' => now()->addDay()])->save();

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/auth/login', ['email' => $this->student->email, 'password' => 'real-pass-123'])->json('access_token');

        // Bản ghi cập nhật gần nhất không bị thu hồi là ca của test (updated_at = bây giờ).
        $this->examStatus($token)->assertOk()->assertJsonPath('sessionId', $this->session->id);
        $this->assertNotSame($revokedSession->id, $this->examStatus($token)->json('sessionId'));
    }

    public function test_status_without_any_admission_is_forbidden(): void
    {
        $stranger = $this->makeStudent();
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/auth/login', ['email' => $stranger->email, 'password' => 'real-pass-123'])->json('access_token');

        $this->examStatus($token)->assertStatus(403)->assertJsonPath('detail', 'Chỉ dùng cho tài khoản ca thi.');

        $this->makeAdmission($stranger, $this->makeExamSession(), ['status' => 'revoked']);
        $this->examStatus($token)->assertStatus(403);
    }

    public function test_status_without_exam_token_uses_the_latest_browser_of_the_session(): void
    {
        $this->examLogin($this->admission->username)->assertOk();
        $this->examLogin($this->admission->username)->assertOk();
        // Trình duyệt khởi tạo sau cùng là trình duyệt chờ duyệt (pending).
        $latest = ExamBrowserSession::where('admission_id', $this->admission->id)->where('status', 'pending')->sole();
        $latest->update(['started_at' => now()->addMinute()]);

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/auth/login', ['email' => $this->student->email, 'password' => 'real-pass-123'])->json('access_token');

        $this->examStatus($token)->assertOk()->assertJsonPath('browserCode', $latest->browser_code)->assertJsonPath('locked', true);
    }

    public function test_status_without_any_browser_session_is_locked_with_an_empty_code(): void
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/auth/login', ['email' => $this->student->email, 'password' => 'real-pass-123'])->json('access_token');

        $this->examStatus($token)->assertOk()->assertJsonPath('locked', true)->assertJsonPath('browserCode', '');
    }

    public function test_status_for_a_deleted_session_is_404(): void
    {
        $token = $this->examLogin($this->admission->username)->json('access_token');
        DB::table('assignments')->where('id', $this->session->id)->delete();

        $this->examStatus($token)->assertStatus(404);
    }
}
