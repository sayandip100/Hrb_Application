<?php

namespace Tests\Feature;

use App\Mail\CandidateRegistrationOtp;
use App\Models\Advertisement;
use App\Models\CandidateApplication;
use App\Models\CandidateUser;
use App\Models\StaffUser;
use Database\Factories\AdvertisementFactory;
use Database\Factories\AgeRelaxationFactory;
use Database\Factories\CandidateApplicationFactory;
use Database\Factories\CandidateDocumentFactory;
use Database\Factories\CandidateUserFactory;
use Database\Factories\DisciplineFactory;
use Database\Factories\ExperienceFactory;
use Database\Factories\QualificationFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StaffAuthTestCase;

class CandidateApplicationApiTest extends StaffAuthTestCase
{
    private const ROOT = '/api/v1/candidate';

    private function ad(array $attributes = []): Advertisement
    {
        $this->travelTo(Carbon::parse('2027-01-10 12:00:00', 'Asia/Kolkata'));
        config(['candidate_auth.cache_store' => 'array']);

        return AdvertisementFactory::new()->create(array_merge(['adv_activated' => 1, 'adv_dictation_set' => 'No', 'adv_fees' => 0], $attributes));
    }

    private function candidate(Advertisement $ad): array
    {
        $candidate = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        $application = CandidateApplicationFactory::new()->create(['candidate_uid' => $candidate->f_uid]);
        $this->actingAs($candidate, 'candidate');

        return [$candidate, $application];
    }

    private function doc(CandidateUser $candidate, string $purpose): string
    {
        $document = CandidateDocumentFactory::new()->create(['candidate_uid' => $candidate->f_uid, 'purpose' => $purpose]);
        Storage::disk('local')->put($document->path, 'test document');

        return $this->publicId('candidate-document', $document->id);
    }

    private function complete(Advertisement $ad, CandidateUser $candidate, CandidateApplication $application): array
    {
        Storage::fake('local');
        AgeRelaxationFactory::new()->create(['caste_id' => 1, 'caste_name' => 'UR', 'caste_cat' => 1, 'caste_createby' => $ad->adv_createby]);
        $discipline = DisciplineFactory::new()->create(['catm_master' => $ad->adv_recruit_master, 'catm_createby' => $ad->adv_createby]);
        $post = DB::table('advertisement_categoty')->insertGetId(['acat_adv_master' => $ad->adv_auto_genno, 'acat_name' => $discipline->catm_id,
            'acat_gender_set' => 'All', 'acat_marital_set' => 'All', 'acat_createdate' => '2027-01-01 10:00:00'], 'acat_id');
        $qualification = QualificationFactory::new()->create(['qm_createby' => $ad->adv_createby]);
        $option = DB::table('advertisement_qualification')->insertGetId(['aquali_adv_master' => $ad->adv_auto_genno, 'aquali_exam' => $qualification->qm_id,
            'aquali_examtype' => 'Essential', 'aquali_marks' => 40, 'aquali_relation' => 'END', 'aquali_category' => 'Full', 'aquali_attempt' => 'No',
            'aquali_createdate' => '2027-01-01 10:00:00'], 'aquali_id');
        $payload = ['post' => ['post_id' => $this->publicId('advertisement-post', $post)],
            'personal-details' => ['dob' => '1990-01-01', 'gender' => 'Female', 'marital_status' => 'Unmarried', 'father_name' => 'Father', 'mother_name' => 'Mother',
                'present_address' => 'Test address', 'permanent_address' => 'Permanent address', 'present_pincode' => '700001', 'permanent_pincode' => '700001', 'read_write_bengali' => 'Yes',
                'photo_document_id' => $this->doc($candidate, 'photo'), 'signature_document_id' => $this->doc($candidate, 'signature'),
                'address_document_id' => $this->doc($candidate, 'address'), 'dob_document_id' => $this->doc($candidate, 'dob')],
            'category-relaxations' => ['caste_id' => $this->publicId('age-relaxation', 1), 'pwd' => 'No', 'exempted' => 'No', 'exservice' => 'No', 'ews' => 'No', 'age_relaxations' => []],
            'qualifications' => ['items' => [['option_id' => $this->publicId('advertisement-qualification', $option), 'board' => 'Test University', 'full_marks' => 100,
                'marks_obtained' => 80, 'issue_date' => '2020-01-01', 'pursuing' => 'No', 'attempts' => 1, 'document_id' => $this->doc($candidate, 'qualification')]]],
            'experiences' => ['items' => []]];
        $application->payload = $payload;
        $application->save();

        return $payload;
    }

