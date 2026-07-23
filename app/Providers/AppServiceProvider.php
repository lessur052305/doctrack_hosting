<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Without this, Laravel derives every generated absolute URL (signed
        // verification/reset links included) from the CURRENT request's own
        // host header — not from APP_URL — for any real HTTP request (only
        // CLI/tinker, with no bound request, actually falls back to
        // config('app.url')). That's exactly why admin-triggered "Resend
        // verification" produced a broken link while a tinker-triggered
        // resend worked: the admin was browsing via 127.0.0.1:8000, so
        // Laravel built the email link from THAT host instead of the LAN
        // IP every other device needs. Forcing the root here makes every
        // generated URL consistent regardless of which host a given
        // request happened to arrive on.
        URL::forceRootUrl(config('app.url'));
    }
}
