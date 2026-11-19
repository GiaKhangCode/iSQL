<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\ExamAdmission;
use App\Models\User;
use App\Services\ExamAdmissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Test đặc tả cho POST /api/exam-admission/{activityId}/unlock
 * (ExamAdmissionController::unlockAdmission).
 *
 * Sinh viên đã đăng nhập nhập lại tài khoản + mật khẩu ca thi để mở khóa vào làm bài; nếu
 * đúng thì được đặt cookie xác nhận. Khóa hành vi hiện tại trước khi tách (lộ trình 1.5).
 */
class ExamAdmissionUnlockTest extends TestCase
{
    use DatabaseTransactions;

    private const TEMP_PASSWORD = 'Unlock-Pass-7k';

    protected User $instructor;
    protected User $student;
    protected Assignment $session;
    protected ExamAdmission $admission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = $this->makeUser('instructor');
        $this->student = $this->makeUser('student');
        $this->session = $this->makeSession();
        $this->admission = ExamAdmission::create([
            'session_id'       => $this->session->id,
            'student_id'       => $this->student->id,
            'username'         => 'K7_' . strtoupper(Str::random(12)),
            'hashed_password'  => Hash::make(self::TEMP_PASSWORD),
            'initial_password' => self::TEMP_PASSWORD,
            'status'           => 'issued',
            'generation'       => 1,
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name'            => "Unlock {$role}",
            'email'           => "unlock_{$role}_" . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => 'Active',
            'initials'        => 'UL',
        ]);
    }

    private function makeSession(array $overrides = []): Assignment
    {
        return Assignment::create(array_merge([
            'title'              => 'Ca thi mở khóa ' . uniqid(),
            'instructor_id'      => $this->instructor->id,
            'published'          => true,
            'exam_mode'          => true,
            'admission_required' => true,
            'opens'              => now()->subHour(),
            'closes'             => now()->addHours(2),
        ], $overrides));
    }

    private function unlock(string $username, string $password, ?string $activityId = null)
    {
        return $this->postJson('/api/exam-admission/' . ($activityId ?? $this->session->id) . '/unlock', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    // ── Phân quyền ──────────────────────────────────────────────────────────

    public function test_unlock_requires_authentication(): void
    {
        $this->unlock($this->admission->username, self::TEMP_PASSWORD)->assertStatus(401);
    }

    // ── Thành công ──────────────────────────────────────────────────────────

    public function test_correct_credentials_unlock_the_session_and_set_the_admission_cookie(): void
    {
        Sanctum::actingAs($this->student);

        $res = $this->unlock($this->admission->username, self::TEMP_PASSWORD);

        $res->assertOk()->assertJsonPath('admissionUnlocked', true);
        $this->assertNotEmpty($res->json('serverTime'));
        $res->assertCookie(ExamAdmissionService::COOKIE_NAME);

        $this->admission->refresh();
        $this->assertSame('claimed', $this->admission->status);
        $this->assertNotNull($this->admission->claimed_at);

        $this->assertTrue(
            ActivityLog::where('user_id', $this->student->id)
                ->where('action', "exam_admission_unlock:{$this->session->id}:{$this->admission->id}")
                ->exists(),
            'mỗi lần mở khóa phải ghi nhật ký hoạt động'
        );
    }

    public function test_username_is_trimmed_and_case_insensitive(): void
    {
        Sanctum::actingAs($this->student);

        $this->unlock('  ' . strtolower($this->admission->username) . '  ', self::TEMP_PASSWORD)
            ->assertOk()
            ->assertJsonPath('admissionUnlocked', true);
    }

    public function test_initial_password_is_accepted_even_when_the_hash_differs(): void
    {
        $this->admission->update(['hashed_password' => Hash::make('mat-khau-da-doi')]);

        Sanctum::actingAs($this->student);

        $this->unlock($this->admission->username, self::TEMP_PASSWORD)->assertOk();
    }

    public function test_session_that_does_not_require_admission_unlocks_without_credentials_or_cookie(): void
    {
        $open = $this->makeSession(['admission_required' => false]);

        Sanctum::actingAs($this->student);

        $res = $this->unlock('', '', $open->id);

        $res->assertOk()->assertJsonPath('admissionUnlocked', true);
        $res->assertCookieMissing(ExamAdmissionService::COOKIE_NAME);
        $this->assertSame('issued', $this->admission->fresh()->status);
    }

    // ── Từ chối ─────────────────────────────────────────────────────────────

    public function test_wrong_password_returns_403_without_cookie_or_state_change(): void
    {
        Sanctum::actingAs($this->student);

        $res = $this->unlock($this->admission->username, 'sai-mat-khau');

        $res->assertStatus(403)->assertJsonPath('detail', 'Sai tài khoản hoặc mật khẩu ca thi.');
        $res->assertCookieMissing(ExamAdmissionService::COOKIE_NAME);
        $this->assertSame('issued', $this->admission->fresh()->status);
    }

    public function test_wrong_username_returns_403(): void
    {
        Sanctum::actingAs($this->student);

        $this->unlock('K7_SAI_TEN', self::TEMP_PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Sai tài khoản hoặc mật khẩu ca thi.');
    }

    public function test_student_without_an_admission_for_the_session_returns_403(): void
    {
        Sanctum::actingAs($this->makeUser('student'));

        $this->unlock($this->admission->username, self::TEMP_PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Tài khoản ca thi không hợp lệ hoặc đã bị thu hồi.');
    }

    public function test_revoked_admission_returns_403(): void
    {
        $this->admission->update(['status' => 'revoked']);

        Sanctum::actingAs($this->student);

        $res = $this->unlock($this->admission->username, self::TEMP_PASSWORD);

        $res->assertStatus(403)->assertJsonPath('detail', 'Tài khoản ca thi không hợp lệ hoặc đã bị thu hồi.');
        $res->assertCookieMissing(ExamAdmissionService::COOKIE_NAME);
    }

    public function test_session_not_yet_open_returns_403(): void
    {
        $this->session->update(['opens' => now()->addHour()]);

        Sanctum::actingAs($this->student);

        $this->unlock($this->admission->username, self::TEMP_PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Ca thi chưa đến giờ mở.');
    }

    public function test_finished_session_returns_403(): void
    {
        $this->session->update(['closes' => now()->subMinute()]);

        Sanctum::actingAs($this->student);

        $this->unlock($this->admission->username, self::TEMP_PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Ca thi đã kết thúc.');
    }

    public function test_unknown_activity_returns_404(): void
    {
        Sanctum::actingAs($this->student);

        $this->unlock($this->admission->username, self::TEMP_PASSWORD, (string) Str::uuid())->assertStatus(404);
    }
}