    public function test_public_list_only_contains_open_published_advertisements(): void
    {
        $ad = $this->ad();
        AdvertisementFactory::new()->create(['adv_recruit_master' => $ad->adv_recruit_master, 'adv_createby' => $ad->adv_createby, 'adv_activated' => 0]);
        $this->getJson('/api/v1/public/advertisements')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->matchesPublicId('advertisement', $ad->adv_id))->assertJsonPath('data.0.can_apply', true)
            ->assertJsonMissingPath('data.0.adv_createby');
    }

    public function test_public_details_do_not_expose_private_document_path(): void
    {
        $ad = $this->ad();
        $this->getJson('/api/v1/public/advertisements/'.$this->publicId('advertisement', $ad->adv_id))->assertOk()
            ->assertJsonStructure(['data' => ['options' => ['posts', 'qualifications', 'experiences', 'castes']]])->assertJsonMissingPath('data.adv_source_doc');
        $this->getJson('/api/v1/public/advertisements/145')->assertNotFound();
    }

    public function test_registration_verifies_email_and_logs_in_without_exposing_otp_or_password(): void
    {
        $ad = $this->ad();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        $challenge = $this->browser('POST', self::ROOT.'/auth/request-registration-otp', ['advertisement_id' => $this->publicId('advertisement', $ad->adv_id),
            'name' => 'Candidate Name', 'email' => 'candidate@example.test', 'mobile' => '9876543210'])->assertStatus(202)->assertJsonMissingPath('data.otp')->json('data.challenge_id');
        $otp = null;
        Mail::assertSent(CandidateRegistrationOtp::class, function ($mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });
        $this->browser('POST', self::ROOT.'/auth/register', ['challenge_id' => $challenge, 'otp' => $otp, 'password' => 'Candidate123!', 'password_confirmation' => 'Candidate123!'])
            ->assertCreated()->assertJsonPath('data.name', 'Candidate Name')->assertJsonMissingPath('data.f_password');
        $this->assertDatabaseCount('frontend_users', 1);
        $this->assertDatabaseCount('f_user_details', 1);
        $this->assertDatabaseCount('candidate_result_tab', 1);
        $this->assertDatabaseHas('candidate_applications', ['state' => 'draft', 'version' => 1]);
        $this->browser('GET', self::ROOT.'/auth/me')->assertOk()->assertJsonPath('data.mobile', '9876543210');
    }

    public function test_wrong_otp_and_expired_otp_are_rejected(): void
    {
        $ad = $this->ad();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        $challenge = $this->browser('POST', self::ROOT.'/auth/request-registration-otp', ['advertisement_id' => $this->publicId('advertisement', $ad->adv_id),
            'name' => 'Candidate', 'email' => 'candidate@example.test', 'mobile' => '9876543210'])->assertStatus(202)->json('data.challenge_id');
        $data = ['challenge_id' => $challenge, 'otp' => 'ABCDEF', 'password' => 'Candidate123!', 'password_confirmation' => 'Candidate123!'];
        $this->browser('POST', self::ROOT.'/auth/register', $data)->assertUnprocessable();
        $this->travel(6)->minutes();
        $data['otp'] = '123456';
        $this->browser('POST', self::ROOT.'/auth/register', $data)->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->assertDatabaseCount('frontend_users', 0);
    }

    public function test_login_requires_candidate_credentials_and_existing_application_advertisement(): void
    {
        $ad = $this->ad();
        $candidate = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        $data = ['advertisement_id' => $this->publicId('advertisement', $ad->adv_id), 'mobile' => $candidate->f_mobile, 'password' => 'wrong'];
        $this->browser('POST', self::ROOT.'/auth/login', $data)->assertUnprocessable();
        $data['password'] = 'Candidate123!';
        $this->browser('POST', self::ROOT.'/auth/login', $data)->assertOk()->assertJsonPath('data.mobile', $candidate->f_mobile);
        $this->browser('POST', self::ROOT.'/auth/logout')->assertOk();
        $this->browser('GET', self::ROOT.'/auth/me')->assertUnauthorized();
    }

    public function test_staff_session_cannot_access_candidate_application(): void
    {
        $ad = $this->ad();
        $staff = StaffUser::query()->findOrFail($ad->adv_createby);
        $this->actingAs($staff, 'staff')->getJson(self::ROOT.'/application')->assertUnauthorized();
    }

    public function test_partial_save_increments_version_and_rejects_stale_or_extra_fields(): void
    {
        $ad = $this->ad();
        [$candidate,$application] = $this->candidate($ad);
        $this->patchJson(self::ROOT.'/application/personal-details', ['version' => 1, 'data' => ['father_name' => 'Test Father']])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.details.personal-details.father_name', 'Test Father');
        $this->patchJson(self::ROOT.'/application/personal-details', ['version' => 1, 'data' => ['mother_name' => 'Test Mother']])->assertConflict();
        $this->patchJson(self::ROOT.'/application/personal-details', ['version' => 2, 'data' => ['fu_payment_stat' => 1]])->assertUnprocessable();
        $this->getJson(self::ROOT.'/application/preview')->assertOk()->assertJsonPath('data.can_submit', false);
    }

    public function test_closed_advertisement_cannot_be_edited_or_registered(): void
    {
        $ad = $this->ad(['adv_end_time' => '2027-01-09 18:00:00']);
        [$candidate] = $this->candidate($ad);
        $this->patchJson(self::ROOT.'/application/personal-details', ['version' => 1, 'data' => ['father_name' => 'Test']])->assertConflict();
        $this->getJson('/api/v1/public/advertisements')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_zero_fee_submission_writes_legacy_tables_and_is_idempotent(): void
    {
        $ad = $this->ad();
        [$candidate,$application] = $this->candidate($ad);
        $this->complete($ad, $candidate, $application);
        $this->getJson(self::ROOT.'/application/preview')->assertOk()->assertJsonPath('data.can_submit', true);
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertOk()->assertJsonPath('data.state', 'complete');
        $this->assertDatabaseHas('f_user_details', ['fu_master' => $candidate->f_uid, 'fu_final_submit' => 1, 'fu_payment_stat' => 1, 'fu_step_4' => 1]);
        $this->assertDatabaseCount('f_user_qualification', 1);
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertOk()->assertJsonPath('data.state', 'complete');
        $this->assertDatabaseCount('f_user_qualification', 1);
        $this->getJson(self::ROOT.'/application/receipt')->assertOk()->assertJsonPath('data.application_number', $candidate->f_application_no);
        $this->patchJson(self::ROOT.'/application/personal-details', ['version' => 2, 'data' => ['father_name' => 'Changed']])->assertConflict();
    }

    public function test_payable_submission_remains_unpaid_and_gateway_is_not_simulated(): void
    {
        $ad = $this->ad(['adv_fees' => 200]);
        [$candidate,$application] = $this->candidate($ad);
        $this->complete($ad, $candidate, $application);
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertOk()->assertJsonPath('data.state', 'payment_pending')->assertJsonPath('data.fee', '200.00');
        $this->assertDatabaseHas('f_user_details', ['fu_master' => $candidate->f_uid, 'fu_payment_stat' => 0]);
        $this->postJson(self::ROOT.'/application/payments')->assertStatus(503);
        $this->getJson(self::ROOT.'/application/receipt')->assertConflict();
    }

    public function test_age_and_post_restrictions_block_final_submission(): void
    {
        $ad = $this->ad();
        [$candidate,$application] = $this->candidate($ad);
        $payload = $this->complete($ad, $candidate, $application);
        $payload['personal-details']['dob'] = '1960-01-01';
        $application->payload = $payload;
        $application->save();
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('personal-details.dob');
        $this->assertDatabaseHas('f_user_details', ['fu_master' => $candidate->f_uid, 'fu_final_submit' => 0]);
    }

    public function test_missing_essential_qualification_blocks_submission(): void
    {
        $ad = $this->ad();
        [$candidate,$application] = $this->candidate($ad);
        $payload = $this->complete($ad, $candidate, $application);
        $payload['qualifications']['items'] = [];
        $application->payload = $payload;
        $application->save();
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('qualifications');
    }

    public function test_document_ownership_is_checked_for_download_and_form_references(): void
    {
        Storage::fake('local');
        $ad = $this->ad();
        [$candidate,$application] = $this->candidate($ad);
        $other = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        $document = $this->doc($other, 'dob');
        $this->getJson(self::ROOT.'/application/documents/'.$document)->assertNotFound();
        $this->patchJson(self::ROOT.'/application/personal-details', ['version' => 1, 'data' => ['dob_document_id' => $document]])->assertUnprocessable()->assertJsonValidationErrors('documents');
    }

    public function test_document_upload_is_private_and_increments_version(): void
    {
        Storage::fake('local');
        $ad = $this->ad();
        [$candidate] = $this->candidate($ad);
        $response = $this->postJson(self::ROOT.'/application/documents', ['version' => 1, 'purpose' => 'dob', 'file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')])
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonMissingPath('data.path');
        $token = $response->json('data.id');
        $this->get(self::ROOT.'/application/documents/'.$token)->assertOk();
        $this->deleteJson(self::ROOT.'/application/documents/'.$token, ['version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $this->getJson(self::ROOT.'/application/documents/'.$token)->assertNotFound();
    }

    public function test_executable_upload_is_rejected(): void
    {
        Storage::fake('local');
        $ad = $this->ad();
        $this->candidate($ad);
        $this->postJson(self::ROOT.'/application/documents', ['version' => 1, 'purpose' => 'photo', 'file' => UploadedFile::fake()->createWithContent('bad.php', '<?php echo 1;')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('candidate_documents', 0);
    }

    public function test_cancellation_or_disabled_account_blocks_application_access(): void
    {
        $ad = $this->ad();
        [$candidate] = $this->candidate($ad);
        DB::table('f_user_details')->where('fu_master', $candidate->f_uid)->update(['fu_cancel_stat' => 1]);
        $this->getJson(self::ROOT.'/application')->assertForbidden();
        $candidate->f_status = 0;
        $candidate->save();
        $this->getJson(self::ROOT.'/application')->assertUnauthorized();
    }

    public function test_legacy_application_status_is_read_only_and_not_overwritten(): void
    {
        $ad = $this->ad();
        $candidate = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        $this->actingAs($candidate, 'candidate');
        $this->getJson(self::ROOT.'/application/status')->assertOk()->assertJsonPath('data.state', 'legacy');
        $this->patchJson(self::ROOT.'/application/personal-details', ['version' => 1, 'data' => ['father_name' => 'Overwrite']])->assertConflict();
    }

    public function test_or_alternative_qualifications_are_accepted_but_and_requirements_are_enforced(): void
    {
        $ad = $this->ad();
        [$candidate, $application] = $this->candidate($ad);
        $payload = $this->complete($ad, $candidate, $application);
        DB::table('advertisement_qualification')->update(['aquali_relation' => 'OR']);
        $master = QualificationFactory::new()->create(['qm_createby' => $ad->adv_createby]);
        DB::table('advertisement_qualification')->insert(['aquali_adv_master' => $ad->adv_auto_genno, 'aquali_exam' => $master->qm_id,
            'aquali_examtype' => 'Essential', 'aquali_marks' => 40, 'aquali_category' => 'Full', 'aquali_attempt' => 'No', 'aquali_relation' => 'END', 'aquali_createdate' => '2027-01-01 10:00:00']);
        $this->getJson(self::ROOT.'/application/preview')->assertOk()->assertJsonPath('data.can_submit', true);
        DB::table('advertisement_qualification')->where('aquali_relation', 'OR')->update(['aquali_relation' => 'AND']);
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('qualifications');
    }

    public function test_reserved_category_requires_evidence_before_fee_waiver(): void
    {
        $ad = $this->ad(['adv_fees' => 200]);
        [$candidate, $application] = $this->candidate($ad);
        $payload = $this->complete($ad, $candidate, $application);
        AgeRelaxationFactory::new()->create(['caste_id' => 2, 'caste_cat' => 1, 'caste_createby' => $ad->adv_createby]);
        DB::table('advertisement_age_set')->insert(['advage_adv_master' => $ad->adv_auto_genno, 'advage_section' => 2,
            'advage_type' => 'END', 'advage_up' => 5, 'advage_feetype' => 'No', 'advage_createdate' => '2027-01-01 10:00:00']);
        $payload['category-relaxations']['caste_id'] = $this->publicId('age-relaxation', 2);
        $application->payload = $payload;
        $application->save();
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('category-relaxations.caste_document_id');
        $this->assertDatabaseHas('candidate_applications', ['id' => $application->id, 'state' => 'draft']);
    }

    public function test_essential_experience_minimum_is_enforced(): void
    {
        $ad = $this->ad();
        [$candidate, $application] = $this->candidate($ad);
        $payload = $this->complete($ad, $candidate, $application);
        $master = ExperienceFactory::new()->create(['expset_createby' => $ad->adv_createby]);
        $option = DB::table('advertisement_experience')->insertGetId(['aexpr_adv_master' => $ad->adv_auto_genno,
            'aexpr_name' => $master->expset_id, 'aexpr_marks' => 10, 'aexpr_category' => 'Full', 'aexpr_type' => 'Essential', 'aexpr_relation' => 'END', 'aexpr_min_month' => 12,
            'aexpr_createdate' => '2027-01-01 10:00:00'], 'aexpr_id');
        $payload['experiences']['items'] = [['option_id' => $this->publicId('advertisement-experience', $option),
            'organization' => 'Test employer', 'years' => 0, 'months' => 11, 'document_id' => $this->doc($candidate, 'experience')]];
        $application->payload = $payload;
        $application->save();
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('experiences');
        $payload['experiences']['items'][0]['years'] = 1;
        $payload['experiences']['items'][0]['months'] = 0;
        $application->payload = $payload;
        $application->save();
        $this->postJson(self::ROOT.'/application/submit', ['version' => 1])->assertOk()->assertJsonPath('data.state', 'complete');
        $this->assertDatabaseCount('f_user_ess_experience', 1);
    }
}
