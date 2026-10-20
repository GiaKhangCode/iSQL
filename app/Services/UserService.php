<?php

namespace App\Services;

use App\Models\User;
use App\Mail\ResetPasswordOtpMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class UserService
{
    public function registerStudent(array $data): User
    {
        // 1. Băm mật khẩu bằng thuật toán Bcrypt an toàn (tương tự BCryptPasswordEncoder trong Java)
        $hashedPassword = Hash::make($data['password']);

        // 2. Tự động sinh chữ viết tắt (initials) cho avatar từ tên người dùng (VD: "Nguyen An" -> "NA")
        $initials = mb_strtoupper(mb_substr(trim($data['name']), 0, 2, 'UTF-8'), 'UTF-8');

        // 3. Tạo bản ghi mới thông qua Eloquent Model User (Id UUID sẽ được Trait HasUuid tự sinh)
        return User::create([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'hashed_password' => $hashedPassword,
            'initials'        => $initials,
            'role'            => 'student', // Mặc định là sinh viên
            'status'          => 'Active',  // Sinh viên kích hoạt dùng được ngay
        ]);
    }

    public function registerLecturer(array $data): User
    {
        // 1. Băm mật khẩu bằng thuật toán Bcrypt an toàn (tương tự BCryptPasswordEncoder trong Java)
        $hashedPassword = Hash::make($data['password']);

        // 2. Tự động sinh chữ viết tắt (initials) cho avatar từ tên người dùng (VD: "Nguyen An" -> "NA")
        $initials = mb_strtoupper(mb_substr(trim($data['name']), 0, 2, 'UTF-8'), 'UTF-8');

        // 3. Tạo bản ghi mới thông qua Eloquent Model User (Id UUID sẽ được Trait HasUuid tự sinh)
        return User::create([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'hashed_password' => $hashedPassword,
            'initials'        => $initials,
            'role'            => 'instructor', // Vai trò Giảng viên
            'status'          => 'Active',    // Trạng thái chờ Admin phê duyệt
            'department'      => $data['department'],
        ]);
    }

    public function authenticate(string $email, string $password): User
    {
        // 1. Tìm user trong CSDL theo email
        $user = User::where('email', $email)->first();

        // 2. Kiểm tra nếu không tìm thấy user HOẶC mật khẩu băm không khớp
        // Hash::check() tương đương passwordEncoder.matches() trong Java Spring Security
        if (!$user || !Hash::check($password, $user->hashed_password)) {
            throw new \Exception('Email hoặc mật khẩu không chính xác.', 401);
        }
        // 3. Kiểm tra trạng thái tài khoản (phục vụ luồng phê duyệt và khóa tài khoản)
        if ($user->status === 'Pending') {
            throw new \Exception('Tài khoản của bạn đang chờ quản trị viên phê duyệt.', 403);
        }

        if ($user->status === 'Inactive') {
            throw new \Exception('Tài khoản của bạn đã bị khóa.', 403);
        }
        return $user;
    }

    public function sendPasswordResetOtp(string $email): string
    {
        // 1. Tìm thông tin người dùng nếu có để hiển thị tên thân thiện trong email
        $user = User::where('email', $email)->first();

        // 2. Sinh ngẫu nhiên mã OTP 6 chữ số (từ 100000 đến 999999)
        $otp = (string) random_int(100000, 999999);

        // 3. Xóa các mã OTP cũ của email này nếu có
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // 4. Lưu mã mới với hạn dùng 15 phút
        DB::table('password_reset_tokens')->insert([
            'email'      => $email,
            'token'      => $otp,
            'created_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        // 5. Ghi log để tiện kiểm tra / debug
        Log::info("Password reset OTP for {$email}: {$otp}");

        // 6. Gửi email OTP qua Mailer (Gmail SMTP hoặc log tùy cấu hình .env)
        Mail::to($email)->send(new ResetPasswordOtpMail($otp, $user?->name));

        return $otp;
    }
    // 2. Kiểm tra tính hợp lệ của mã OTP
    public function verifyOtp(string $email, string $otp): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $otp)
            ->first();
        if (!$record) {
            throw new \Exception('Mã xác thực không chính xác.', 400);
        }
        if (Carbon::parse($record->expires_at)->isPast()) {
            throw new \Exception('Mã xác thực đã hết hạn. Vui lòng yêu cầu mã mới.', 400);
        }
        return true;
    }
    // 3. Đặt lại mật khẩu mới
    public function resetPassword(string $email, string $otp, string $newPassword): void
    {
        // Xác thực lại OTP một lần nữa để chống giả mạo
        $this->verifyOtp($email, $otp);
        $user = User::where('email', $email)->first();
        if (!$user) {
            throw new \Exception('Không tìm thấy tài khoản người dùng.', 404);
        }
        // Cập nhật mật khẩu băm mới
        $user->hashed_password = Hash::make($newPassword);
        $user->save();
        // Xóa mã OTP sau khi đổi mật khẩu thành công
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }
}