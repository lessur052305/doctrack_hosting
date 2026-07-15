<?php

use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register the RBAC alias used throughout routes/web.php
        // (e.g. ->middleware('role:admin')). This is the Laravel 11
        // equivalent of adding it to $middlewareAliases in Kernel.php.
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Section 5 safety net, in two steps, both every 5 minutes:
        // 1) flag individually expired parallel approver assignments
        $schedule->command('workflow:check-parallel-slas')->everyFiveMinutes()->withoutOverlapping();
        // 2) auto-approve anything still unresolved past the Admin grace window
        $schedule->command('sla:check')->everyFiveMinutes()->withoutOverlapping();
        // 3) drain the database queue (Section 3 email notifications) —
        // no long-running queue:work process is documented for this app.
        $schedule->command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();