<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: bộ nhớ đệm quyền sống trong một request/job, không rò sang request sau (queue worker).
        $this->app->scoped(\App\Services\Access\AccessControlService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Chặn dò mật khẩu: tối đa 10 lần/phút cho mỗi (tên đăng nhập, IP). Tính theo từng tên nên cả phòng thi
        // dùng chung một IP vẫn không ảnh hưởng nhau.
        RateLimiter::for('login', function (Request $request) {
            $account = Str::lower(trim((string) ($request->input('username') ?? $request->input('email') ?? '')));

            return Limit::perMinute(10)
                ->by($account . '|' . $request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Too Many Attempts.',
                    'detail'  => 'Bạn đăng nhập sai quá nhiều lần. Vui lòng thử lại sau ít phút.',
                ], 429));
        });
    }
}
