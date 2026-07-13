<?php

/**
 * Drop-in replacement for config/auth.php.
 * No structural change from Laravel's default other than confirming the
 * Eloquent provider points at our App\Models\User (which maps the
 * documented `password_hash` column onto the auth password via
 * getAuthPassword()). Kept here so the scaffold is self-documenting.
 */
return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
