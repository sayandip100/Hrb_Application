<?php

namespace App\Providers;

use App\Domain\Identity\EmailOtpSender;
use App\Domain\Identity\OtpSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class StaffAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpSender::class, EmailOtpSender::class);
    }

    public function boot(): void
    {
        RateLimiter::for('staff-otp-request', function (Request $request): array {
            $role = $request->input('role_id');
            $mobile = $request->input('mobile');
            $identity = hash_hmac('sha256', (is_scalar($role) ? (string) $role : '').'|'.(is_scalar($mobile) ? (string) $mobile : ''), (string) config('app.key'));

            return [
                Limit::perMinute(10)->by('staff-otp-ip:'.$request->ip()),
                Limit::perHour(5)->by('staff-otp-account:'.$identity),
            ];
        });

        RateLimiter::for('staff-otp-verify', function (Request $request): array {
            $challenge = $request->input('challenge_id');

            return [
                Limit::perMinute(30)->by('staff-verify-ip:'.$request->ip()),
                Limit::perMinute(10)->by('staff-verify-challenge:'.hash('sha256', is_string($challenge) ? $challenge : '')),
            ];
        });
    }
}
