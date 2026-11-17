<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Mã OTP đặt lại mật khẩu - iSQL Platform</title>
    <style>
        :root {
            color-scheme: light dark;
            supported-color-schemes: light dark;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
            -webkit-text-size-adjust: 100%;
            line-height: 1.6;
        }

        .wrapper {
            width: 100%;
            background-color: #f1f5f9;
            padding: 30px 0;
        }

        .container {
            max-width: 580px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
        }

        /* ── Header: Logo Khoa HTTT bên trái, chữ canh lề trái chạy từ logo ra ── */
        .header {
            background-color: #005baa;
            padding: 22px 24px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logo-col {
            width: 62px;
            vertical-align: middle;
            padding-right: 14px;
        }

        .logo-badge {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 0;
            width: 56px;
            height: 56px;
            overflow: hidden;
            box-sizing: border-box;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
            line-height: 0;
            font-size: 0;
        }

        .logo-img {
            display: block;
            width: 56px;
            height: 56px;
            border-radius: 10px;
            object-fit: cover;
            border: 0;
            outline: none;
            text-decoration: none;
        }

        .text-col {
            vertical-align: middle;
            text-align: left;
        }

        .header-dept {
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 0 0 3px 0;
            line-height: 1.25;
        }

        .header-school {
            color: #e0f2fe;
            font-size: 12px;
            font-weight: 500;
            margin: 0 0 4px 0;
            line-height: 1.3;
        }

        .header-portal {
            color: #bae6fd;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin: 0;
            line-height: 1.25;
        }

        /* ── Nội dung email ── */
        .content {
            padding: 34px 30px 28px 30px;
            background-color: #ffffff;
        }

        .greeting {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 16px 0;
        }

        .desc {
            font-size: 15px;
            color: #334155;
            margin: 0 0 22px 0;
            line-height: 1.6;
        }

        /* ── Khung mã OTP tương tác một chạm (Phong cách GitHub: Chạm/Click là tự động chọn toàn bộ mã) ── */
        .otp-wrapper {
            text-align: center;
            margin: 24px 0 22px 0;
        }

        .otp-box {
            display: inline-block;
            background-color: #f1f8ff;
            border: 1.5px solid #c8e1ff;
            border-radius: 8px;
            padding: 14px 28px;
            text-align: center;
            cursor: pointer;
            user-select: all !important;
            -webkit-user-select: all !important;
            -moz-user-select: all !important;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 91, 170, 0.08);
        }

        .otp-box:hover {
            background-color: #e0f2fe;
            border-color: #005baa;
            box-shadow: 0 2px 8px rgba(0, 91, 170, 0.16);
        }

        .otp-code {
            font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace;
            font-size: 34px;
            font-weight: 800;
            letter-spacing: 6px;
            color: #005baa;
            margin: 0;
            display: inline-block;
            user-select: all !important;
            -webkit-user-select: all !important;
            -moz-user-select: all !important;
        }

        .otp-hint {
            font-size: 12.5px;
            color: #64748b;
            margin-top: 10px;
            text-align: center;
        }


        /* ── Khối thông báo thời hạn & bảo mật (Định dạng phẳng, tinh gọn chuẩn GitHub như ảnh) ── */
        .notice-section {
            margin: 22px 0 20px 0;
        }

        .notice-desc {
            font-size: 14.5px;
            color: #334155;
            line-height: 1.6;
            margin: 0 0 16px 0;
        }

        .closing {
            font-size: 14.5px;
            color: #334155;
            margin-top: 20px;
            margin-bottom: 0;
        }

        /* ── Footer ── */
        .footer {
            background-color: #f8fafc;
            padding: 22px 20px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
        }

        .footer-dept {
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            font-size: 13px;
            margin: 0 0 3px 0;
        }

        .footer-school {
            color: #64748b;
            font-size: 12px;
            margin: 0 0 6px 0;
        }

        .footer-link {
            color: #005baa;
            text-decoration: none;
            font-weight: 500;
        }

        .footer-link:hover {
            text-decoration: underline;
        }

        /* ── CẤU HÌNH TỐI ƯU DARK MODE (CHỐNG MẤT CHỮ TRÊN GMAIL / OUTLOOK / APPLE) ── */
        @media (prefers-color-scheme: dark) {
            body, .wrapper {
                background-color: #0f172a !important;
                color: #e2e8f0 !important;
            }

            .container {
                background-color: #1e293b !important;
                border-color: #334155 !important;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4) !important;
            }

            .content {
                background-color: #1e293b !important;
            }

            .greeting {
                color: #f8fafc !important;
            }

            .desc {
                color: #cbd5e1 !important;
            }

            .otp-box {
                background-color: #0f172a !important;
                border-color: #0284c7 !important;
                box-shadow: none !important;
            }

            .otp-box:hover {
                background-color: #1e293b !important;
                border-color: #38bdf8 !important;
            }

            .otp-code {
                color: #38bdf8 !important;
            }

            .otp-hint {
                color: #94a3b8 !important;
            }

            .notice-desc {
                color: #cbd5e1 !important;
            }

            .notice-desc strong {
                color: #f8fafc !important;
            }

            .closing {
                color: #cbd5e1 !important;
            }

            .closing strong {
                color: #f8fafc !important;
            }

            .footer {
                background-color: #0f172a !important;
                border-top-color: #334155 !important;
                color: #94a3b8 !important;
            }

            .footer-dept {
                color: #e2e8f0 !important;
            }

            .footer-school {
                color: #94a3b8 !important;
            }

            .footer-link {
                color: #38bdf8 !important;
            }

            .logo-badge {
                background-color: #ffffff !important;
            }
        }

        /* Hỗ trợ tiền tố của Gmail Dark Mode ([data-ogsc]) */
        [data-ogsc] body, [data-ogsc] .wrapper { background-color: #0f172a !important; color: #e2e8f0 !important; }
        [data-ogsc] .container { background-color: #1e293b !important; border-color: #334155 !important; }
        [data-ogsc] .content { background-color: #1e293b !important; }
        [data-ogsc] .greeting { color: #f8fafc !important; }
        [data-ogsc] .desc { color: #cbd5e1 !important; }
        [data-ogsc] .otp-box { background-color: #0f172a !important; border-color: #0284c7 !important; }
        [data-ogsc] .otp-code { color: #38bdf8 !important; }
        [data-ogsc] .otp-hint { color: #94a3b8 !important; }

        [data-ogsc] .notice-desc { color: #cbd5e1 !important; }
        [data-ogsc] .notice-desc strong { color: #f8fafc !important; }
        [data-ogsc] .footer { background-color: #0f172a !important; border-top-color: #334155 !important; color: #94a3b8 !important; }
        [data-ogsc] .footer-dept { color: #e2e8f0 !important; }
        [data-ogsc] .logo-badge { background-color: #ffffff !important; }
        [data-ogsc] .footer-link { color: #38bdf8 !important; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <!-- Header: Logo Khoa HTTT bên trái, chữ canh lề trái chạy từ logo ra -->
            <div class="header">
                <table class="header-table" role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="logo-col">
                            <div class="logo-badge" style="width: 56px; height: 56px; border-radius: 10px; overflow: hidden; padding: 0; background-color: #ffffff; line-height: 0; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);">
                                <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSTMO7TDRcOF6TnhZGmOjzBp1FVGvk49RqVoUCq1EI7pJLegh3OEMzEa54&s=10" width="56" height="56" class="logo-img" alt="Logo Khoa HTTT" style="width: 56px; height: 56px; display: block; border-radius: 10px; object-fit: cover; border: 0;">
                            </div>
                        </td>
                        <td class="text-col">
                            <div class="header-dept">KHOA HỆ THỐNG THÔNG TIN</div>
                            <div class="header-school">TRƯỜNG ĐẠI HỌC CÔNG NGHỆ THÔNG TIN - ĐHQG-HCM</div>
                            <div class="header-portal">iSQL - STUDENT ASSESSMENT & PRACTICE PORTAL</div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Content -->
            <div class="content">
                <p class="greeting">Xin chào {{ !empty($userName) ? $userName . ',' : 'bạn,' }}</p>
                
                <p class="desc">
                    Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản <strong>iSQL Platform</strong> liên kết với email này. Vui lòng sử dụng mã xác thực (OTP) bên dưới để hoàn tất việc đổi mật khẩu:
                </p>

                <!-- Khung mã OTP định dạng chuẩn giúp Gmail / Apple Mail tự động nhận diện và hiển thị nút "Copy code" -->
                <div class="otp-wrapper" style="text-align: center; margin: 26px 0 22px 0;">
                    <div style="font-size: 13.5px; font-weight: 600; color: #475569; margin-bottom: 10px; letter-spacing: 0.5px;">
                        Mã xác thực của bạn là:
                    </div>
                    <div class="otp-box" id="otpBox" title="Chạm hoặc nhấp vào để tự động chọn toàn bộ mã" style="display: inline-block; background-color: #f1f8ff; border: 1.5px solid #c8e1ff; border-radius: 8px; padding: 14px 32px; text-align: center; cursor: pointer; user-select: all !important; -webkit-user-select: all !important; -moz-user-select: all !important;">
                        <span class="otp-code" id="otpCodeDisplay" style="font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace; font-size: 34px; font-weight: 800; letter-spacing: 6px; color: #005baa; display: inline-block; user-select: all !important; -webkit-user-select: all !important; -moz-user-select: all !important;">{{ $otp }}</span>
                    </div>

                </div>

                <!-- Thông báo thời hạn & bảo mật (Định dạng phẳng, tinh gọn chuẩn GitHub như hình mẫu) -->
                <div class="notice-section">
                    <p class="notice-desc">
                        Mã có hiệu lực trong <strong>15 phút</strong> kể từ thời điểm gửi email này. Vui lòng không chia sẻ mã này cho người khác.
                    </p>
                    <p class="notice-desc" style="margin-bottom: 0;">
                        Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email.
                    </p>
                </div>

                <p class="closing">
                    Trân trọng,<br>
                    <strong>Ban Phát Triển Hệ Thống iSQL - Khoa HTTT (UIT)</strong>
                </p>
            </div>

            <!-- Footer -->
            <div class="footer">
                <div class="footer-dept">KHOA HỆ THỐNG THÔNG TIN</div>
                <div class="footer-school">Trường Đại học Công nghệ Thông tin - ĐHQG-HCM</div>
                <div class="footer-contact">
                    Email hỗ trợ: <a href="mailto:info.httt@uit.edu.vn" class="footer-link">info.httt@uit.edu.vn</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
