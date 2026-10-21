<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPasswordOtpMail;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('mail:test {email : Địa chỉ email người nhận để kiểm tra}', function (string $email) {
    $mailer = config('mail.default');
    $this->info("--- KIỂM TRA CẤU HÌNH GỬI MAIL ---");
    $this->line("Mailer: {$mailer}");
    $this->line("Host: " . config('mail.mailers.smtp.host') . ":" . config('mail.mailers.smtp.port'));
    $this->line("Encryption: " . config('mail.mailers.smtp.encryption'));
    $this->line("Username: " . (config('mail.mailers.smtp.username') ?: '(chưa cấu hình)'));
    $this->line("From: " . config('mail.from.address') . " (" . config('mail.from.name') . ")");
    $this->newLine();

    $this->info("Đang tiến hành gửi email OTP thử nghiệm tới: {$email}...");
    try {
        $testOtp = (string) random_int(100000, 999999);
        Mail::to($email)->send(new ResetPasswordOtpMail($testOtp, 'Thử nghiệm hệ thống'));
        $this->info("✅ Gửi email thành công! Mã OTP test là: [{$testOtp}]. Hãy kiểm tra hộp thư (hoặc mục Spam) của {$email}.");
    } catch (\Throwable $e) {
        $this->error("❌ Gửi mail thất bại: " . $e->getMessage());
        $this->newLine();
        $this->warn("Gợi ý khắc phục:");
        $this->line("1. Đảm bảo đã bật 'Xác minh 2 bước' (2-Step Verification) cho tài khoản Google.");
        $this->line("2. Tạo 'Mật khẩu ứng dụng' (App Password) 16 ký tự tại https://myaccount.google.com/apppasswords");
        $this->line("3. Điền mật khẩu ứng dụng đó vào MAIL_PASSWORD trong file .env (không dùng mật khẩu đăng nhập thông thường).");
    }
})->purpose('Kiểm tra cấu hình SMTP Google Mail và gửi thử email OTP');
