<?php

return ['cache_store' => env('CANDIDATE_AUTH_CACHE_STORE', 'redis'), 'otp_ttl' => 300, 'resend_seconds' => 60,
    'max_attempts' => 5, 'legacy_password_key' => env('CANDIDATE_LEGACY_PASSWORD_KEY')];
