<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

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

        // Custom mailer — Laravel has no built-in Brevo driver. Brevo's
        // *SMTP* transport times out from Railway (outbound SMTP appears
        // blocked there regardless of provider — Resend's API worked fine
        // in the same environment), so this uses Brevo's HTTP API instead
        // via Symfony's brevo-mailer bridge. Brevo was chosen over Resend
        // because it only requires verifying the individual sender address
        // (a one-click email confirmation), not owning/verifying a DNS
        // domain — this app sends from real personal Gmail addresses with
        // no domain of its own.
        Mail::extend('brevo', fn (array $config) => (new BrevoTransportFactory())
            ->create(new Dsn('brevo+api', 'default', $config['key'] ?? null)));
    }
}
