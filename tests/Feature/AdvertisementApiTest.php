<?php

namespace Tests\Feature;

use App\Domain\Identity\AdvertisementDraftService;
use App\Domain\Identity\AdvertisementSubmission;
use App\Domain\Identity\PublicId;
use App\Models\AdvertisementDraft;
use App\Models\StaffUser;
use Database\Factories\AdvertisementDraftFactory;
use Database\Factories\AgeRelaxationFactory;
use Database\Factories\DisciplineFactory;
use Database\Factories\ExperienceFactory;
use Database\Factories\QualificationFactory;
use Database\Factories\RecruitmentFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class AdvertisementApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/advertisements';

    private function admin(): StaffUser
    {
        $admin = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($admin, 'staff');

        return $admin;
    }

    private function path(AdvertisementDraft $draft, string $suffix = ''): string
    {
        return self::ENDPOINT.'/'.$this->publicId('advertisement-draft', $draft->id).$suffix;
    }

    private function draft(StaffUser $admin, array $payload = []): AdvertisementDraft
    {
        return AdvertisementDraftFactory::new()->create(['created_by' => $admin->u_id, 'payload' => $payload]);
    }

    private function payload(StaffUser $admin): array
    {
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $discipline = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id, 'catm_master' => $recruitment->rm_id]);
        $qualification = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);

        return [
            'recruitment_id' => $this->publicId('recruitment', $recruitment->rm_id),
            'advertisement_number' => 'WBHRB/TEST/001', 'type' => 'New',
            'starts_at' => '2027-01-01 10:00:00', 'ends_at' => '2027-02-01 18:00:00',
            'temporary_appointment' => 'Temporary appointment', 'pay_scale' => 'Level 10',
            'total_vacancies' => 5, 'dob_from' => '1980-01-01', 'dob_to' => '2000-01-01',
            'age_relaxation_years' => 5, 'fee' => 200, 'pwd_percent' => 40,
            'has_experience' => 'No', 'dictation' => 'No',
            'disciplines' => [['discipline_id' => $this->publicId('discipline', $discipline->catm_id),
                'gender' => 'All', 'marital_status' => 'All', 'vacancies' => ['ur' => 3, 'sc' => 2]]],
            'qualifications' => [['qualification_id' => $this->publicId('qualification', $qualification->qm_id),
                'type' => 'Essential', 'relationship' => 'END', 'maximum_marks' => 40, 'distribution' => 'Full',
                'final_qualification' => 'Yes', 'allow_pursuing' => 'No', 'attempt_type' => 'No']],
            'marks' => ['academic' => 40, 'experience' => 0, 'interview' => 30, 'written' => 30],
        ];
    }

    private function ready(StaffUser $admin, ?array $payload = null): AdvertisementDraft
    {
        Storage::fake('local');
        $response = $this->postJson(self::ENDPOINT, $payload ?? $this->payload($admin))->assertCreated();
        $draft = AdvertisementDraft::findOrFail(PublicId::decode('advertisement-draft', $response->json('data.id')));
        Storage::disk('local')->put('advertisements/test.pdf', '%PDF-1.4 test');
        $draft->document_path = 'advertisements/test.pdf';
        $draft->save();

        return $draft;
    }

    public function test_empty_draft_can_be_created_without_any_business_table_writes(): void
    {
        $admin = $this->admin();
        $response = $this->postJson(self::ENDPOINT)->assertCreated()->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.has_document', false)
            ->assertJsonPath('data.created_by', $this->matchesPublicId('staff', $admin->u_id));
        $this->assertDatabaseCount('advertisement_drafts', 1);
        $this->assertDatabaseCount('advertisement_master', 0);
        $this->assertDatabaseCount('advertisement_qualification', 0);
        $this->assertGreaterThan(50, strlen($response->json('data.id')));
    }

    public function test_partial_rows_and_missing_sections_can_be_saved_and_resumed(): void
    {
        $admin = $this->admin();
        $draft = $this->draft($admin, ['advertisement_number' => 'Partial']);
        $qualification = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $this->patchJson($this->path($draft), ['version' => 1, 'qualifications' => [
            ['qualification_id' => $this->publicId('qualification', $qualification->qm_id), 'relationship' => 'OR'], [],
        ]])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.details.advertisement_number', 'Partial');
        $this->getJson($this->path($draft))->assertOk()->assertJsonCount(2, 'data.details.qualifications')
            ->assertJsonPath('data.details.qualifications.0.qualification_id', $this->matchesPublicId('qualification', $qualification->qm_id));
        $this->assertDatabaseCount('advertisement_master', 0);
    }

    public function test_omitted_sections_are_preserved_supplied_arrays_replace_and_marks_merge(): void
    {
        $admin = $this->admin();
        $draft = $this->draft($admin, ['qualifications' => [['relationship' => 'OR']], 'marks' => ['academic' => 40, 'interview' => 20]]);
        $this->patchJson($this->path($draft), ['version' => 1, 'marks' => ['interview' => 30]])->assertOk()
            ->assertJsonPath('data.details.marks.academic', 40)->assertJsonPath('data.details.marks.interview', 30)
            ->assertJsonCount(1, 'data.details.qualifications');
        $this->patchJson($this->path($draft), ['version' => 2, 'qualifications' => []])->assertOk()->assertJsonCount(0, 'data.details.qualifications');
        $this->assertSame([], $draft->refresh()->payload['qualifications']);
    }

    public function test_stale_save_returns_409_without_overwriting_newer_data(): void
    {
        $admin = $this->admin();
        $draft = $this->draft($admin);
        $this->patchJson($this->path($draft), ['version' => 1, 'advertisement_number' => 'New'])->assertOk();
        $this->patchJson($this->path($draft), ['version' => 1, 'advertisement_number' => 'Stale'])->assertConflict()
            ->assertJsonPath('message', 'This draft has changed. Reload it before saving again.');
        $this->assertSame('New', $draft->refresh()->payload['advertisement_number']);
    }

    #[TestWith(['status', 1])]
    #[TestWith(['created_by', 99])]
    #[TestWith(['state', 'submitted'])]
    #[TestWith(['document_path', '../../test.php'])]
    #[TestWith(['submitted_advertisement_id', 100])]
    public function test_server_owned_fields_cannot_be_injected(string $field, mixed $value): void
    {
        $this->admin();
        $this->postJson(self::ENDPOINT, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('advertisement_drafts', 0);
    }

    #[TestWith([145])]
    #[TestWith(['145'])]
    #[TestWith(['invalid-token'])]
    public function test_plain_or_invalid_master_identifiers_return_422(mixed $id): void
    {
        $this->admin();
        $this->postJson(self::ENDPOINT, ['recruitment_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
    }

    public function test_wrong_purpose_draft_identifiers_and_numeric_routes_return_404(): void
    {
        $admin = $this->admin();
        $draft = $this->draft($admin);
        $this->getJson(self::ENDPOINT.'/'.$draft->id)->assertNotFound();
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('experience', $draft->id))->assertNotFound();
    }

    #[TestWith(['GET', ''])]
    #[TestWith(['POST', ''])]
    #[TestWith(['GET', '/form-options'])]
    public function test_unauthenticated_requests_return_401(string $method, string $suffix): void
    {
        $this->json($method, self::ENDPOINT.$suffix)->assertUnauthorized();
    }

    public function test_all_advertisement_endpoints_reject_non_super_admin(): void
    {
        $role = StaffRoleFactory::new()->create(['mu_id' => 2]);
        $user = StaffUserFactory::new()->for($role, 'role')->create();
        $this->actingAs($user, 'staff');
        $creator = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $draft = $this->draft($creator);
        $token = $this->publicId('advertisement-draft', $draft->id);
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $creator->u_id]);
        $recruitmentToken = $this->publicId('recruitment', $recruitment->rm_id);
        foreach ([['GET', ''], ['POST', ''], ['GET', '/form-options'], ['GET', '/'.$token],
            ['PATCH', '/'.$token], ['POST', '/'.$token.'/document'], ['GET', '/'.$token.'/document'], ['POST', '/'.$token.'/submit'],
            ['GET', '/recruitment-options/'.$recruitmentToken]] as [$method, $suffix]) {
            $this->json($method, self::ENDPOINT.$suffix)->assertForbidden();
        }
        $this->assertDatabaseCount('advertisement_drafts', 1);
    }

    public function test_disabled_super_admin_is_forbidden(): void
    {
        $admin = StaffUserFactory::new()->inactive()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($admin, 'staff')->postJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_draft_listing_filters_state_and_paginates(): void
    {
        $admin = $this->admin();
        AdvertisementDraftFactory::new()->count(3)->create(['created_by' => $admin->u_id]);
        AdvertisementDraftFactory::new()->create(['created_by' => $admin->u_id, 'state' => 'submitted']);
        $this->getJson(self::ENDPOINT.'?state=draft&per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson(self::ENDPOINT.'?per_page=1000')->assertUnprocessable();
    }

    public function test_dropdowns_apply_recruitment_filter_and_general_discipline_fallback(): void
    {
        $admin = $this->admin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $general = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id]);
        QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $url = self::ENDPOINT.'/recruitment-options/'.$this->publicId('recruitment', $recruitment->rm_id);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.disciplines')
            ->assertJsonPath('data.disciplines.0.id', $this->matchesPublicId('discipline', $general->catm_id));
        $specific = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id, 'catm_master' => $recruitment->rm_id]);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.disciplines')
            ->assertJsonPath('data.disciplines.0.id', $this->matchesPublicId('discipline', $specific->catm_id));
        $this->getJson(self::ENDPOINT.'/form-options')->assertOk()->assertJsonPath('data.relationships', ['AND', 'OR', 'END']);
    }

    public function test_incomplete_submission_returns_validation_errors_and_remains_draft(): void
    {
        $admin = $this->admin();
        $draft = $this->draft($admin);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['recruitment_id', 'disciplines', 'qualifications', 'marks']);
        $this->assertSame('draft', $draft->refresh()->state);
        $this->assertDatabaseCount('advertisement_master', 0);
    }

    public function test_complete_submission_writes_legacy_records_with_correct_dob_mapping_and_no_activation(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        $response = $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk()
            ->assertJsonPath('data.state', 'submitted')->assertJsonPath('data.version', 2)->assertJsonPath('data.has_document', true);
        $advertisementId = PublicId::decode('advertisement', $response->json('data.submitted_advertisement_id'));
        $this->assertDatabaseHas('advertisement_master', ['adv_id' => $advertisementId, 'adv_activated' => 0,
            'adv_total_recruit' => 5, 'adv_min_age_limit' => '2000-01-01', 'adv_age_limit' => '1980-01-01',
            'adv_qualification_no' => 1, 'adv_createby' => $admin->u_id]);
        $this->assertDatabaseHas('advertisement_categoty', ['acat_adv_master' => $draft->reference, 'acat_total' => 5]);
        $this->assertDatabaseHas('advertisement_marks', ['amark_adv_master' => $draft->reference, 'amark_academic' => 40]);
        $this->assertDatabaseHas('advertisement_qualification', ['aquali_adv_master' => $draft->reference, 'aquali_relation' => 'END']);
        $this->assertDatabaseCount('advertisement_master', 1);
        Storage::disk('local')->assertExists('advertisements/test.pdf');
    }

    public function test_repeated_submission_is_idempotent_and_submitted_draft_is_not_editable(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk();
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($this->path($draft), ['version' => 2, 'advertisement_number' => 'Changed'])->assertConflict();
        $this->assertDatabaseCount('advertisement_master', 1);
        $this->assertDatabaseCount('advertisement_qualification', 1);
    }

    #[TestWith(['total_vacancies', 6])]
    #[TestWith(['marks', ['academic' => 20, 'experience' => 0, 'interview' => 20, 'written' => 20]])]
    #[TestWith(['has_experience', 'Yes'])]
    #[TestWith(['dictation', 'Yes'])]
    #[TestWith(['type', 'Old'])]
    public function test_submission_enforces_cross_section_completeness(string $key, mixed $value): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $payload[$key] = $value;
        $draft = $this->ready($admin, $payload);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('advertisement_master', 0);
    }

    public function test_missing_document_blocks_submission(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        Storage::disk('local')->delete('advertisements/test.pdf');
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertDatabaseCount('advertisement_master', 0);
    }

    public function test_ordered_or_and_end_rules_are_preserved_and_essential_groups_counted(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $base = $payload['qualifications'][0];
        $second = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $third = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $payload['qualifications'] = [array_replace($base, ['relationship' => 'OR']),
            array_replace($base, ['qualification_id' => $this->publicId('qualification', $second->qm_id), 'relationship' => 'AND']),
            array_replace($base, ['qualification_id' => $this->publicId('qualification', $third->qm_id)])];
        $draft = $this->ready($admin, $payload);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk();
        $this->assertSame(['OR', 'AND', 'END'], DB::table('advertisement_qualification')->orderBy('aquali_id')->pluck('aquali_relation')->all());
        $this->assertDatabaseHas('advertisement_master', ['adv_auto_genno' => $draft->reference, 'adv_qualification_no' => 2]);
    }

    public function test_chain_without_final_end_can_be_saved_but_cannot_be_submitted(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $payload['qualifications'][0]['relationship'] = 'OR';
        $draft = $this->ready($admin, $payload);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('qualifications.0.relationship');
        $this->assertDatabaseCount('advertisement_master', 0);
    }

    public function test_duplicate_qualification_cannot_hide_behind_different_encrypted_tokens(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $id = PublicId::decode('qualification', $payload['qualifications'][0]['qualification_id']);
        $payload['qualifications'][] = array_replace($payload['qualifications'][0], ['qualification_id' => $this->publicId('qualification', $id)]);
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors('qualifications.1.qualification_id');
    }

    public function test_recruitment_change_revalidates_previously_saved_disciplines(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $response = $this->postJson(self::ENDPOINT, $payload)->assertCreated();
        $other = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $this->patchJson(self::ENDPOINT.'/'.$response->json('data.id'), ['version' => 1,
            'recruitment_id' => $this->publicId('recruitment', $other->rm_id)])->assertUnprocessable()->assertJsonValidationErrors('disciplines.0.discipline_id');
    }

    public function test_submission_rechecks_master_status_after_draft_save(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        DB::table('qualification_master')->update(['qm_status' => 0]);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('qualifications.0.qualification_id');
    }

    public function test_qualification_deductions_experience_slabs_and_age_rules_are_written(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $experience = ExperienceFactory::new()->create(['expset_createby' => $admin->u_id]);
        $section = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id]);
        $payload['qualifications'][0] = array_replace($payload['qualifications'][0], [
            'distribution' => 'Slab', 'slabs' => [['threshold' => 60, 'marks' => 40]],
            'attempt_type' => 'Slab', 'deductions' => [['attempt' => 2, 'marks' => 5]],
        ]);
        $payload['has_experience'] = 'Yes';
        $payload['experiences'] = [['experience_id' => $this->publicId('experience', $experience->expset_id),
            'type' => 'Essential', 'relationship' => 'END', 'maximum_marks' => 10,
            'distribution' => 'Slab', 'minimum_months' => 12,
            'slabs' => [['operator' => 'GT', 'months' => 24, 'marks' => 10]]]];
        $payload['marks'] = ['academic' => 40, 'experience' => 10, 'interview' => 20, 'written' => 30];
        $payload['age_fee_rules'] = [['section_id' => $this->publicId('age-relaxation', $section->caste_id),
            'relationship' => 'END', 'years' => 5, 'fee_type' => 'Part', 'partial_fee' => 100]];
        $draft = $this->ready($admin, $payload);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk();
        $this->assertDatabaseHas('advertisement_quali_details', ['aq_detail_score_lvl' => 60, 'aq_detail_score_mark' => 40]);
        $this->assertDatabaseHas('advertisement_deduct_details', ['aq_deduct_lvl' => 2, 'aq_deduct_mark' => 5]);
        $this->assertDatabaseHas('advertisement_exp_details', ['ae_range_words' => 'GT', 'ae_detail_month' => 24]);
        $this->assertDatabaseHas('advertisement_age_set', ['advage_section' => $section->caste_id, 'advage_partfee' => 100]);
    }

    public function test_failure_during_submission_rolls_back_every_legacy_write(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        $this->mock(AdvertisementSubmission::class, function ($mock): void {
            $mock->shouldReceive('write')->once()->andReturnUsing(function (AdvertisementDraft $draft, array $payload, int $staffId): int {
                (new AdvertisementSubmission)->write($draft, $payload, $staffId);
                throw new RuntimeException('Simulated failure after child writes');
            });
        });
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertStatus(500);
        foreach (['advertisement_master', 'advertisement_marks', 'advertisement_categoty', 'advertisement_qualification'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('draft', $draft->refresh()->state);
        $this->assertSame(1, $draft->version);
    }

    public function test_document_upload_and_download_are_private_and_versioned(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $draft = $this->draft($admin);
        $this->post($this->path($draft, '/document'), ['version' => 1,
            'document' => UploadedFile::fake()->create('advertisement.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_document', true)->assertJsonPath('data.version', 2)->assertJsonMissingPath('data.document_path');
        Storage::disk('local')->assertExists($draft->refresh()->document_path);
        $this->get($this->path($draft, '/document'))->assertOk()->assertDownload('advertisement.pdf');
    }

    public function test_stale_document_upload_does_not_leave_an_orphan_file(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $draft = $this->draft($admin);
        $this->post($this->path($draft, '/document'), ['version' => 2,
            'document' => UploadedFile::fake()->create('advertisement.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertConflict();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($draft->refresh()->document_path);
    }

    public function test_executable_document_is_rejected(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $draft = $this->draft($admin);
        $this->post($this->path($draft, '/document'), ['version' => 1,
            'document' => UploadedFile::fake()->create('test.php', 1, 'application/x-php')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_csrf_protection_is_applied_to_draft_creation(): void
    {
        $this->admin();
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT)->assertStatus(419);
        $this->assertDatabaseCount('advertisement_drafts', 0);
    }

    public function test_old_advertisement_and_language_requirements_are_saved_when_complete(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $payload['type'] = 'Old';
        $payload['old_starts_at'] = '2026-01-01 10:00:00';
        $payload['old_ends_at'] = '2026-02-01 10:00:00';
        $payload['dictation'] = 'Yes';
        $payload['language_writeup'] = 'Knowledge of Bengali';
        $draft = $this->ready($admin, $payload);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk();
        $this->assertDatabaseHas('advertisement_master', ['adv_auto_genno' => $draft->reference,
            'adv_old_starttime' => '2026-01-01 10:00:00', 'adv_lang_writeup' => 'Knowledge of Bengali']);
    }

    #[TestWith(['ends_at', '2026-01-01 10:00:00'])]
    #[TestWith(['dob_to', '1970-01-01'])]
    #[TestWith(['fee', -1])]
    #[TestWith(['pwd_percent', 101])]
    #[TestWith(['type', 'Injected'])]
    public function test_invalid_supplied_values_are_rejected_even_in_drafts(string $key, mixed $value): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $payload[$key] = $value;
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->assertDatabaseCount('advertisement_drafts', 0);
    }

    public function test_unknown_nested_fields_and_nested_boolean_groups_are_rejected(): void
    {
        $this->admin();
        $this->postJson(self::ENDPOINT, ['qualifications' => [['operator' => 'OR', 'items' => []]]])
            ->assertUnprocessable()->assertJsonValidationErrors('qualifications.0');
    }

    public function test_slab_marks_cannot_exceed_the_requirement_maximum(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $payload['qualifications'][0]['distribution'] = 'Slab';
        $payload['qualifications'][0]['slabs'] = [['threshold' => 50, 'marks' => 41]];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors('qualifications.0.slabs.0.marks');
    }

    public function test_required_scoring_slabs_can_be_completed_later_but_are_checked_on_submit(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $payload['qualifications'][0]['distribution'] = 'Slab';
        $draft = $this->ready($admin, $payload);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('qualifications.0.slabs');
    }

    public function test_partial_fee_is_required_on_submit_and_cannot_exceed_the_base_fee(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $section = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id]);
        $payload['age_fee_rules'] = [['section_id' => $this->publicId('age-relaxation', $section->caste_id),
            'relationship' => 'END', 'years' => 5, 'fee_type' => 'Part', 'partial_fee' => 300]];
        $draft = $this->ready($admin, $payload);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('age_fee_rules.0.partial_fee');
    }

    public function test_stale_submission_returns_409_without_writes(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        $this->postJson($this->path($draft, '/submit'), ['version' => 2])->assertConflict();
        $this->assertDatabaseCount('advertisement_master', 0);
        $this->assertSame('draft', $draft->refresh()->state);
    }

    public function test_upload_replacement_removes_old_document_only_after_successful_save(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        $this->post($this->path($draft, '/document'), ['version' => 1,
            'document' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing('advertisements/test.pdf');
        Storage::disk('local')->assertExists($draft->refresh()->document_path);
        $this->assertSame(2, $draft->version);
    }

    public function test_submission_keeps_document_immutable_and_oversized_upload_is_rejected(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        $this->post($this->path($draft, '/document'), ['version' => 1,
            'document' => UploadedFile::fake()->create('large.pdf', 11001, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk();
        $this->post($this->path($draft, '/document'), ['version' => 2,
            'document' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertConflict();
        $this->assertSame(['advertisements/test.pdf'], Storage::disk('local')->allFiles());
    }

    public function test_generated_reference_uses_kolkata_date_time_and_four_numeric_digits(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 11:47:30', 'Asia/Kolkata'));
        $this->admin();
        $response = $this->postJson(self::ENDPOINT)->assertCreated();
        $reference = $response->json('data.reference');
        $this->assertMatchesRegularExpression('/\AA05112026114730[0-9]{4}\z/', $reference);
        $this->assertDatabaseHas('advertisement_drafts', ['reference' => $reference]);
        $this->getJson(self::ENDPOINT.'/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.reference', $reference);
    }

    public function test_generated_reference_retries_an_existing_draft_number(): void
    {
        $admin = $this->admin();
        $existing = $this->draft($admin);
        $existing->reference = 'A051120261147301234';
        $existing->save();
        $service = \Mockery::mock(AdvertisementDraftService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('generateReference')->twice()->andReturn($existing->reference, 'A051120261147305678');
        $this->app->instance(AdvertisementDraftService::class, $service);
        $this->postJson(self::ENDPOINT)->assertCreated()->assertJsonPath('data.reference', 'A051120261147305678');
        $this->assertDatabaseCount('advertisement_drafts', 2);
    }

    public function test_generated_reference_does_not_reuse_a_legacy_advertisement_number(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk();
        DB::table('advertisement_master')->where('adv_auto_genno', $draft->reference)->update(['adv_auto_genno' => 'A051120261147301234']);
        $service = \Mockery::mock(AdvertisementDraftService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('generateReference')->twice()->andReturn('A051120261147301234', 'A051120261147305678');
        $this->app->instance(AdvertisementDraftService::class, $service);
        $this->postJson(self::ENDPOINT)->assertCreated()->assertJsonPath('data.reference', 'A051120261147305678');
    }

    public function test_reference_collision_limit_returns_retryable_503_without_duplicate_records(): void
    {
        $admin = $this->admin();
        $existing = $this->draft($admin);
        $service = \Mockery::mock(AdvertisementDraftService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('generateReference')->times(10)->andReturn($existing->reference);
        $this->app->instance(AdvertisementDraftService::class, $service);
        $this->postJson(self::ENDPOINT)->assertStatus(503);
        $this->assertDatabaseCount('advertisement_drafts', 1);
    }

    #[TestWith(['writeup_language', 'language_writeup'])]
    #[TestWith(['writeup_age', 'age_writeup'])]
    #[TestWith(['writeup_essential_qualification', 'essential_writeup'])]
    #[TestWith(['writeup_desirable_qualification', 'desirable_writeup'])]
    #[TestWith(['experience_writeup', 'marks_writeup'])]
    #[TestWith(['writeup_experience', 'marks_writeup'])]
    #[TestWith(['writeup_miscellaneous', 'miscellaneous_writeup'])]
    #[TestWith(['writeup_disabilities', 'disability_writeup'])]
    public function test_frontend_writeup_alias_is_decoded_before_draft_creation(string $alias, string $canonical): void
    {
        $this->admin();
        $html = '<p>lorem ipsum new&nbsp;</p>';
        $response = $this->postJson(self::ENDPOINT, [$alias => 'PHA+bG9yZW0gaXBzdW0gbmV3Jm5ic3A7PC9wPg=='])
            ->assertCreated()->assertJsonPath('data.details.'.$canonical, 'lorem ipsum new')->assertJsonMissingPath('data.details.'.$alias);
        $draft = AdvertisementDraft::findOrFail(PublicId::decode('advertisement-draft', $response->json('data.id')));
        $this->assertSame('lorem ipsum new', $draft->payload[$canonical]);
        $this->assertArrayNotHasKey($alias, $draft->payload);
    }

    #[TestWith(['writeup_language', 'language_writeup'])]
    #[TestWith(['writeup_age', 'age_writeup'])]
    #[TestWith(['writeup_essential_qualification', 'essential_writeup'])]
    #[TestWith(['writeup_desirable_qualification', 'desirable_writeup'])]
    #[TestWith(['experience_writeup', 'marks_writeup'])]
    #[TestWith(['writeup_experience', 'marks_writeup'])]
    #[TestWith(['writeup_miscellaneous', 'miscellaneous_writeup'])]
    #[TestWith(['writeup_disabilities', 'disability_writeup'])]
    public function test_frontend_writeup_alias_is_decoded_when_saving_and_reopening(string $alias, string $canonical): void
    {
        $admin = $this->admin();
        $draft = $this->draft($admin, ['advertisement_number' => 'Existing']);
        $html = '<p>Updated &amp; formatted</p>';
        $this->patchJson($this->path($draft), ['version' => 1, $alias => base64_encode($html)])
            ->assertOk()->assertJsonPath('data.details.'.$canonical, 'Updated & formatted')->assertJsonPath('data.version', 2);
        $this->getJson($this->path($draft))->assertOk()->assertJsonPath('data.details.'.$canonical, 'Updated & formatted')
            ->assertJsonPath('data.details.advertisement_number', 'Existing');
        $this->assertSame('Updated & formatted', $draft->refresh()->payload[$canonical]);
    }

    public function test_all_frontend_writeup_aliases_reach_their_legacy_database_columns_as_plain_text(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $html = '<p>Test Data&nbsp;</p>';
        $payload['dictation'] = 'Yes';
        foreach (['writeup_language', 'writeup_age', 'writeup_essential_qualification', 'writeup_desirable_qualification',
            'experience_writeup', 'writeup_experience', 'writeup_miscellaneous', 'writeup_disabilities'] as $field) {
            $payload[$field] = base64_encode($html);
        }
        $draft = $this->ready($admin, $payload);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk();
        $this->assertDatabaseHas('advertisement_master', [
            'adv_auto_genno' => $draft->reference, 'adv_lang_writeup' => 'Test Data', 'adv_age_writeup' => 'Test Data',
            'adv_essen_qualification' => 'Test Data', 'adv_desir_qualification' => 'Test Data',
            'adv_marks_writeup' => 'Test Data', 'adv_miscellenius' => 'Test Data', 'adv_disability' => 'Test Data',
        ]);
    }

    #[TestWith(['<p>not encoded</p>'])]
    #[TestWith(['%%%invalid%%%'])]
    #[TestWith(['Zg==='])]
    #[TestWith(['/w=='])]
    #[TestWith(['AA=='])]
    #[TestWith([123])]
    #[TestWith([['value' => 'invalid']])]
    public function test_invalid_base64_writeup_returns_422_and_leaves_saved_data_unchanged(mixed $encoded): void
    {
        $admin = $this->admin();
        $draft = $this->draft($admin, ['marks_writeup' => '<p>Original</p>']);
        $this->patchJson($this->path($draft), ['version' => 1, 'experience_writeup' => $encoded])
            ->assertUnprocessable()->assertJsonValidationErrors('experience_writeup')
            ->assertJsonPath('errors.experience_writeup.0', 'Provide valid Base64-encoded UTF-8 write-up content.');
        $this->assertSame('<p>Original</p>', $draft->refresh()->payload['marks_writeup']);
        $this->assertSame(1, $draft->version);
    }

    public function test_conflicting_experience_aliases_are_rejected_instead_of_silently_overwriting(): void
    {
        $this->admin();
        $this->postJson(self::ENDPOINT, ['experience_writeup' => base64_encode('First'), 'writeup_experience' => base64_encode('Second')])
            ->assertUnprocessable()->assertJsonValidationErrors('writeup_experience');
        $this->assertDatabaseCount('advertisement_drafts', 0);
    }

    public function test_conflicting_encoded_alias_and_plain_canonical_value_are_rejected(): void
    {
        $this->admin();
        $this->postJson(self::ENDPOINT, ['age_writeup' => 'Plain', 'writeup_age' => base64_encode('Different')])
            ->assertUnprocessable()->assertJsonValidationErrors('writeup_age');
    }

    public function test_canonical_html_is_converted_to_text_without_guessing_base64(): void
    {
        $this->admin();
        $this->postJson(self::ENDPOINT, ['marks_writeup' => 'VGVzdA==', 'pay_scale' => '<p>Level 10</p>'])
            ->assertCreated()->assertJsonPath('data.details.marks_writeup', 'VGVzdA==')
            ->assertJsonPath('data.details.pay_scale', 'Level 10');
    }

    public function test_unicode_writeup_and_unpadded_base64_are_accepted(): void
    {
        $this->admin();
        $html = '<p>বাংলা লেখা</p>';
        $this->postJson(self::ENDPOINT, ['writeup_language' => rtrim(base64_encode($html), '=')])
            ->assertCreated()->assertJsonPath('data.details.language_writeup', 'বাংলা লেখা');
    }

    public function test_empty_alias_clears_the_writeup_without_affecting_other_fields(): void
    {
        $admin = $this->admin();
        $draft = $this->draft($admin, ['age_writeup' => '<p>Old</p>', 'pay_scale' => 'Level 10']);
        $this->patchJson($this->path($draft), ['version' => 1, 'writeup_age' => null])->assertOk()
            ->assertJsonPath('data.details.age_writeup', null)->assertJsonPath('data.details.pay_scale', 'Level 10');
        $this->assertNull($draft->refresh()->payload['age_writeup']);
    }

    public function test_decoded_writeup_length_limit_is_enforced(): void
    {
        $this->admin();
        $this->postJson(self::ENDPOINT, ['writeup_age' => base64_encode(str_repeat('a', 65001))])
            ->assertUnprocessable()->assertJsonValidationErrors('age_writeup');
        $this->assertDatabaseCount('advertisement_drafts', 0);
    }

    #[TestWith(['age_writeup'])]
    #[TestWith(['language_writeup'])]
    #[TestWith(['essential_writeup'])]
    #[TestWith(['desirable_writeup'])]
    #[TestWith(['marks_writeup'])]
    #[TestWith(['miscellaneous_writeup'])]
    #[TestWith(['disability_writeup'])]
    #[TestWith(['pay_scale'])]
    #[TestWith(['temporary_appointment'])]
    public function test_canonical_rich_text_is_stored_and_returned_without_tags(string $field): void
    {
        $this->admin();
        $response = $this->postJson(self::ENDPOINT, [$field => '<p>Test <strong>Data</strong>&nbsp;</p>'])
            ->assertCreated()->assertJsonPath('data.details.'.$field, 'Test Data');
        $draft = AdvertisementDraft::findOrFail(PublicId::decode('advertisement-draft', $response->json('data.id')));
        $this->assertSame('Test Data', $draft->payload[$field]);
    }

    public function test_plain_text_conversion_preserves_paragraph_and_line_breaks_and_discards_scripts(): void
    {
        $this->admin();
        $html = '<p>First&nbsp;paragraph</p><p>Second<br>line &amp; more</p><script>alert(1)</script><style>p{color:red}</style>';
        $this->postJson(self::ENDPOINT, ['writeup_miscellaneous' => base64_encode($html)])->assertCreated()
            ->assertJsonPath('data.details.miscellaneous_writeup', "First paragraph\nSecond\nline & more");
    }

    public function test_empty_editor_markup_is_null_and_cannot_satisfy_a_required_writeup(): void
    {
        $admin = $this->admin();
        $payload = $this->payload($admin);
        $payload['dictation'] = 'Yes';
        $payload['writeup_language'] = base64_encode('<p>&nbsp;<br></p>');
        $draft = $this->ready($admin, $payload);
        $this->assertNull($draft->payload['language_writeup']);
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('language_writeup');
    }

    public function test_old_html_draft_is_normalized_in_draft_and_legacy_tables_during_submission(): void
    {
        $admin = $this->admin();
        $draft = $this->ready($admin);
        $payload = $draft->payload;
        $payload['pay_scale'] = '<p>Level <b>10</b></p>';
        $payload['miscellaneous_writeup'] = '<p>Test Data&nbsp;</p>';
        $draft->payload = $payload;
        $draft->save();
        $this->postJson($this->path($draft, '/submit'), ['version' => 1])->assertOk()
            ->assertJsonPath('data.details.pay_scale', 'Level 10')
            ->assertJsonPath('data.details.miscellaneous_writeup', 'Test Data');
        $this->assertSame('Test Data', $draft->refresh()->payload['miscellaneous_writeup']);
        $this->assertDatabaseHas('advertisement_master', ['adv_auto_genno' => $draft->reference,
            'adv_scale_pay' => 'Level 10', 'adv_miscellenius' => 'Test Data']);
    }

    public function test_equivalent_plain_and_encoded_alias_values_do_not_conflict_after_normalization(): void
    {
        $this->admin();
        $this->postJson(self::ENDPOINT, ['marks_writeup' => 'Test Data', 'experience_writeup' => base64_encode('<p>Test Data&nbsp;</p>')])
            ->assertCreated()->assertJsonPath('data.details.marks_writeup', 'Test Data');
    }
}
