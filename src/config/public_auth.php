<?php

return [
    // Local OTP is an explicit development alternative; provider errors never use it.
    'provider' => env('PUBLIC_AUTH_PROVIDER', 'razavi'),

    'razavi' => [
        'base_url' => env('RAZAVI_AUTH_BASE_URL', 'https://haram-api-dev.razavi.ir/auth'),
        'project' => env('RAZAVI_AUTH_PROJECT', 'noorolhoda'),
        'device' => env('RAZAVI_AUTH_DEVICE', 'web'),
        'connect_timeout' => (int) env('RAZAVI_AUTH_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('RAZAVI_AUTH_TIMEOUT', 10),
    ],

    // Deployment hints: the OpenAPI only supplies an example four-digit code.
    // Confirm these values with the employer before production deployment.
    'otp' => [
        'length' => max(4, min(8, (int) env('RAZAVI_AUTH_OTP_LENGTH', 4))),
        'ttl_seconds' => (int) env('RAZAVI_AUTH_OTP_TTL_SECONDS', 120),
        'cooldown_seconds' => (int) env('RAZAVI_AUTH_OTP_COOLDOWN_SECONDS', 30),
        'max_attempts' => (int) env('RAZAVI_AUTH_OTP_MAX_ATTEMPTS', 5),
    ],

    'local' => [
        'return_in_response' => (bool) env('OTP_RETURN_IN_RESPONSE', false),
        'dev_bypass' => (bool) env('OTP_DEV_BYPASS', false),
    ],
];
