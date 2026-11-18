<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        //
    }
}
