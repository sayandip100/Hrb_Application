<?php

namespace Tests\Feature;

use App\Domain\Identity\CandidateRegistrationService;
use App\Models\CandidateUser;
use Database\Factories\AdvertisementFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\StaffAuthTestCase;

class CandidateRateLimitsTest extends StaffAuthTestCase
{
    public function test_candidate_otp_endpoint_enforces_three_requests_per_minute(): void
    {
        $this->travelTo(Carbon::parse('2027-01-10 12:00:00', 'Asia/Kolkata'));
        $advertisement = AdvertisementFactory::new()->create([
            'adv_activated' => 1,
            'adv_dictation_set' => 'No',
            'adv_fees' => 0,
        ]);

        $this->mock(CandidateRegistrationService::class, function ($mock): void {
            $mock->shouldReceive('issue')->times(3)->andReturn([
                'challenge_id' => 'test-challenge',
                'expires_in' => 300,
                'message' => 'If registration is available, a verification code will be sent to your email.',
            ]);
        });

        $payload = [
            'advertisement_id' => $this->publicId('advertisement', $advertisement->adv_id),
            'name' => 'Candidate Name',
            'email' => 'candidate@example.test',
            'mobile' => '9876543210',
        ];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->browser('POST', '/api/v1/candidate/auth/request-registration-otp', $payload)->assertStatus(202);
        }

        $this->browser('POST', '/api/v1/candidate/auth/request-registration-otp', $payload)->assertTooManyRequests();
    }

    public function test_candidate_rate_limiters_have_required_scopes_and_caps(): void
    {
        $otpLimiter = RateLimiter::limiter('candidate-otp');
        $this->assertNotNull($otpLimiter);
        $otpRequest = Request::create('/api/v1/candidate/auth/request-registration-otp', 'POST', [
            'mobile' => '9876543210',
        ], [], [], [
            'REMOTE_ADDR' => '192.0.2.1',
        ]);
        $otpLimits = $otpLimiter($otpRequest);

        $this->assertCount(2, $otpLimits);
        $this->assertSame([3, 10], array_map(static fn (Limit $limit): int => $limit->maxAttempts, $otpLimits));
        $this->assertSame([60, 86400], array_map(static fn (Limit $limit): int => $limit->decaySeconds, $otpLimits));

        $otherIpRequest = Request::create('/api/v1/candidate/auth/request-registration-otp', 'POST', [
            'mobile' => '9876543210',
        ], [], [], [
            'REMOTE_ADDR' => '192.0.2.2',
        ]);
        $otherIpLimits = $otpLimiter($otherIpRequest);
        $this->assertNotSame($otpLimits[0]->key, $otherIpLimits[0]->key);
        $this->assertSame($otpLimits[1]->key, $otherIpLimits[1]->key);

        $otherPhoneRequest = Request::create('/api/v1/candidate/auth/request-registration-otp', 'POST', [
            'mobile' => '9123456780',
        ], [], [], [
            'REMOTE_ADDR' => '192.0.2.1',
        ]);
        $otherPhoneLimits = $otpLimiter($otherPhoneRequest);
        $this->assertNotSame($otpLimits[0]->key, $otherPhoneLimits[0]->key);
        $this->assertNotSame($otpLimits[1]->key, $otherPhoneLimits[1]->key);

        $candidate = new CandidateUser;
        $candidate->f_uid = 123;
        $request = Request::create('/api/v1/candidate/application/submit', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.1',
        ]);
        $request->setUserResolver(fn (?string $guard = null): CandidateUser => $candidate);

        foreach ([
            'candidate-submit' => 3,
            'candidate-payments' => 5,
        ] as $limiterName => $maxAttempts) {
            $limiter = RateLimiter::limiter($limiterName);
            $this->assertNotNull($limiter);

            $candidateLimit = $limiter($request);
            $this->assertInstanceOf(Limit::class, $candidateLimit);
            $this->assertSame($maxAttempts, $candidateLimit->maxAttempts);
            $this->assertSame(60, $candidateLimit->decaySeconds);

            $request->server->set('REMOTE_ADDR', '192.0.2.2');
            $this->assertSame($candidateLimit->key, $limiter($request)->key);

            $request->setUserResolver(static fn (?string $guard = null): ?CandidateUser => null);
            $ipLimit = $limiter($request);
            $request->server->set('REMOTE_ADDR', '192.0.2.3');
            $this->assertNotSame($ipLimit->key, $limiter($request)->key);

            $request->setUserResolver(fn (?string $guard = null): CandidateUser => $candidate);
            $request->server->set('REMOTE_ADDR', '192.0.2.1');
        }
    }
}
