<?php

use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // Registers POST /broadcasting/auth (web+auth middleware — matches
    // this app's existing session-cookie auth, no separate token needed)
    // and loads routes/channels.php's Broadcast::channel() authorization
    // callbacks. Required for private-channel WebSocket auth (Reverb) to
    // work at all — see resources/js/echo.js for the client side.
    ->withBroadcasting(
        __DIR__ . '/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register the RBAC alias used throughout routes/web.php
        // (e.g. ->middleware('role:admin')). This is the Laravel 11
        // equivalent of adding it to $middlewareAliases in Kernel.php.
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // Trust every proxy in front of this app (Railway, or any other
        // platform that terminates TLS at its own edge and forwards
        // requests to this container over plain HTTP). Without this,
        // Laravel has no way to know the original request was HTTPS, so
        // url()/asset()/Vite all generate http:// links — which the
        // browser then silently blocks as mixed content on an https:// page
        // (this is exactly what broke all CSS/JS on first deploy). Trusting
        // '*' is safe here specifically because the platform's edge is the
        // only way any traffic reaches this container — there's no direct
        // path for an external client to spoof X-Forwarded-* headers.
        $middleware->trustProxies(at: '*');
    })
    ->withSchedule(function (Schedule $schedule) {
        // Section 5 safety net. Primary detection is now event-driven —
        // EscalateAssignmentJob is dispatched with a delay set to exactly
        // each assignment's sla_expires_at (see WorkflowService), so a
        // breach is caught the instant it happens via the persistent queue
        // worker (see docuwise-queue-worker systemd user service), not on
        // a polling cycle.
        //
        // 1) backstop only: catches anything the event-driven job might
        // ever miss (e.g. the queue worker was down when a job should have
        // fired). 5 minutes is plenty for a safety net that isn't the
        // primary mechanism anymore.
        $schedule->command('workflow:check-parallel-slas')->everyFiveMinutes()->withoutOverlapping();
        // 2) auto-approve anything still unresolved past the Admin grace
        // window (hours-long by design — see SlaService::ADMIN_GRACE_HOURS)
        // — no need for minute-level precision here.
        $schedule->command('sla:check')->everyFiveMinutes()->withoutOverlapping();
        // Note: queue draining is no longer scheduled here — a persistent
        // `php artisan queue:work` process (systemd user service) runs
        // continuously instead, which is what makes EscalateAssignmentJob
        // actually fire in real time rather than up to a minute late.

        // Section 3 hardware requirement: nightly backup of the database
        // + document/ML-model storage (see BackupSystem's docblock — this
        // project has already lost both to out-of-band resets once each).
        // Low-traffic hour; overlap-safe in case a manual run is mid-flight.
        $schedule->command('backup:run')->dailyAt('02:00')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();