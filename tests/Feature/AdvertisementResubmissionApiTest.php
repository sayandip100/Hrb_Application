<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use App\Mail\ResubmissionInvitation;
use App\Models\Advertisement;
use App\Models\ResubmissionCandidate;
use Database\Factories\AdvertisementFactory;
use Database\Factories\AdvertisementResubmissionFactory;
use Database\Factories\DisciplineFactory;
use Database\Factories\RecruitmentFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class AdvertisementResubmissionApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/advertisement-resubmissions';

    private function setupAdvertisement(): array
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Asia/Kolkata'));
        $admin = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($admin, 'staff');
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $advertisement = AdvertisementFactory::new()->create(['adv_recruit_master' => $recruitment->rm_id, 'adv_createby' => $admin->u_id]);
        $discipline = DisciplineFactory::new()->create(['catm_master' => $recruitment->rm_id, 'catm_createby' => $admin->u_id]);
        $post = DB::table('advertisement_categoty')->insertGetId([
            'acat_adv_master' => $advertisement->adv_auto_genno, 'acat_name' => $discipline->catm_id,
            'acat_gender_set' => 'All', 'acat_marital_set' => 'All', 'acat_createdate' => '2026-11-06 12:00:00',
        ], 'acat_id');

        return [$advertisement, $post, $admin, $recruitment];
    }

    private function payload(Advertisement $advertisement, int $post): array
    {
        return ['advertisement_id' => $this->publicId('advertisement', $advertisement->adv_id),
            'post_id' => $this->publicId('advertisement-post', $post),
            'starts_at' => '2026-11-06 12:01:00', 'ends_at' => '2026-11-06 13:00:00', 'reason' => 'Correct rejected documents.'];
    }

    private function candidate(Advertisement $advertisement, int $post, array $details = [], array $check = [], string $approval = 'Rejected'): array
    {
        $application = fake()->unique()->numerify('APP##########');
        $uid = DB::table('frontend_users')->insertGetId([
            'f_applied_for' => $advertisement->adv_auto_genno, 'f_application_no' => $application,
            'f_mobile' => '9000000000', 'f_full_name' => 'Test Candidate', 'f_email' => 'candidate@example.test',
            'f_password' => 'do-not-copy', 'f_otp' => '123456', 'f_registered' => 1, 'f_status' => 1,
            'f_createdate' => '2026-10-01 10:00:00',
        ], 'f_uid');
        DB::table('f_user_details')->insert(array_merge(['fu_master' => $uid, 'fu_category' => $post,
            'fu_step_1' => 1, 'fu_step_2' => 1, 'fu_step_3' => 1, 'fu_step_4' => 1,
            'fu_final_submit' => 1, 'fu_payment_stat' => 1, 'fu_cancel_stat' => 0], $details));
        $result = DB::table('candidate_result_tab')->insertGetId(['cr_application_master' => $application,
            'cr_approval' => $approval, 'cr_reject_comments' => 'Needs correction', 'fu_dob_check' => 'Rejected'], 'cr_id');
        $checking = DB::table('checking_tab')->insertGetId(array_merge(['chk_user_application' => $application,
            'chk_type' => 'fu_dob', 'chk_approve' => 'Rejected', 'chk2_approve' => 'Rejected', 'chk_final_state' => 'Rejected',
            'chk_createdate' => '2026-11-01 10:00:00', 'chk_create_by_type' => 2], $check), 'chk_id');

        return ['uid' => $uid, 'application' => $application, 'result' => $result, 'check' => $checking];
    }

    private function createWindow(Advertisement $advertisement, int $post): array
    {
        return $this->postJson(self::ENDPOINT, $this->payload($advertisement, $post))->assertCreated()->json('data');
    }

    private function logCorrection(Advertisement $advertisement, array $candidate, string $at = '2026-11-06 12:30:00'): void
    {
        DB::table('f_resubmit_log')->insert(['fresub_cand_advno' => $advertisement->adv_auto_genno,
            'fresub_cand_regno' => $candidate['application'], 'fresub_createdate' => $at]);
    }

    #[TestWith(['GET', ''])]
    #[TestWith(['GET', '/form-options'])]
    #[TestWith(['POST', '/preview'])]
    #[TestWith(['POST', ''])]
    #[TestWith(['GET', '/invalid'])]
    #[TestWith(['POST', '/invalid/process'])]
    public function test_requires_authentication_401(string $method, string $path): void
    {
        $this->json($method, self::ENDPOINT.$path)->assertUnauthorized();
    }

    #[TestWith(['GET', ''])]
    #[TestWith(['GET', '/form-options'])]
    #[TestWith(['POST', '/preview'])]
    #[TestWith(['POST', ''])]
    public function test_requires_super_admin_403(string $method, string $path): void
    {
        $staff = StaffUserFactory::new()->create();
        $this->actingAs($staff, 'staff')->json($method, self::ENDPOINT.$path)->assertForbidden();
    }

    public function test_form_options_scope_advertisements_and_posts_and_encrypt_ids(): void
    {
        [$advertisement, $post, , $recruitment] = $this->setupAdvertisement();
        $this->getJson(self::ENDPOINT.'/form-options')->assertOk()->assertJsonCount(1, 'data.recruitments')
            ->assertJsonCount(0, 'data.advertisements')->assertJsonCount(0, 'data.posts');
        $this->getJson(self::ENDPOINT.'/form-options?'.http_build_query([
            'recruitment_id' => $this->publicId('recruitment', $recruitment->rm_id),
            'advertisement_id' => $this->publicId('advertisement', $advertisement->adv_id),
        ]))->assertOk()->assertJsonCount(1, 'data.advertisements')->assertJsonCount(1, 'data.posts')
            ->assertJsonPath('data.posts.0.id', $this->matchesPublicId('advertisement-post', $post));
    }

    public function test_preview_counts_only_complete_paid_active_candidates_with_final_rejected_sections(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $candidate = $this->candidate($advertisement, $post, [], [], 'Approved');
        DB::table('checking_tab')->insert(['chk_user_application' => $candidate['application'], 'chk_type' => 'fu_address',
            'chk2_approve' => 'Rejected', 'chk_final_state' => 'Rejected', 'chk_createdate' => '2026-11-01 10:00:00', 'chk_create_by_type' => 2]);
        $this->candidate($advertisement, $post, ['fu_payment_stat' => 0]);
        $this->candidate($advertisement, $post, ['fu_final_submit' => 0]);
        $this->candidate($advertisement, $post, ['fu_cancel_stat' => 1]);
        $this->candidate($advertisement, $post, [], ['chk_final_state' => 'Approved']);
        $this->candidate($advertisement, $post, [], [], 'NotChecked');
        $this->candidate($advertisement, $post + 999);
        $this->postJson(self::ENDPOINT.'/preview', $this->payload($advertisement, $post))->assertOk()->assertExactJson(['data' => ['eligible_candidates' => 1]]);
        $this->assertDatabaseCount('advertisement_resubmit_tab', 0);
    }

    public function test_create_snapshots_only_eligible_candidates_and_does_not_send_email_or_expose_secrets(): void
    {
        Mail::fake();
        [$advertisement, $post, $admin] = $this->setupAdvertisement();
        $candidate = $this->candidate($advertisement, $post);
        $this->candidate($advertisement, $post + 1);
        $window = $this->createWindow($advertisement, $post);
        $id = PublicId::decode('advertisement-resubmission', $window['id']);
        $this->assertSame(1, $window['eligible_candidates']);
        $this->assertSame('scheduled', $window['state']);
        $this->assertSame(1, $window['notifications']['pending']);
        $this->assertTrue($window['is_managed']);
        $this->assertFalse($window['can_process']);
        $this->assertArrayNotHasKey('snapshot', $window);
        $this->assertSame($admin->u_id, PublicId::decode('staff', $window['created_by']));
        $snapshot = ResubmissionCandidate::query()->where('resubmission_id', $id)->firstOrFail();
        $this->assertSame($candidate['application'], $snapshot->application_no);
        $this->assertArrayNotHasKey('f_password', $snapshot->snapshot['frontend_users'][0]);
        $this->assertArrayNotHasKey('f_otp', $snapshot->snapshot['frontend_users'][0]);
        $this->assertSame('Rejected', $snapshot->snapshot['candidate_result_tab'][0]['cr_approval']);
        $this->assertDatabaseCount('advertisement_resubmission_candidates', 1);
        $this->assertDatabaseHas('candidate_result_tab', ['cr_id' => $candidate['result'], 'cr_approval' => 'Rejected']);
        Mail::assertNothingSent();
    }

    public function test_create_without_eligible_candidates_rolls_back_the_whole_window_422(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->postJson(self::ENDPOINT, $this->payload($advertisement, $post))->assertUnprocessable()->assertJsonValidationErrors('post_id');
        $this->assertDatabaseCount('advertisement_resubmit_tab', 0);
        $this->assertDatabaseCount('advertisement_resubmission_batches', 0);
    }

    #[TestWith(['starts_at', 'invalid'])]
    #[TestWith(['ends_at', '2026-11-06 12:00:00'])]
    #[TestWith(['ends_at', '2026-11-06 12:01:00'])]
    #[TestWith(['advertisement_id', '145'])]
    #[TestWith(['post_id', '1'])]
    #[TestWith(['adv_resub_status', 1])]
    public function test_rejects_invalid_creation_fields_422(string $field, mixed $value): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $payload = $this->payload($advertisement, $post);
        $payload[$field] = $value;
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_rejects_an_already_expired_window_422(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $payload = $this->payload($advertisement, $post);
        $payload['starts_at'] = '2026-11-05 10:00:00';
        $payload['ends_at'] = '2026-11-05 12:00:00';
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_an_unrelated_post_and_locked_advertisement_422(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $payload = $this->payload($advertisement, $post);
        $payload['post_id'] = $this->publicId('advertisement-post', $post + 999);
        $this->postJson(self::ENDPOINT.'/preview', $payload)->assertUnprocessable()->assertJsonValidationErrors('post_id');
        $advertisement->adv_status = 0;
        $advertisement->save();
        $this->postJson(self::ENDPOINT.'/preview', $this->payload($advertisement, $post))->assertUnprocessable()->assertJsonValidationErrors('advertisement_id');
    }

    public function test_rejects_overlapping_or_duplicate_windows_409(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $this->createWindow($advertisement, $post);
        $this->postJson(self::ENDPOINT, $this->payload($advertisement, $post))->assertConflict();
        $this->assertDatabaseCount('advertisement_resubmit_tab', 1);
        $this->assertDatabaseCount('advertisement_resubmission_candidates', 1);
    }

    public function test_allows_non_overlapping_windows_with_separate_snapshots(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $this->createWindow($advertisement, $post);
        $payload = $this->payload($advertisement, $post);
        $payload['starts_at'] = '2026-11-06 13:00:01';
        $payload['ends_at'] = '2026-11-06 14:00:00';
        $this->postJson(self::ENDPOINT, $payload)->assertCreated();
        $this->assertDatabaseCount('advertisement_resubmission_candidates', 2);
    }

    public function test_process_only_corrected_snapshot_candidates_and_is_idempotent(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $corrected = $this->candidate($advertisement, $post);
        $unchanged = $this->candidate($advertisement, $post);
        $outside = $this->candidate($advertisement, $post);
        $otherPost = $this->candidate($advertisement, $post + 1);
        $window = $this->createWindow($advertisement, $post);
        $later = $this->candidate($advertisement, $post);
        $this->logCorrection($advertisement, $corrected);
        $this->logCorrection($advertisement, $corrected);
        $this->logCorrection($advertisement, $outside, '2026-11-06 13:00:01');
        $this->logCorrection($advertisement, $otherPost);
        $this->logCorrection($advertisement, $later);
        $approved = DB::table('checking_tab')->insertGetId(['chk_user_application' => $corrected['application'], 'chk_type' => 'fu_address',
            'chk2_approve' => 'Approved', 'chk_final_state' => 'Approved', 'chk_createdate' => '2026-11-06 12:30:00', 'chk_create_by_type' => 2], 'chk_id');
        $this->travelTo(Carbon::parse('2026-11-06 13:01:00', 'Asia/Kolkata'));
        $path = self::ENDPOINT.'/'.$window['id'].'/process';
        $this->postJson($path)->assertOk()->assertJsonPath('data.state', 'processed')
            ->assertJsonPath('data.processed_candidates', 1)->assertJsonPath('data.deleted_checks', 1)->assertJsonPath('data.can_process', false);
        $this->assertDatabaseHas('candidate_result_tab', ['cr_id' => $corrected['result'], 'cr_approval' => 'NotChecked', 'cr_reject_comments' => null, 'fu_dob_check' => null]);
        $this->assertDatabaseMissing('checking_tab', ['chk_id' => $corrected['check']]);
        $this->assertDatabaseHas('checking_tab', ['chk_id' => $approved]);
        foreach ([$unchanged, $outside, $otherPost, $later] as $candidate) {
            $this->assertDatabaseHas('candidate_result_tab', ['cr_id' => $candidate['result'], 'cr_approval' => 'Rejected']);
            $this->assertDatabaseHas('checking_tab', ['chk_id' => $candidate['check']]);
        }
        $this->postJson($path)->assertOk()->assertJsonPath('data.processed_candidates', 1)->assertJsonPath('data.deleted_checks', 1);
        $snapshot = ResubmissionCandidate::query()->where('application_no', $corrected['application'])->firstOrFail();
        $this->assertSame('Rejected', $snapshot->snapshot['candidate_result_tab'][0]['cr_approval']);
    }

    public function test_process_blocks_before_and_at_deadline_409(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $window = $this->createWindow($advertisement, $post);
        $this->postJson(self::ENDPOINT.'/'.$window['id'].'/process')->assertConflict();
        $this->travelTo(Carbon::parse('2026-11-06 13:00:00', 'Asia/Kolkata'));
        $this->postJson(self::ENDPOINT.'/'.$window['id'].'/process')->assertConflict();
    }

    public function test_legacy_window_is_listed_but_not_processed_without_snapshot_409(): void
    {
        [$advertisement, $post, $admin] = $this->setupAdvertisement();
        $legacy = AdvertisementResubmissionFactory::new()->create(['adv_resub_master' => $advertisement->adv_auto_genno,
            'adv_resub_cat' => $post, 'adv_resub_createby' => $admin->u_id]);
        $id = $this->publicId('advertisement-resubmission', $legacy->getKey());
        $this->getJson(self::ENDPOINT.'/'.$id)->assertOk()->assertJsonPath('data.is_managed', false)->assertJsonPath('data.can_process', false);
        $this->postJson(self::ENDPOINT.'/'.$id.'/process')->assertConflict();
    }

    public function test_invalid_or_wrong_purpose_route_ids_return_404(): void
    {
        $this->setupAdvertisement();
        $this->getJson(self::ENDPOINT.'/145')->assertNotFound();
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('advertisement', 1))->assertNotFound();
    }

    public function test_lists_and_filters_windows_with_pagination(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $window = $this->createWindow($advertisement, $post);
        $query = http_build_query(['advertisement_id' => $this->publicId('advertisement', $advertisement->adv_id),
            'post_id' => $this->publicId('advertisement-post', $post), 'per_page' => 1]);
        $this->getJson(self::ENDPOINT.'?'.$query)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reference', $advertisement->adv_auto_genno);
        $this->getJson(self::ENDPOINT.'/'.$window['id'])->assertOk()->assertJsonPath('data.eligible_candidates', 1);
    }

    public function test_notification_command_sends_once_and_records_delivery(): void
    {
        Mail::fake();
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $window = $this->createWindow($advertisement, $post);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'admin@example.test']);
        $this->artisan('resubmissions:send-notifications', ['--limit' => 10])->assertExitCode(0);
        $this->artisan('resubmissions:send-notifications', ['--limit' => 10])->assertExitCode(0);
        Mail::assertSent(ResubmissionInvitation::class, 1);
        $this->getJson(self::ENDPOINT.'/'.$window['id'])->assertOk()->assertJsonPath('data.notifications.sent', 1)->assertJsonPath('data.notifications.pending', 0);
    }

    public function test_notification_command_skips_expired_windows(): void
    {
        Mail::fake();
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $this->createWindow($advertisement, $post);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'admin@example.test']);
        $this->travelTo(Carbon::parse('2026-11-06 13:01:00', 'Asia/Kolkata'));
        $this->artisan('resubmissions:send-notifications')->assertExitCode(0);
        $this->assertDatabaseHas('advertisement_resubmission_candidates', ['notification_status' => 'skipped']);
        Mail::assertNothingSent();
    }

    public function test_invitation_escapes_html_and_includes_exact_window(): void
    {
        $mail = new ResubmissionInvitation('TEST/1', '<script>test</script>', '2026-11-06 12:01:00', '2026-11-06 13:00:00', '<img src=x>');
        $mail->assertSeeInHtml('2026-11-06 13:00:00');
        $mail->assertSeeInHtml('<script>test</script>');
        $mail->assertDontSeeInHtml('<img src=x>', false);
    }

    #[TestWith(['GET', ''])]
    #[TestWith(['POST', '/process'])]
    public function test_bound_window_routes_reject_non_admin_403(string $method, string $suffix): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $window = $this->createWindow($advertisement, $post);
        $staff = StaffUserFactory::new()->create();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'staff')->json($method, self::ENDPOINT.'/'.$window['id'].$suffix)->assertForbidden();
    }

    public function test_rollback_preserves_candidate_data_if_processing_fails(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $candidate = $this->candidate($advertisement, $post);
        $window = $this->createWindow($advertisement, $post);
        $this->logCorrection($advertisement, $candidate);
        DB::statement("CREATE TRIGGER fail_resub_result BEFORE UPDATE ON candidate_result_tab BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->travelTo(Carbon::parse('2026-11-06 13:01:00', 'Asia/Kolkata'));
        $this->postJson(self::ENDPOINT.'/'.$window['id'].'/process')->assertStatus(500);
        $this->assertDatabaseHas('checking_tab', ['chk_id' => $candidate['check']]);
        $this->assertDatabaseHas('candidate_result_tab', ['cr_id' => $candidate['result'], 'cr_approval' => 'Rejected']);
        $this->assertDatabaseHas('advertisement_resubmit_tab', ['adv_resub_id' => PublicId::decode('advertisement-resubmission', $window['id']), 'adv_resub_chk_stat' => 0]);
        DB::statement('DROP TRIGGER fail_resub_result');
    }

    public function test_snapshot_failure_rolls_back_window_membership_and_notifications(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        DB::statement("CREATE TRIGGER fail_resub_snapshot BEFORE INSERT ON advertisement_resubmission_candidates BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->postJson(self::ENDPOINT, $this->payload($advertisement, $post))->assertStatus(500);
        $this->assertDatabaseCount('advertisement_resubmit_tab', 0);
        $this->assertDatabaseCount('advertisement_resubmission_batches', 0);
        $this->assertDatabaseCount('advertisement_resubmission_candidates', 0);
        DB::statement('DROP TRIGGER fail_resub_snapshot');
    }

    public function test_logs_before_window_creation_do_not_trigger_processing(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $candidate = $this->candidate($advertisement, $post);
        $payload = $this->payload($advertisement, $post);
        $payload['starts_at'] = '2026-11-06 10:00:00';
        $window = $this->postJson(self::ENDPOINT, $payload)->assertCreated()->json('data');
        $this->logCorrection($advertisement, $candidate, '2026-11-06 11:59:59');
        $this->travelTo(Carbon::parse('2026-11-06 13:01:00', 'Asia/Kolkata'));
        $this->postJson(self::ENDPOINT.'/'.$window['id'].'/process')->assertOk()->assertJsonPath('data.processed_candidates', 0);
        $this->assertDatabaseHas('checking_tab', ['chk_id' => $candidate['check']]);
    }

    public function test_new_rejected_checks_are_not_deleted_and_changed_approvals_are_preserved(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $candidate = $this->candidate($advertisement, $post);
        $window = $this->createWindow($advertisement, $post);
        $this->logCorrection($advertisement, $candidate);
        DB::table('checking_tab')->where('chk_id', $candidate['check'])->update(['chk2_approve' => 'Approved', 'chk_final_state' => 'Approved']);
        $newCheck = DB::table('checking_tab')->insertGetId(['chk_user_application' => $candidate['application'], 'chk_type' => 'fu_address',
            'chk2_approve' => 'Rejected', 'chk_final_state' => 'Rejected', 'chk_createdate' => '2026-11-06 12:30:00', 'chk_create_by_type' => 2], 'chk_id');
        $this->travelTo(Carbon::parse('2026-11-06 13:01:00', 'Asia/Kolkata'));
        $this->postJson(self::ENDPOINT.'/'.$window['id'].'/process')->assertOk()->assertJsonPath('data.processed_candidates', 0);
        $this->assertDatabaseHas('checking_tab', ['chk_id' => $newCheck]);
        $this->assertDatabaseHas('checking_tab', ['chk_id' => $candidate['check'], 'chk2_approve' => 'Approved']);
    }

    public function test_invalid_email_is_reported_without_excluding_eligible_candidate(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $candidate = $this->candidate($advertisement, $post);
        DB::table('frontend_users')->where('f_uid', $candidate['uid'])->update(['f_email' => 'invalid-email']);
        $window = $this->createWindow($advertisement, $post);
        $this->assertSame(1, $window['eligible_candidates']);
        $this->assertSame(1, $window['notifications']['invalid']);
        $this->assertSame(0, $window['notifications']['pending']);
    }

    public function test_notification_failures_are_recorded_and_stop_after_three_attempts(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $this->createWindow($advertisement, $post);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'admin@example.test']);
        Mail::shouldReceive('mailer')->with('smtp')->times(3)->andReturnSelf();
        Mail::shouldReceive('to')->with('candidate@example.test')->times(3)->andReturnSelf();
        Mail::shouldReceive('send')->times(3)->andThrow(new \RuntimeException('SMTP failed'));
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('resubmissions:send-notifications')->assertExitCode(1);
        }
        $this->artisan('resubmissions:send-notifications')->assertExitCode(0);
        $this->assertDatabaseHas('advertisement_resubmission_candidates', ['notification_status' => 'failed', 'attempts' => 3]);
    }

    public function test_notification_command_rejects_unconfigured_mail_and_bad_limit(): void
    {
        $this->artisan('resubmissions:send-notifications')->assertExitCode(1);
        $this->artisan('resubmissions:send-notifications', ['--limit' => 0])->assertExitCode(1);
    }

    public function test_large_candidate_snapshot_is_chunked_without_skips(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        for ($i = 0; $i < 105; $i++) {
            $this->candidate($advertisement, $post);
        }
        $window = $this->createWindow($advertisement, $post);
        $this->assertSame(105, $window['eligible_candidates']);
        $this->assertSame(105, $window['notifications']['pending']);
        $this->assertDatabaseCount('advertisement_resubmission_candidates', 105);
    }

    public function test_csrf_is_required_for_create_and_process(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $window = $this->createWindow($advertisement, $post);
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT, $this->payload($advertisement, $post))->assertStatus(419);
        $this->postJson(self::ENDPOINT.'/'.$window['id'].'/process')->assertStatus(419);
        $this->assertDatabaseCount('advertisement_resubmit_tab', 1);
    }

    public function test_form_options_rejects_advertisement_from_another_recruitment(): void
    {
        [$advertisement, , $admin] = $this->setupAdvertisement();
        $other = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'/form-options?'.http_build_query([
            'recruitment_id' => $this->publicId('recruitment', $other->rm_id),
            'advertisement_id' => $this->publicId('advertisement', $advertisement->adv_id),
        ]))->assertUnprocessable()->assertJsonValidationErrors('advertisement_id');
    }

    public function test_rejects_wrong_purpose_post_identifier(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $data = $this->payload($advertisement, $post);
        $data['post_id'] = $this->publicId('discipline', $post);
        $this->postJson(self::ENDPOINT.'/preview', $data)->assertUnprocessable()->assertJsonValidationErrors('post_id');
    }

    public function test_disabled_window_cannot_be_processed(): void
    {
        [$advertisement, $post] = $this->setupAdvertisement();
        $this->candidate($advertisement, $post);
        $window = $this->createWindow($advertisement, $post);
        DB::table('advertisement_resubmit_tab')->where('adv_resub_id', PublicId::decode('advertisement-resubmission', $window['id']))->update(['adv_resub_status' => 0]);
        $this->travelTo(Carbon::parse('2026-11-06 13:01:00', 'Asia/Kolkata'));
        $this->postJson(self::ENDPOINT.'/'.$window['id'].'/process')->assertConflict();
    }
}
