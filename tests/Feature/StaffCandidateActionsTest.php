<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use App\Domain\Identity\StaffCandidateActionService;
use App\Models\StaffUser;
use Database\Factories\AdvertisementFactory;
use Database\Factories\AgeRelaxationFactory;
use Database\Factories\CandidateApplicationFactory;
use Database\Factories\CandidateDocumentFactory;
use Database\Factories\CandidateUserFactory;
use Database\Factories\DisciplineFactory;
use Database\Factories\ExperienceFactory;
use Database\Factories\QualificationFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\StaffAuthTestCase;

class StaffCandidateActionsTest extends StaffAuthTestCase
{
    private function context(): array
    {
        Storage::fake('local');
        $ad = AdvertisementFactory::new()->create();
        $this->actingAs(StaffUser::query()->findOrFail($ad->adv_createby), 'staff');
        $discipline = DisciplineFactory::new()->create(['catm_master' => $ad->adv_recruit_master, 'catm_createby' => $ad->adv_createby]);
        $post = DB::table('advertisement_categoty')->insertGetId(['acat_adv_master' => $ad->adv_auto_genno, 'acat_name' => $discipline->catm_id, 'acat_gender_set' => 'All', 'acat_marital_set' => 'All', 'acat_createdate' => now()], 'acat_id');
        $c = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        DB::table('f_user_details')->where('fu_master', $c->f_uid)->update(['fu_category' => $post, 'fu_step_1' => 1, 'fu_step_2' => 1, 'fu_step_3' => 1, 'fu_step_4' => 1, 'fu_final_submit' => 1, 'fu_payment_stat' => 1, 'fu_dob' => '1990-01-01', 'fu_father_name' => 'Father', 'fu_mother_name' => 'Mother']);

        return [$ad, $c, '/api/v1/candidates/applications/'.$this->publicId('candidate', $c->f_uid)];
    }

    private function revision(string $url): string
    {
        return $this->getJson($url)->assertOk()->json('data.revision');
    }

    private function option(object $ad, bool $qualification = true, string $type = 'Essential'): array
    {
        if ($qualification) {
            $master = QualificationFactory::new()->create(['qm_createby' => $ad->adv_createby]);
            $id = DB::table('advertisement_qualification')->insertGetId(['aquali_adv_master' => $ad->adv_auto_genno, 'aquali_exam' => $master->qm_id, 'aquali_examtype' => $type, 'aquali_marks' => 10, 'aquali_category' => 'Full', 'aquali_relation' => 'END', 'aquali_attempt' => 'No', 'aquali_createdate' => now()], 'aquali_id');

            return [$master->qm_id, $this->publicId('advertisement-qualification', $id), $id];
        }
        $master = ExperienceFactory::new()->create(['expset_createby' => $ad->adv_createby]);
        $id = DB::table('advertisement_experience')->insertGetId(['aexpr_adv_master' => $ad->adv_auto_genno, 'aexpr_name' => $master->expset_id, 'aexpr_type' => $type, 'aexpr_marks' => 10, 'aexpr_min_month' => 12, 'aexpr_category' => 'Full', 'aexpr_relation' => 'END', 'aexpr_createdate' => now()], 'aexpr_id');

        return [$master->expset_id, $this->publicId('advertisement-experience', $id), $id];
    }

    private function entry(object $c, int $master, string $section = 'essential-qualifications'): array
    {
        $m = StaffCandidateActionService::SECTIONS[$section];
        $row = [$m['owner'] => $c->f_uid, $m['master'] => $master, $m['doc'] => 'candidate-documents/proof.pdf'];
        if ($m['qualification']) {
            $row[$m['prefix'].'_quali_createdate'] = now();
        } else {
            foreach (['exp_year' => 1, 'exp_month' => 0, 'exp_org_name' => 'Employer', 'exp_createdate' => now()] as $k => $v) {
                $row[$m['prefix'].'_'.$k] = $v;
            }
            if ($section === 'essential-experiences') {
                $row['fues_exp_serial'] = 1;
            }
        }
        $id = DB::table($m['table'])->insertGetId($row, $m['key']);

        return [$id, $this->publicId('candidate-entry:'.$section, $id)];
    }

