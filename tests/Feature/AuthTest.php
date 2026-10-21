<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_validation(): void
    {
        $response = $this->postJson('/api/auth/login', []);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_student_can_register_successfully(): void
    {
        $payload = [
            'name'     => 'Nguyen Van Test',
            'email'    => 'student_test_' . uniqid() . '@gm.uit.edu.vn',
            'password' => 'Password123!',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'access_token',
                     'token_type',
                     'user' => ['id', 'email', 'name', 'role', 'status'],
                 ]);

        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'role'  => 'student',
        ]);
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        $email = 'duplicate_' . uniqid() . '@gm.uit.edu.vn';
        User::create([
            'name'            => 'First User',
            'email'           => $email,
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'FU',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name'     => 'Second User',
            'email'    => $email,
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_lecturer_can_register(): void
    {
        $payload = [
            'name'       => 'Lecturer Test',
            'email'      => 'lecturer_test_' . uniqid() . '@uit.edu.vn',
            'password'   => 'Password123!',
            'department' => 'Software Engineering',
        ];

        $response = $this->postJson('/api/auth/register-lecturer', $payload);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'access_token',
                     'token_type',
                     'user' => ['id', 'email', 'name', 'role', 'department'],
                 ]);

        $this->assertDatabaseHas('users', [
            'email'      => $payload['email'],
            'role'       => 'instructor',
            'department' => 'Software Engineering',
        ]);
    }

    public function test_login_success_with_valid_credentials(): void
    {
        $email = 'active_user_' . uniqid() . '@uit.edu.vn';
        $user = User::create([
            'name'            => 'Active User',
            'email'           => $email,
            'hashed_password' => Hash::make('secret123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'AU',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'access_token',
                     'token_type',
                     'user',
                 ]);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $email = 'wrong_pass_' . uniqid() . '@uit.edu.vn';
        User::create([
            'name'            => 'Wrong Pass User',
            'email'           => $email,
            'hashed_password' => Hash::make('correct_password'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'WP',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401)
                 ->assertJson(['detail' => 'Email hoặc mật khẩu không chính xác.']);
    }

    public function test_me_returns_current_user_profile(): void
    {
        $user = User::create([
            'name'            => 'Current User',
            'email'           => 'current_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'admin',
            'status'          => 'Active',
            'initials'        => 'CU',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->getJson('/api/auth/me');

        $response->assertStatus(200)
                 ->assertJson([
                     'id'    => $user->id,
                     'email' => $user->email,
                     'name'  => $user->name,
                 ]);
    }

    public function test_update_me_profile(): void
    {
        $user = User::create([
            'name'            => 'Profile Before',
            'email'           => 'before_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'PB',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->patchJson('/api/auth/me', [
                             'name'       => 'Profile After Update',
                             'department' => 'Cyber Security',
                         ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'name'       => 'Profile After Update',
                     'department' => 'Cyber Security',
                 ]);
    }

    public function test_me_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(401);
    }

    public function test_forgot_password_verify_otp_and_reset_flow(): void
    {
        Mail::fake();

        $email = 'reset_' . uniqid() . '@uit.edu.vn';
        User::create([
            'name'            => 'Reset User',
            'email'           => $email,
            'hashed_password' => Hash::make('old_pass_123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'RU',
        ]);

        // 1. Request OTP
        $forgotResponse = $this->postJson('/api/auth/forgot-password', [
            'email' => $email,
        ]);
        $forgotResponse->assertStatus(200);

        // Fetch generated OTP from database
        $tokenRecord = DB::table('password_reset_tokens')->where('email', $email)->first();
        $this->assertNotNull($tokenRecord);
        $otp = $tokenRecord->token;

        // 2. Verify OTP
        $verifyResponse = $this->postJson('/api/auth/verify-otp', [
            'email' => $email,
            'otp'   => $otp,
        ]);
        $verifyResponse->assertStatus(200);

        // 3. Reset Password
        $resetResponse = $this->postJson('/api/auth/reset-password', [
            'email'                 => $email,
            'otp'                   => $otp,
            'password'              => 'new_pass_456',
            'password_confirmation' => 'new_pass_456',
        ]);
        $resetResponse->assertStatus(200);

        // 4. Verify user can login with new password
        $loginResponse = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => 'new_pass_456',
        ]);
        $loginResponse->assertStatus(200);
    }
}
