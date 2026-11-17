<?php

return [
    'otp_in_response' => env('STAFF_AUTH_OTP_IN_RESPONSE', false),
    'cache_store' => env('STAFF_AUTH_CACHE_STORE', 'redis'),
    'otp_ttl' => 300,
    'resend_seconds' => 60,
    'max_attempts' => 5,
    'timezone' => 'Asia/Kolkata',
];