    public function test_detail_contains_owned_application_and_no_authentication_or_storage_secrets(): void
    {
        [$ad,$c,$url] = $this->context();
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $this->matchesPublicId('candidate', $c->f_uid))
            ->assertJsonPath('data.personal.dob', '1990-01-01')->assertJsonPath('data.actions.can_swap', true)
            ->assertJsonMissingPath('data.f_password')->assertJsonMissingPath('data.f_otp')->assertJsonMissingPath('data.personal.fu_master')
            ->assertJsonStructure(['data' => ['revision', 'personal', 'addresses', 'entries', 'documents', 'review', 'actions']]);
        $this->getJson('/api/v1/candidates/applications/123')->assertNotFound();
    }

    public function test_profile_change_requires_proof_is_audited_and_invalidates_stale_revision(): void
    {
        [$ad,$c,$url] = $this->context();
        $revision = $this->revision($url);
        $this->postJson($url.'/profile', ['revision' => $revision, 'reason' => 'Correct spelling', 'name' => 'Correct Name'])->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->postJson($url.'/profile', ['revision' => $revision, 'reason' => 'Correct spelling', 'name' => 'Correct Name', 'father_name' => 'New Father', 'evidence' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')])
            ->assertOk()->assertJsonPath('data.name', 'Correct Name')->assertJsonPath('data.personal.father_name', 'New Father');
        $this->assertDatabaseHas('candidate_admin_events', ['candidate_uid' => $c->f_uid, 'action' => 'profile']);
        $this->assertDatabaseHas('frontend_users', ['f_uid' => $c->f_uid, 'f_full_name' => 'Correct Name']);
        $this->patchJson($url.'/dob', ['revision' => $revision, 'reason' => 'Fix DOB', 'dob' => '1991-01-01'])->assertConflict();
        $audit = DB::table('candidate_admin_events')->first();
        $this->assertStringNotContainsString('f_password', $audit->before);
        $this->getJson($url.'/audit')->assertOk()->assertJsonPath('data.0.action', 'profile')->assertJsonMissingPath('data.0.before');
    }

    public function test_profile_contact_duplicate_is_rejected_and_failed_upload_is_cleaned_up(): void
    {
        [$ad,$c,$url] = $this->context();
        $other = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        $this->postJson($url.'/profile', ['revision' => $this->revision($url), 'reason' => 'Contact fix', 'mobile' => $other->f_mobile, 'evidence' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles('candidate-admin'));
        $this->assertDatabaseCount('candidate_admin_events', 0);
    }

    public function test_dob_validates_age_range_and_updates_new_application_payload(): void
    {
        [$ad,$c,$url] = $this->context();
        $a = CandidateApplicationFactory::new()->create(['candidate_uid' => $c->f_uid, 'payload' => ['personal-details' => ['dob' => '1990-01-01']], 'state' => 'complete']);
        $revision = $this->revision($url);
        $this->patchJson($url.'/dob', ['revision' => $revision, 'reason' => 'Certificate correction', 'dob' => '1960-01-01'])->assertUnprocessable()->assertJsonValidationErrors('dob');
        $this->patchJson($url.'/dob', ['revision' => $revision, 'reason' => 'Certificate correction', 'dob' => '1991-01-01'])->assertOk()->assertJsonPath('data.personal.dob', '1991-01-01');
        $this->assertSame('1991-01-01', $a->fresh()->payload['personal-details']['dob']);
        $this->assertDatabaseCount('candidate_admin_events', 1);
    }

    public function test_dob_supports_advertisement_relaxation_rules(): void
    {
        [$ad,$c,$url] = $this->context();
        AgeRelaxationFactory::new()->create(['caste_id' => 2, 'caste_cat' => 1, 'caste_createby' => $ad->adv_createby]);
        DB::table('f_user_details')->where('fu_master', $c->f_uid)->update(['fu_caste_type' => 2]);
        DB::table('advertisement_age_set')->insert(['advage_adv_master' => $ad->adv_auto_genno, 'advage_section' => 2, 'advage_up' => 5, 'advage_feetype' => 'Full', 'advage_type' => 'END', 'advage_createdate' => now()]);
        $this->patchJson($url.'/dob', ['revision' => $this->revision($url), 'reason' => 'Relaxation valid', 'dob' => '1977-01-01'])->assertOk();
    }

    #[TestWith(['essential-qualifications', true, 'Essential'])]
    #[TestWith(['desirable-qualifications', true, 'Desirable'])]
    #[TestWith(['essential-experiences', false, 'Essential'])]
    #[TestWith(['desirable-experiences', false, 'Desirable'])]
    public function test_each_swap_updates_only_owned_entry_preserves_removed_checks_and_logs(string $section, bool $q, string $type): void
    {
        [$ad,$c,$url] = $this->context();
        [$old] = $this->option($ad, $q, $type);
        [$new,$option] = $this->option($ad, $q, $type);
        [$id,$entry] = $this->entry($c, $old, $section);
        $m = StaffCandidateActionService::SECTIONS[$section];
        DB::table('checking_tab')->insert(['chk_user_application' => $c->f_application_no, 'chk_type' => $m['check'], 'chk_sub_typeid' => $old, 'chk_create_by_type' => 2, 'chk_createdate' => now()]);
        $this->patchJson($url.'/swaps', ['revision' => $this->revision($url), 'reason' => 'Requirement correction', 'section' => $section, 'changes' => [['entry_id' => $entry, 'option_id' => $option]]])->assertOk();
        $this->assertDatabaseHas($m['table'], [$m['key'] => $id, $m['master'] => $new]);
        $this->assertDatabaseCount('checking_tab', 0);
        $this->assertDatabaseCount('qe_swip_log', 1);
        $audit = DB::table('candidate_admin_events')->first();
        $this->assertCount(1, json_decode($audit->before, true)['checks']);
    }

    public function test_swaps_require_not_checked_and_cannot_modify_another_candidate_entry(): void
    {
        [$ad,$c,$url] = $this->context();
        [$master,$option] = $this->option($ad);
        $other = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        [$id,$entry] = $this->entry($other, $master);
        $data = ['revision' => $this->revision($url), 'reason' => 'Test correction', 'section' => 'essential-qualifications', 'changes' => [['entry_id' => $entry, 'option_id' => $option]]];
        $this->patchJson($url.'/swaps', $data)->assertUnprocessable();
        DB::table('candidate_result_tab')->where('cr_application_master', $c->f_application_no)->update(['cr_approval' => 'Approved']);
        $data['revision'] = $this->revision($url);
        $this->patchJson($url.'/swaps', $data)->assertConflict();
        $this->assertDatabaseCount('candidate_admin_events', 0);
    }

    public function test_swap_batch_is_atomic_and_rejects_wrong_requirement_type(): void
    {
        [$ad,$c,$url] = $this->context();
        [$old] = $this->option($ad);
        [$new,$option] = $this->option($ad);
        [$id,$entry] = $this->entry($c, $old);
        $this->patchJson($url.'/swaps', ['revision' => $this->revision($url), 'reason' => 'Test', 'section' => 'essential-qualifications', 'changes' => [['entry_id' => $entry, 'option_id' => $option], ['entry_id' => $this->publicId('candidate-entry:essential-qualifications', 999999), 'option_id' => $option]]])->assertUnprocessable();
        $this->assertDatabaseHas('f_user_qualification', ['fu_quali_id' => $id, 'fu_qualifiaction_name' => $old]);
        $this->assertDatabaseCount('qe_swip_log', 0);
        [$wrong,$wrongOption] = $this->option($ad, true, 'Desirable');
        $this->patchJson($url.'/swaps', ['revision' => $this->revision($url), 'reason' => 'Test', 'section' => 'essential-qualifications', 'changes' => [['entry_id' => $entry, 'option_id' => $wrongOption]]])->assertUnprocessable();
    }

    public function test_swaps_reject_duplicate_resulting_qualifications(): void
    {
        [$ad,$c,$url] = $this->context();
        [$old] = $this->option($ad);
        [$new,$option] = $this->option($ad);
        [$id,$entry] = $this->entry($c, $old);
        $this->entry($c, $new);
        $this->patchJson($url.'/swaps', ['revision' => $this->revision($url), 'reason' => 'Test', 'section' => 'essential-qualifications', 'changes' => [['entry_id' => $entry, 'option_id' => $option]]])->assertUnprocessable();
    }

    public function test_rejection_requires_evidence_and_updates_result_without_cancelling_or_deleting_application(): void
    {
        [$ad,$c,$url] = $this->context();
        $this->postJson($url.'/reject', ['revision' => $this->revision($url), 'reason' => 'Invalid document', 'evidence' => UploadedFile::fake()->create('rejection.pdf', 10, 'application/pdf')])->assertOk()->assertJsonPath('data.review.status', 'Rejected');
        $this->assertDatabaseHas('candidate_result_tab', ['cr_application_master' => $c->f_application_no, 'cr_approval' => 'Rejected', 'cr_reject_comments' => 'Invalid document']);
        $this->assertDatabaseHas('f_user_details', ['fu_master' => $c->f_uid, 'fu_cancel_stat' => 0, 'fu_payment_stat' => 1]);
        $this->postJson($url.'/reject', ['revision' => $this->revision($url), 'reason' => 'Again', 'evidence' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')])->assertConflict();
        $this->assertDatabaseCount('candidate_admin_events', 1);
    }

    public function test_checks_history_and_section_view_return_review_information(): void
    {
        [$ad,$c,$url] = $this->context();
        DB::table('checking_tab')->insert(['chk_user_application' => $c->f_application_no, 'chk_type' => 'fu_dob', 'chk_approve' => 'Approved', 'chk_create_by_type' => 2, 'chk_createdate' => now()]);
        DB::table('checker_log')->insert(['chklog_app_no' => $c->f_application_no, 'chklog_type' => 'fu_dob', 'chklog_user' => $ad->adv_createby, 'chklog_approval' => 'Approved', 'chklog_msg' => 'Checked', 'chklog_createdate' => now()]);
        $this->getJson($url.'/checkings?section=fu_dob')->assertOk()->assertJsonPath('data.checks.0.level_1.status', 'Approved');
        $this->getJson($url.'/checking-history')->assertOk()->assertJsonPath('data.0.comments', 'Checked');
        $this->getJson($url.'/sections')->assertOk()->assertJsonStructure(['data' => ['available_sections', 'entries']]);
        $this->getJson($url.'/swap-options')->assertOk()->assertJsonPath('data.can_swap', true);
    }

    public function test_marks_require_merit_list_then_return_saved_scores(): void
    {
        [$ad,$c,$url] = $this->context();
        $this->getJson($url.'/marks')->assertConflict();
        $post = DB::table('f_user_details')->where('fu_master', $c->f_uid)->value('fu_category');
        DB::table('merit_list_tab')->insert(['mr_adv_master' => $ad->adv_auto_genno, 'mr_cand_app_no' => $c->f_application_no, 'mr_category' => $post, 'mr_listing' => 1, 'mr_createdate' => now(), 'mr_createby' => $ad->adv_createby]);
        DB::table('candidate_result_tab')->where('cr_application_master', $c->f_application_no)->update(['cr_academic' => 40, 'cr_total_marks' => 70]);
        $this->getJson($url.'/marks')->assertOk()->assertJsonPath('data.total', 70);
    }

    public function test_private_document_download_is_scoped_to_candidate(): void
    {
        [$ad,$c,$url] = $this->context();
        Storage::disk('local')->put('candidate-documents/test.pdf', 'pdf test');
        DB::table('f_user_details')->where('fu_master', $c->f_uid)->update(['fu_dob_doc' => 'candidate-documents/test.pdf']);
        $id = $this->getJson($url)->json('data.documents.0.id');
        $this->get($url.'/documents/'.$id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        $token = $this->publicId('staff-candidate-document:dob', $other->f_uid);
        $this->getJson($url.'/documents/'.$token)->assertNotFound();
        $this->getJson($url.'/documents/123')->assertNotFound();
    }

    public function test_legacy_document_uses_only_configured_candidate_directory(): void
    {
        [$ad,$c,$url] = $this->context();
        $base = Storage::disk('local')->path('legacy');
        $folder = $base.'/'.$ad->adv_auto_genno.'/candidates/'.$c->f_application_no;
        mkdir($folder, 0777, true);
        file_put_contents($folder.'/proof.pdf', 'legacy pdf');
        config(['candidate_admin.legacy_upload_root' => $base]);
        DB::table('f_user_details')->where('fu_master', $c->f_uid)->update(['fu_dob_doc' => 'proof.pdf']);
        $token = $this->publicId('staff-candidate-document:dob', $c->f_uid);
        $this->get($url.'/documents/'.$token)->assertOk();
        DB::table('f_user_details')->where('fu_master', $c->f_uid)->update(['fu_dob_doc' => '../../outside.pdf']);
        $this->getJson($url.'/documents/'.$token)->assertNotFound();
    }

    public function test_executable_evidence_and_unknown_fields_are_rejected(): void
    {
        [$ad,$c,$url] = $this->context();
        $this->postJson($url.'/reject', ['revision' => $this->revision($url), 'reason' => 'Test', 'cr_approval' => 'Approved', 'evidence' => UploadedFile::fake()->createWithContent('bad.php', '<?php echo 1;')])->assertUnprocessable()->assertJsonValidationErrors(['evidence', 'cr_approval']);
        $this->assertDatabaseCount('candidate_admin_events', 0);
    }

    public function test_incomplete_and_cancelled_candidates_cannot_be_modified(): void
    {
        [$ad,$c,$url] = $this->context();
        DB::table('f_user_details')->where('fu_master', $c->f_uid)->update(['fu_payment_stat' => 0]);
        $this->getJson($url)->assertConflict();
        DB::table('f_user_details')->where('fu_master', $c->f_uid)->update(['fu_payment_stat' => 1, 'fu_cancel_stat' => 1]);
        $this->getJson($url)->assertConflict();
    }

    public function test_staff_without_super_admin_role_and_guest_are_denied(): void
    {
        $base = '/api/v1/candidates/applications/'.$this->publicId('candidate', 1);
        $this->getJson($base)->assertUnauthorized();
        $this->actingAs(StaffUserFactory::new()->create(), 'staff');
        foreach (['', '/checkings', '/checking-history', '/sections', '/marks', '/swap-options', '/audit', '/documents/abc'] as $suffix) {
            $this->getJson($base.$suffix)->assertForbidden();
        }
        $this->postJson($base.'/profile')->assertForbidden();
        $this->patchJson($base.'/dob')->assertForbidden();
        $this->patchJson($base.'/swaps')->assertForbidden();
        $this->postJson($base.'/reject')->assertForbidden();
    }

    public function test_qualification_swap_keeps_the_new_application_receipt_payload_in_sync(): void
    {
        [$ad,$c,$url] = $this->context();
        [$old,$oldOption] = $this->option($ad);
        [$new,$newOption] = $this->option($ad);
        [$id,$entry] = $this->entry($c, $old);
        $a = CandidateApplicationFactory::new()->create(['candidate_uid' => $c->f_uid, 'state' => 'complete', 'payload' => ['qualifications' => ['items' => [['option_id' => $oldOption]]]]]);
        $this->patchJson($url.'/swaps', ['revision' => $this->revision($url), 'reason' => 'Correct requirement', 'section' => 'essential-qualifications', 'changes' => [['entry_id' => $entry, 'option_id' => $newOption]]])->assertOk();
        $this->assertSame(PublicId::decode('advertisement-qualification', $newOption), PublicId::decode('advertisement-qualification', $a->fresh()->payload['qualifications']['items'][0]['option_id']));
    }

    public function test_experience_swap_changes_only_the_selected_employment_in_the_new_payload(): void
    {
        [$ad,$c,$url] = $this->context();
        [$old,$oldOption] = $this->option($ad, false);
        [$new,$newOption] = $this->option($ad, false);
        [$id,$entry] = $this->entry($c, $old, 'essential-experiences');
        [$otherId] = $this->entry($c, $old, 'essential-experiences');
        DB::table('f_user_ess_experience')->where('fues_exp_id', $otherId)->update(['fues_exp_org_name' => 'Other employer']);
        $doc = CandidateDocumentFactory::new()->create(['candidate_uid' => $c->f_uid, 'path' => 'candidate-documents/proof.pdf', 'purpose' => 'experience']);
        $proof = $this->publicId('candidate-document', $doc->id);
        $a = CandidateApplicationFactory::new()->create(['candidate_uid' => $c->f_uid, 'state' => 'complete', 'payload' => ['experiences' => ['items' => [
            ['option_id' => $oldOption, 'organization' => 'Employer', 'years' => 1, 'months' => 0, 'document_id' => $proof],
            ['option_id' => $oldOption, 'organization' => 'Other employer', 'years' => 1, 'months' => 0, 'document_id' => $proof],
        ]]]]);
        $this->patchJson($url.'/swaps', ['revision' => $this->revision($url), 'reason' => 'Correct requirement', 'section' => 'essential-experiences', 'changes' => [['entry_id' => $entry, 'option_id' => $newOption]]])->assertOk();
        $items = $a->fresh()->payload['experiences']['items'];
        $this->assertSame(PublicId::decode('advertisement-experience', $newOption), PublicId::decode('advertisement-experience', $items[0]['option_id']));
        $this->assertSame(PublicId::decode('advertisement-experience', $oldOption), PublicId::decode('advertisement-experience', $items[1]['option_id']));
    }

    public function test_checking_filters_validate_section_subtype_purpose_and_scope(): void
    {
        [$ad,$c,$url] = $this->context();
        [$master] = $this->option($ad);
        DB::table('checking_tab')->insert(['chk_user_application' => $c->f_application_no, 'chk_type' => 'fu_es_qualification', 'chk_sub_typeid' => $master, 'chk_create_by_type' => 2, 'chk_createdate' => now()]);
        $this->getJson($url.'/sections?'.http_build_query(['section' => 'fu_es_qualification', 'subtype_id' => $this->publicId('qualification', $master)]))->assertOk()->assertJsonCount(1, 'data.checking.checks');
        $this->getJson($url.'/checkings?'.http_build_query(['section' => 'fu_es_qualification', 'subtype_id' => $this->publicId('experience', $master)]))->assertUnprocessable();
        $this->getJson($url.'/checkings?section=anything')->assertUnprocessable();
    }

    public function test_non_candidate_tokens_and_cross_advertisement_swap_options_are_rejected(): void
    {
        [$ad,$c,$url] = $this->context();
        [$old] = $this->option($ad);
        [$id,$entry] = $this->entry($c, $old);
        $other = AdvertisementFactory::new()->create(['adv_recruit_master' => $ad->adv_recruit_master, 'adv_createby' => $ad->adv_createby]);
        [$new,$otherOption] = $this->option($other);
        $this->patchJson($url.'/swaps', ['revision' => $this->revision($url), 'reason' => 'Test', 'section' => 'essential-qualifications', 'changes' => [['entry_id' => $entry, 'option_id' => $otherOption]]])->assertUnprocessable();
        $this->getJson('/api/v1/candidates/applications/'.$this->publicId('advertisement', $c->f_uid))->assertNotFound();
        $this->assertDatabaseCount('candidate_admin_events', 0);
    }
}
