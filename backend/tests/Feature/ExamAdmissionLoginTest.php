<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\ExamAdmissionService;
use App\Services\ExamLoginService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Test đặc tả cho POST /api/auth/login khi dùng tài khoản/mật khẩu TẠM của ca thi
 * (Auth\AuthController::login gọi sang ExamLoginService).
 *
 * Có hai cách vào ca thi:
 *   A. Gõ tên tài khoản ca thi (K7_...) + mật khẩu tạm.
 *   B. Gõ email hoặc MSSV của sinh viên + mật khẩu tạm.
 * Đăng nhập thường (email + mật khẩu thật) được ưu tiên và đã có test ở AuthTest.
 *
 * Khóa hành vi hiện tại trước khi tách logic khỏi controller (lộ trình 1.5).
 */
class ExamAdmissionLoginTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private const TEMP_PASSWORD = 'Tmp-Pass-9z';
    private const REAL_PASSWORD = 'real-pass-123';

    protected User $instructor;
    protected User $student;
    protected string $studentCode;
    protected Assignment $session;
    protected ExamAdmission $admission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();

        $this->instructor = $this->makeUser('instructor', 'Active', 'teacher');
        $this->student = $this->makeUser('student', 'Active', 'student');

        $this->studentCode = '2152' . random_int(1000, 9999);
        StudentIdentity::create(['user_id' => $this->student->id, 'student_code' => $this->studentCode]);

        $this->session = $this->makeSession();
        $this->admission = $this->makeAdmission($this->student, $this->session);
    }

    private function makeUser(string $role, string $status, string $tag): User
    {
        return User::create([
            'name'            => "ExamLogin {$tag}",
            'email'           => "examlogin_{$tag}_" . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make(self::REAL_PASSWORD),
            ...AccountFixture::for($role),
            'status'          => $status,
            'initials'        => 'EL',
        ]);
    }

    private function makeSession(array $overrides = []): Assignment
    {
        return Assignment::create(array_merge([
            'title'              => 'Ca thi thử ' . uniqid(),
            'instructor_id'      => $this->instructor->id,
            'published'          => true,
            'exam_mode'          => true,
            'admission_required' => true,
            'opens'              => now()->subHour(),
            'closes'             => now()->addHours(2),
        ], $overrides));
    }

    private function makeAdmission(User $student, Assignment $session, array $overrides = []): ExamAdmission
    {
        return ExamAdmission::create(array_merge([
            'session_id'       => $session->id,
            'student_id'       => $student->id,
            'username'         => 'K7_' . strtoupper(Str::random(12)),
            'hashed_password'  => Hash::make(self::TEMP_PASSWORD),
            'initial_password' => self::TEMP_PASSWORD,
            'status'           => 'issued',
            'generation'       => 1,
        ], $overrides));
    }

    private function login(string $account, string $password)
    {
        return $this->postJson('/api/auth/login', ['email' => $account, 'password' => $password]);
    }

    // ══ A. Tên tài khoản ca thi + mật khẩu tạm ═══════════════════════════════

    public function test_exam_username_with_correct_password_signs_the_student_into_the_session(): void
    {
        $res = $this->login($this->admission->username, self::TEMP_PASSWORD);

        $res->assertOk()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('user.id', $this->student->id)
            ->assertJsonPath('user.examSessionId', $this->session->id)
            ->assertJsonPath('user.exam_session_id', $this->session->id)
            ->assertJsonPath('locked', false);
        $this->assertNotEmpty($res->json('access_token'));

        // Tài khoản ca thi được đánh dấu đã sử dụng.
        $this->admission->refresh();
        $this->assertSame('claimed', $this->admission->status);
        $this->assertNotNull($this->admission->claimed_at);

        // Hai cookie: xác nhận ca thi và định danh trình duyệt.
        $res->assertCookie(ExamAdmissionService::COOKIE_NAME);
        $res->assertCookie(ExamLoginService::BROWSER_COOKIE);
    }

    public function test_exam_token_is_scoped_to_the_exam_session(): void
    {
        $token = $this->login($this->admission->username, self::TEMP_PASSWORD)->json('access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $this->student->id)
            ->assertJsonPath('examSessionId', $this->session->id);
    }

    public function test_exam_username_is_matched_case_insensitively(): void
    {
        $this->login(strtolower($this->admission->username), self::TEMP_PASSWORD)
            ->assertOk()
            ->assertJsonPath('user.id', $this->student->id);
    }

    public function test_exam_username_with_wrong_password_returns_401(): void
    {
        $this->login($this->admission->username, 'sai-mat-khau')
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Sai tài khoản hoặc mật khẩu ca thi.');

        $this->assertSame('issued', $this->admission->fresh()->status);
    }

    public function test_revoked_exam_username_returns_401_even_with_the_right_password(): void
    {
        $this->admission->update(['status' => 'revoked']);

        $this->login($this->admission->username, self::TEMP_PASSWORD)
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Sai tài khoản hoặc mật khẩu ca thi.');
    }

    public function test_initial_password_is_accepted_even_when_the_hash_differs(): void
    {
        $this->admission->update(['hashed_password' => Hash::make('mat-khau-da-doi')]);

        $this->login($this->admission->username, self::TEMP_PASSWORD)->assertOk();
    }

    // ══ B. Email hoặc MSSV + mật khẩu tạm ════════════════════════════════════

    public function test_student_email_with_exam_temporary_password_signs_into_the_session(): void
    {
        $this->login($this->student->email, self::TEMP_PASSWORD)
            ->assertOk()
            ->assertJsonPath('user.id', $this->student->id)
            ->assertJsonPath('user.examSessionId', $this->session->id);

        $this->assertSame('claimed', $this->admission->fresh()->status);
    }

    public function test_student_code_with_exam_temporary_password_signs_into_the_session(): void
    {
        $this->login($this->studentCode, self::TEMP_PASSWORD)
            ->assertOk()
            ->assertJsonPath('user.id', $this->student->id)
            ->assertJsonPath('user.examSessionId', $this->session->id);
    }

    public function test_student_email_with_wrong_password_returns_the_generic_401(): void
    {
        $this->login($this->student->email, 'sai-mat-khau')
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Email hoặc mật khẩu không chính xác.');

        $this->assertSame('issued', $this->admission->fresh()->status);
    }

    public function test_revoked_admissions_are_ignored_when_signing_in_with_email(): void
    {
        $this->admission->update(['status' => 'revoked']);

        $this->login($this->student->email, self::TEMP_PASSWORD)
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Email hoặc mật khẩu không chính xác.');
    }

    public function test_unknown_account_returns_the_generic_401(): void
    {
        $this->login('khong.ton.tai@gm.uit.edu.vn', self::TEMP_PASSWORD)
            ->assertStatus(401)
            ->assertJsonPath('detail', 'Email hoặc mật khẩu không chính xác.');
    }

    public function test_normal_login_with_the_real_password_still_works_and_is_not_an_exam_login(): void
    {
        $res = $this->login($this->student->email, self::REAL_PASSWORD);

        $res->assertOk()->assertJsonPath('user.id', $this->student->id);
        $this->assertNull($res->json('user.examSessionId'));
        $this->assertSame('issued', $this->admission->fresh()->status, 'đăng nhập thường không được dùng tài khoản ca thi');
    }

    // ══ Điều kiện của ca thi ═════════════════════════════════════════════════

    public function test_unpublished_session_returns_403(): void
    {
        $this->session->update(['published' => false]);

        $this->login($this->admission->username, self::TEMP_PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Ca thi chưa được công bố.');
    }

    public function test_finished_session_returns_403(): void
    {
        $this->session->update(['closes' => now()->subMinute()]);

        $this->login($this->admission->username, self::TEMP_PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Ca thi đã kết thúc.');
    }

    public function test_inactive_student_cannot_sign_into_the_session(): void
    {
        $this->student->update(['status' => 'Inactive']);

        $this->login($this->admission->username, self::TEMP_PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Tài khoản sinh viên không khả dụng.');
    }

    // ══ Đăng nhập trùng ══════════════════════════════════════════════════════

    public function test_second_login_from_another_browser_is_locked_pending_proctor_approval(): void
    {
        $this->login($this->admission->username, self::TEMP_PASSWORD)
            ->assertOk()->assertJsonPath('locked', false);

        // Trình duyệt thứ hai không có cookie của trình duyệt đầu tiên.
        $this->login($this->admission->username, self::TEMP_PASSWORD)
            ->assertOk()
            ->assertJsonPath('locked', true);

        $state = ExamAccessState::where('session_id', $this->session->id)
            ->where('student_id', $this->student->id)
            ->first();
        $this->assertTrue((bool) $state->locked);
        $this->assertSame('duplicate_login', $state->lock_reason);
    }
}
