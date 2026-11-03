<?php

namespace Tests\Feature;

use App\Domain\Identity\OtpSender;
use App\Http\Middleware\EnsureActiveStaff;
use App\Http\Middleware\EnsureStaffBrowserSession;
use App\Http\Middleware\RequireStaffRole;
use App\Mail\StaffLoginOtp;
use App\Models\StaffUser;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fakes\FakeOtpSender;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class StaffAuthenticationTest extends StaffAuthTestCase
{
    private const PREFIX = '/api/v1/staff/auth';

    private function fakeEmail(): FakeOtpSender
    {
        $fake = new FakeOtpSender;
        $this->app->instance(OtpSender::class, $fake);

        return $fake;
    }

    private function issue(StaffUser $staff): TestResponse
    {
        return $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => $staff->mobile, 'role_id' => $staff->u_type]);
    }

    private function verify(string $challenge, string $otp): TestResponse
    {
        return $this->browser('POST', self::PREFIX.'/login', ['challenge_id' => $challenge, 'otp' => $otp]);
    }

    private function login(StaffUser $staff, FakeOtpSender $sender): void
    {
        $challenge = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $this->verify($challenge, $sender->lastOtp())->assertOk();
    }

    public function test_email_sender_delivers_to_the_registered_address_and_the_code_logs_in(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'hrb@example.test']);
        Mail::fake();
        $staff = StaffUserFactory::new()->create(['email' => 'registered@example.test']);
        $response = $this->issue($staff)->assertAccepted();
        Mail::assertSent(StaffLoginOtp::class, function (StaffLoginOtp $mail) use ($staff): bool {
            return $mail->hasTo($staff->email) && preg_match('/^[0-9]{6}$/', $mail->otp) === 1;
        });
        $mail = Mail::sent(StaffLoginOtp::class)->sole();
        $this->assertStringNotContainsString($mail->otp, $response->getContent());
        $this->verify($response->json('data.challenge_id'), $mail->otp)->assertOk();
        $this->assertAuthenticatedAs($staff, 'staff');
    }

    public function test_login_email_matches_the_admin_login_subject_and_body(): void
    {
        $mail = new StaffLoginOtp('456789', 300);
        $mail->assertSeeInText('456789');
        $mail->assertSeeInText('Welcome to ADMIN portal of WBHRB website.');
        $mail->assertSeeInHtml('Welcome to ADMIN portal of WBHRB website.');
        $mail->assertSeeInHtml('Your ONE TIME PASSWORD IS - 456789');
        $mail->assertHasSubject('WBHRB - Admin Login');
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['invalid-address'])]
    public function test_missing_or_invalid_registered_email_does_not_receive_an_otp(?string $email): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create(['email' => $email]);
        $response = $this->issue($staff)->assertAccepted();
        $this->assertSame([], $sender->messages);
        $this->verify($response->json('data.challenge_id'), '123456')->assertUnprocessable();
        $this->assertGuest('staff');
    }

    public function test_client_cannot_override_the_registered_email(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $this->browser('POST', self::PREFIX.'/request-otp', [
            'mobile' => $staff->mobile, 'role_id' => $staff->u_type, 'email' => 'other@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame([], $sender->messages);
    }

    public function test_changing_registered_email_invalidates_an_issued_code(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $challenge = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $staff->email = 'changed@example.test';
        $staff->save();
        $this->verify($challenge, $sender->lastOtp())->assertUnprocessable();
        $this->assertGuest('staff');
    }

    public function test_real_application_fails_closed_without_a_configured_email_sender(): void
    {
        $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => '9999999999', 'role_id' => 2])
            ->assertStatus(503)->assertJsonPath('message', 'Staff OTP email delivery is not configured yet.');
    }

    public function test_otp_creates_a_staff_session_and_returns_only_selected_account_fields(): void
    {
        $this->freezeTime();
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $response = $this->issue($staff)->assertAccepted();
        $this->assertCount(1, $sender->messages);
        $this->assertSame($staff->email, $sender->messages[0]['email']);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $sender->lastOtp());
        $this->assertArrayNotHasKey('otp', $response->json('data'));
        $this->assertStringNotContainsString('"otp"', $response->getContent());

        $before = $this->app['session']->getId();
        $this->verify($response->json('data.challenge_id'), $sender->lastOtp())
            ->assertOk()->assertExactJson(['data' => [
                'id' => $staff->u_id, 'name' => 'Test Staff', 'mobile' => $staff->mobile,
                'role' => ['id' => 2, 'name' => 'Checker-LVL-1'],
            ]]);
        $this->assertNotSame($before, $this->app['session']->getId());
        $this->browser('GET', route('staff.auth.me', [], false))->assertOk()->assertJsonPath('data.id', $staff->u_id);
        $this->assertDatabaseCount('user_info', 1);
        $this->assertDatabaseHas('user_info', ['u_id' => $staff->u_id, 'otp_verify' => null]);
    }

    public function test_unknown_account_has_the_same_public_challenge_shape_without_sending_email(): void
    {
        $sender = $this->fakeEmail();
        $response = $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => '9999999999', 'role_id' => 2])
            ->assertAccepted()->assertJsonPath('data.expires_in', 300)
            ->assertJsonPath('data.message', 'If the account is eligible, a verification code will be sent.');
        $this->assertTrue(Str::isUuid($response->json('data.challenge_id')));
        $this->assertSame([], $sender->messages);
        $this->verify($response->json('data.challenge_id'), '123456')->assertUnprocessable()->assertJsonValidationErrors('otp');
    }

    public function test_inactive_staff_do_not_receive_an_otp(): void
    {
        $sender = $this->fakeEmail();
        $this->issue(StaffUserFactory::new()->inactive()->create())->assertAccepted();
        $this->assertSame([], $sender->messages);
    }

    public function test_inactive_roles_do_not_receive_an_otp(): void
    {
        $sender = $this->fakeEmail();
        $role = StaffRoleFactory::new()->inactive()->create();
        $this->issue(StaffUserFactory::new()->for($role, 'role')->create())->assertAccepted();
        $this->assertSame([], $sender->messages);
    }

    public function test_duplicate_active_accounts_are_not_selected_arbitrarily(): void
    {
        $sender = $this->fakeEmail();
        $role = StaffRoleFactory::new()->create();
        $staff = StaffUserFactory::new()->for($role, 'role')->create();
        StaffUserFactory::new()->for($role, 'role')->create(['mobile' => $staff->mobile]);
        $this->issue($staff)->assertAccepted();
        $this->assertSame([], $sender->messages);
    }

    public function test_role_selection_cannot_authenticate_an_account_in_another_role(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => $staff->mobile, 'role_id' => 1])->assertAccepted();
        $this->assertSame([], $sender->messages);
    }

    public function test_resending_during_the_cooldown_is_rejected_with_429(): void
    {
        $this->freezeTime();
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $this->issue($staff)->assertAccepted();
        $this->issue($staff)->assertTooManyRequests();
        $this->assertCount(1, $sender->messages);
    }

    public function test_resend_invalidates_the_previous_challenge(): void
    {
        $this->freezeTime();
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $old = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $oldOtp = $sender->lastOtp();
        $this->travel(61)->seconds();
        $new = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $this->verify($old, $oldOtp)->assertUnprocessable();
        $this->verify($new, $sender->lastOtp())->assertOk();
        $this->assertCount(2, $sender->messages);
    }

    public function test_expired_otp_is_rejected_with_422(): void
    {
        $this->freezeTime();
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $challenge = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $this->travel(301)->seconds();
        $this->verify($challenge, $sender->lastOtp())->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->assertGuest('staff');
    }

    public function test_five_wrong_attempts_invalidate_even_the_correct_otp(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $challenge = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $wrong = $sender->lastOtp() === '000000' ? '111111' : '000000';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->verify($challenge, $wrong)->assertUnprocessable();
        }
        $this->verify($challenge, $sender->lastOtp())->assertUnprocessable();
        $this->assertGuest('staff');
    }

    public function test_an_otp_cannot_be_replayed_after_logout(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $challenge = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $otp = $sender->lastOtp();
        $this->verify($challenge, $otp)->assertOk();
        $this->browser('POST', self::PREFIX.'/logout')->assertOk()->assertExactJson(['message' => 'Logout successful.']);
        $this->verify($challenge, $otp)->assertUnprocessable();
        $this->browser('GET', route('staff.auth.me', [], false))->assertUnauthorized();
    }

    public function test_otp_challenge_is_bound_to_the_requesting_browser_session(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $challenge = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $this->unencryptedCookies = [];
        $this->verify($challenge, $sender->lastOtp())->assertUnprocessable();
        $this->assertGuest('staff');
    }

    public function test_account_disabled_after_otp_request_cannot_sign_in(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $challenge = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $staff->user_status = 0;
        $staff->save();
        $this->verify($challenge, $sender->lastOtp())->assertUnprocessable();
        $this->assertGuest('staff');
    }

    public function test_disabled_role_invalidates_an_existing_session(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $this->login($staff, $sender);
        $staff->role()->update(['mu_status' => 0]);
        $this->browser('GET', route('staff.auth.me', [], false))->assertForbidden();
        $this->browser('GET', route('staff.auth.me', [], false))->assertUnauthorized();
    }

    #[TestWith(['*/*'])]
    #[TestWith(['text/html'])]
    public function test_unauthenticated_api_returns_json_401_without_requesting_json(string $accept): void
    {
        $this->get(route('staff.auth.me', [], false), ['Accept' => $accept])
            ->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unauthenticated_staff_profile_is_rejected_with_401(): void
    {
        $this->browser('GET', route('staff.auth.me', [], false))->assertUnauthorized();
    }

    public function test_access_window_and_ip_are_enforced_for_non_super_admins(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->expiredAccess()->create();
        $this->issue($staff)->assertAccepted();
        $this->assertSame([], $sender->messages);
    }

    public function test_wrong_ip_does_not_receive_an_otp(): void
    {
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create(['user_mac_address' => '192.0.2.20']);
        $this->issue($staff)->assertAccepted();
        $this->assertSame([], $sender->messages);
    }

    public function test_super_admin_preserves_the_legacy_access_window_exception(): void
    {
        $sender = $this->fakeEmail();
        $role = StaffRoleFactory::new()->superAdmin()->create();
        $staff = StaffUserFactory::new()->for($role, 'role')->unconfiguredAccess()->create();
        $this->login($staff, $sender);
        $this->assertCount(1, $sender->messages);
    }

    public function test_role_middleware_denies_a_checker_access_to_an_admin_only_operation(): void
    {
        Route::middleware(['api', EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
            ->get('/api/testing/admin-operation', fn () => response()->json(['allowed' => true]));
        $sender = $this->fakeEmail();
        $staff = StaffUserFactory::new()->create();
        $this->login($staff, $sender);
        $this->browser('GET', '/api/testing/admin-operation')->assertForbidden();
    }

    public function test_otp_verification_does_not_accept_role_or_account_overrides(): void
    {
        $this->browser('POST', self::PREFIX.'/login', [
            'challenge_id' => (string) Str::uuid(), 'otp' => '123456', 'role_id' => 1, 'user_id' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role_id', 'user_id']);
    }

    public function test_missing_otp_request_fields_are_rejected_with_422(): void
    {
        $this->browser('POST', self::PREFIX.'/request-otp')->assertUnprocessable()->assertJsonValidationErrors(['mobile', 'role_id']);
    }

    #[TestWith(['abc'])]
    #[TestWith(['123'])]
    #[TestWith(['9999999999 OR 1=1'])]
    public function test_invalid_mobile_input_is_rejected_with_422(string $mobile): void
    {
        $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => $mobile, 'role_id' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('mobile');
    }

    public function test_ip_rate_limit_blocks_excess_requests_with_429(): void
    {
        $sender = $this->fakeEmail();
        for ($index = 0; $index < 10; $index++) {
            $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => '99999999'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'role_id' => 2])->assertAccepted();
        }
        $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => '8888888888', 'role_id' => 2])->assertTooManyRequests();
        $this->assertSame([], $sender->messages);
    }

    public function test_csrf_is_required_on_stateful_otp_requests(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => '9999999999', 'role_id' => 2])->assertStatus(419);
    }

    public function test_valid_csrf_token_reaches_the_otp_endpoint(): void
    {
        $sender = $this->fakeEmail();
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->withHeader('X-CSRF-TOKEN', $this->app['session']->token());
        $this->browser('POST', self::PREFIX.'/request-otp', ['mobile' => '9999999999', 'role_id' => 2])->assertAccepted();
        $this->assertSame([], $sender->messages);
    }

    public function test_delivery_failure_does_not_leave_a_verifiable_challenge(): void
    {
        $sender = $this->fakeEmail();
        $sender->failDelivery = true;
        $staff = StaffUserFactory::new()->create();
        $challenge = $this->issue($staff)->assertAccepted()->json('data.challenge_id');
        $this->verify($challenge, '123456')->assertUnprocessable();
        $this->assertSame([], $sender->messages);
    }
}
