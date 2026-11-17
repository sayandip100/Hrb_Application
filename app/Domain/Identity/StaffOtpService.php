<?php

namespace App\Domain\Identity;

use App\Models\StaffUser;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class StaffOtpService
{
    public function __construct(private OtpSender $sender, private StaffAccess $access) {}

    /** @return array{challenge_id: string, expires_in: int, message: string, otp?: string} */
    public function issue(string $mobile, int $roleId, string $sessionId, string $ip): array
    {
        $localResponse = app()->environment('local') && (bool) config('staff_auth.otp_in_response');
        if (! $localResponse && ! $this->sender->isAvailable()) {
            throw new HttpException(503, 'Staff OTP email delivery is not configured yet.');
        }

        $staff = StaffUser::query()->with('role')->where('mobile', $mobile)->where('u_type', $roleId)->where('user_status', 1)->limit(2)->get();
        $account = $staff->count() === 1 && $this->access->allows($staff->first(), $ip) ? $staff->first() : null;
        if ($account && filter_var($account->email, FILTER_VALIDATE_EMAIL) === false) {
            $account = null;
        }
        $identity = $this->digest($roleId.'|'.$mobile);
        $challengeId = (string) Str::uuid();
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int) config('staff_auth.otp_ttl');
        $record = [
            'account_id' => $account?->getKey(),
            'identity' => $identity,
            'role_id' => $roleId,
            'email_hash' => $account ? $this->digest($account->email) : null,
            'session' => $this->digest($sessionId),
            'code_hash' => $this->digest($challengeId.'|'.$otp),
            'expires_at' => now()->timestamp + $ttl,
            'attempts' => 0,
        ];

        $this->locked($identity, function () use ($identity, $challengeId, $record, $ttl): void {
            if (! $this->cache()->add($this->key('cooldown:'.$identity), true, (int) config('staff_auth.resend_seconds'))) {
                throw new HttpException(429, 'Please wait before requesting another verification code.', null, ['Retry-After' => (string) config('staff_auth.resend_seconds')]);
            }

            $previous = $this->cache()->get($this->key('current:'.$identity));
            if (is_string($previous)) {
                $this->cache()->forget($this->key('challenge:'.$previous));
            }

            $this->cache()->put($this->key('challenge:'.$challengeId), $record, $ttl);
            $this->cache()->put($this->key('current:'.$identity), $challengeId, $ttl);
        });

        if ($account && ! $localResponse) {
            try {
                $this->sender->sendOtp($account->email, $otp);
            } catch (Throwable) {
                $this->locked($identity, function () use ($identity, $challengeId): void {
                    $this->cache()->forget($this->key('challenge:'.$challengeId));
                    if ($this->cache()->get($this->key('current:'.$identity)) === $challengeId) {
                        $this->cache()->forget($this->key('current:'.$identity));
                    }
                });
                Log::warning('Staff OTP delivery failed.', ['challenge_id' => $challengeId]);
            }
        }

        $response = [
            'challenge_id' => $challengeId,
            'expires_in' => $ttl,
            'message' => 'If the account is eligible, a verification code will be sent.',
        ];
        if ($localResponse && $account) {
            $response['otp'] = $otp;
            $response['message'] = 'Development OTP generated. Email delivery skipped.';
        }

        return $response;
    }

    public function verify(string $challengeId, string $otp, string $sessionId, string $ip): StaffUser
    {
        $record = $this->cache()->get($this->key('challenge:'.$challengeId));
        if (! is_array($record)) {
            $this->invalidCode();
        }

        $staff = isset($record['account_id']) ? StaffUser::query()->with('role')->find($record['account_id']) : null;
        $eligible = $staff && $this->access->allows($staff, $ip)
            && $staff->u_type === $record['role_id']
            && is_string($record['email_hash'] ?? null)
            && hash_equals($record['email_hash'], $this->digest((string) $staff->email))
            && hash_equals($record['identity'], $this->digest($staff->u_type.'|'.$staff->mobile));

        $this->locked($record['identity'], function () use ($record, $challengeId, $otp, $sessionId, $eligible): void {
            $current = $this->cache()->get($this->key('challenge:'.$challengeId));
            if (! is_array($current)
                || $this->cache()->get($this->key('current:'.$record['identity'])) !== $challengeId
                || $current['expires_at'] <= now()->timestamp
                || ! hash_equals($current['session'], $this->digest($sessionId))) {
                $this->invalidCode();
            }

            $attempts = $current['attempts'] + 1;
            $matches = hash_equals($current['code_hash'], $this->digest($challengeId.'|'.$otp));

            if (! $eligible || ! $matches) {
                if ($attempts >= (int) config('staff_auth.max_attempts') || ! $eligible) {
                    $this->cache()->forget($this->key('challenge:'.$challengeId));
                    $this->cache()->forget($this->key('current:'.$record['identity']));
                } else {
                    $current['attempts'] = $attempts;
                    $this->cache()->put($this->key('challenge:'.$challengeId), $current, $current['expires_at'] - now()->timestamp);
                }
                $this->invalidCode();
            }

            $this->cache()->forget($this->key('challenge:'.$challengeId));
            $this->cache()->forget($this->key('current:'.$record['identity']));
        });

        return $staff;
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages(['otp' => 'The verification code is invalid or has expired.']);
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function key(string $suffix): string
    {
        return 'staff-auth:'.$suffix;
    }

    private function cache(): Repository
    {
        return Cache::store(config('staff_auth.cache_store'));
    }

    private function locked(string $identity, callable $callback): mixed
    {
        try {
            return $this->cache()->lock($this->key('lock:'.$identity), 30)->block(3, $callback);
        } catch (LockTimeoutException) {
            throw new HttpException(429, 'A login request is already being processed. Please retry.');
        }
    }
}
