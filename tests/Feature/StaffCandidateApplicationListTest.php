<?php

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\CandidateUser;
use App\Models\StaffUser;
use Database\Factories\AdvertisementFactory;
use Database\Factories\CandidateUserFactory;
use Database\Factories\DisciplineFactory;
use Database\Factories\RecruitmentFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\StaffAuthTestCase;

class StaffCandidateApplicationListTest extends StaffAuthTestCase
{
    private const ROOT = '/api/v1/candidates/applications';

    private function context(): array
    {
        $ad = AdvertisementFactory::new()->create();
        $this->actingAs(StaffUser::query()->findOrFail($ad->adv_createby), 'staff');
        $discipline = DisciplineFactory::new()->create(['catm_master' => $ad->adv_recruit_master, 'catm_createby' => $ad->adv_createby]);
        $post = DB::table('advertisement_categoty')->insertGetId([
            'acat_adv_master' => $ad->adv_auto_genno, 'acat_name' => $discipline->catm_id,
            'acat_gender_set' => 'All', 'acat_marital_set' => 'All', 'acat_createdate' => now(),
        ], 'acat_id');

        return [$ad, $post, [
            'recruitment_id' => $this->publicId('recruitment', $ad->adv_recruit_master),
            'advertisement_id' => $this->publicId('advertisement', $ad->adv_id),
            'post_id' => $this->publicId('advertisement-post', $post),
        ]];
    }

    private function candidate(Advertisement $ad, int $post, array $details = [], array $user = []): CandidateUser
    {
        $candidate = CandidateUserFactory::new()->create(array_merge(['f_applied_for' => $ad->adv_auto_genno], $user));
        DB::table('f_user_details')->where('fu_master', $candidate->f_uid)->update(array_merge([
            'fu_category' => $post, 'fu_step_1' => 1, 'fu_step_2' => 1, 'fu_step_3' => 1, 'fu_step_4' => 1,
            'fu_final_submit' => 1, 'fu_payment_stat' => 1, 'fu_cancel_stat' => 0,
        ], $details));

        return $candidate;
    }

    private function url(array $query = []): string
    {
        return self::ROOT.'?'.http_build_query($query);
    }

    public function test_list_returns_only_safe_fields_with_encrypted_candidate_id(): void
    {
        [$ad,$post,$query] = $this->context();
        $candidate = $this->candidate($ad, $post, [], ['f_full_name' => 'Alice Candidate']);
        $this->getJson($this->url($query))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->matchesPublicId('candidate', $candidate->f_uid))
            ->assertJsonPath('data.0.name', 'Alice Candidate')->assertJsonPath('data.0.application_number', $candidate->f_application_no)
            ->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.f_password')->assertJsonMissingPath('data.0.f_otp')
            ->assertJsonMissingPath('data.0.f_uid')->assertJsonStructure(['links', 'meta']);
        $this->assertSame(['id', 'application_number', 'name', 'mobile', 'email'], array_keys($this->getJson($this->url($query))->json('data.0')));
    }

    #[TestWith(['fu_step_1', 0])]
    #[TestWith(['fu_step_2', 0])]
    #[TestWith(['fu_step_3', 0])]
    #[TestWith(['fu_step_4', 0])]
    #[TestWith(['fu_final_submit', 0])]
    #[TestWith(['fu_payment_stat', 0])]
    #[TestWith(['fu_cancel_stat', 1])]
    public function test_incomplete_unpaid_or_cancelled_applications_are_excluded(string $field, int $value): void
    {
        [$ad,$post,$query] = $this->context();
        $this->candidate($ad, $post, [$field => $value]);
        $this->candidate($ad, $post);
        $this->getJson($this->url($query))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    }

    public function test_review_status_is_not_a_filter_and_legacy_rows_need_no_new_draft(): void
    {
        [$ad,$post,$query] = $this->context();
        foreach (['Approved', 'Rejected', 'NotChecked'] as $status) {
            $candidate = $this->candidate($ad, $post);
            DB::table('candidate_result_tab')->where('cr_application_master', $candidate->f_application_no)->update(['cr_approval' => $status]);
        }
        $this->assertDatabaseCount('candidate_applications', 0);
        $this->getJson($this->url($query))->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_search_cannot_escape_selected_post_or_completion_filters(): void
    {
        [$ad,$post,$query] = $this->context();
        $this->candidate($ad, $post, [], ['f_full_name' => 'Visible Match']);
        $this->candidate($ad, $post, ['fu_category' => $post + 100], ['f_full_name' => 'Other Match']);
        $this->candidate($ad, $post, ['fu_payment_stat' => 0], ['f_full_name' => 'Unpaid Match']);
        $this->candidate($ad, $post, [], ['f_applied_for' => 'OTHER', 'f_full_name' => 'Another Match']);
        $this->getJson($this->url($query + ['search' => 'mAtCh']))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Visible Match');
    }

    public function test_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        [$ad,$post,$query] = $this->context();
        $this->candidate($ad, $post, [], ['f_full_name' => 'Name 100%_test']);
        $this->candidate($ad, $post, [], ['f_full_name' => 'Name 100XXtest']);
        $this->getJson($this->url($query + ['search' => '%_']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Name 100%_test');
    }

    #[TestWith(['application_number', 'C123456789'])]
    #[TestWith(['mobile', '9876543210'])]
    #[TestWith(['email', 'find@example.test'])]
    public function test_search_supports_other_display_columns(string $field, string $value): void
    {
        [$ad,$post,$query] = $this->context();
        $column = ['application_number' => 'f_application_no', 'mobile' => 'f_mobile', 'email' => 'f_email'][$field];
        $this->candidate($ad, $post, [], [$column => $value]);
        $this->candidate($ad, $post);
        $this->getJson($this->url($query + ['search' => $value]))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sorting_and_pagination_happen_on_the_server(): void
    {
        [$ad,$post,$query] = $this->context();
        foreach (['Charlie', 'Alice', 'Bob'] as $name) {
            $this->candidate($ad, $post, [], ['f_full_name' => $name]);
        }
        $this->getJson($this->url($query + ['per_page' => 1, 'page' => 2]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bob')->assertJsonPath('meta.total', 3);
        $this->getJson($this->url($query + ['sort_direction' => 'desc']))->assertOk()->assertJsonPath('data.0.name', 'Charlie');
    }

    public function test_all_selection_ids_are_required_and_plain_or_wrong_purpose_ids_fail(): void
    {
        [$ad,$post,$query] = $this->context();
        $this->getJson(self::ROOT)->assertUnprocessable()->assertJsonValidationErrors(['recruitment_id', 'advertisement_id', 'post_id']);
        $query['post_id'] = (string) $post;
        $this->getJson($this->url($query))->assertUnprocessable()->assertJsonValidationErrors('post_id');
        $query['post_id'] = $this->publicId('discipline', $post);
        $this->getJson($this->url($query))->assertUnprocessable()->assertJsonValidationErrors('post_id');
    }

    public function test_mismatched_recruitment_and_advertisement_are_rejected(): void
    {
        [$ad,$post,$query] = $this->context();
        $other = RecruitmentFactory::new()->create(['rm_createby' => $ad->adv_createby]);
        $query['recruitment_id'] = $this->publicId('recruitment', $other->rm_id);
        $this->getJson($this->url($query))->assertUnprocessable()->assertJsonValidationErrors('advertisement_id');
    }

    public function test_invalid_or_foreign_post_is_rejected(): void
    {
        [$ad,$post,$query] = $this->context();
        $query['post_id'] = $this->publicId('advertisement-post', $post + 100);
        $this->getJson($this->url($query))->assertUnprocessable()->assertJsonValidationErrors('post_id');
    }

    public function test_locked_advertisement_is_not_selectable(): void
    {
        [$ad,$post,$query] = $this->context();
        $ad->adv_status = 0;
        $ad->save();
        $this->getJson($this->url($query))->assertUnprocessable()->assertJsonValidationErrors('advertisement_id');
        $this->getJson(self::ROOT.'/form-options?'.http_build_query(['recruitment_id' => $query['recruitment_id']]))->assertOk()->assertJsonCount(0, 'data.advertisements');
    }

    public function test_options_load_in_sequence_and_return_encrypted_ids(): void
    {
        [$ad,$post,$query] = $this->context();
        $this->getJson(self::ROOT.'/form-options')->assertOk()->assertJsonCount(1, 'data.recruitments')->assertJsonCount(0, 'data.advertisements')->assertJsonCount(0, 'data.posts');
        $this->getJson(self::ROOT.'/form-options?'.http_build_query(['recruitment_id' => $query['recruitment_id']]))->assertOk()
            ->assertJsonPath('data.advertisements.0.id', $this->matchesPublicId('advertisement', $ad->adv_id));
        unset($query['post_id']);
        $this->getJson(self::ROOT.'/form-options?'.http_build_query($query))->assertOk()
            ->assertJsonPath('data.posts.0.id', $this->matchesPublicId('advertisement-post', $post));
        $this->getJson(self::ROOT.'/form-options?'.http_build_query(['advertisement_id' => $query['advertisement_id']]))->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
    }

    public function test_unsafe_sort_and_unbounded_pagination_are_rejected(): void
    {
        [$ad,$post,$query] = $this->context();
        $this->getJson($this->url($query + ['sort_by' => 'f_password', 'sort_direction' => 'DROP', 'per_page' => 101, 'page' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors(['sort_by', 'sort_direction', 'per_page', 'page']);
    }

    public function test_guest_and_non_super_admin_are_denied_on_both_routes(): void
    {
        $this->getJson(self::ROOT)->assertUnauthorized();
        $this->getJson(self::ROOT.'/form-options')->assertUnauthorized();
        $staff = StaffUserFactory::new()->create();
        $this->actingAs($staff, 'staff');
        $this->getJson(self::ROOT)->assertForbidden();
        $this->getJson(self::ROOT.'/form-options')->assertForbidden();
    }

    public function test_candidate_session_does_not_grant_staff_list_access(): void
    {
        $ad = AdvertisementFactory::new()->create();
        $candidate = CandidateUserFactory::new()->create(['f_applied_for' => $ad->adv_auto_genno]);
        $this->actingAs($candidate, 'candidate');
        $this->getJson(self::ROOT)->assertUnauthorized();
    }

    public function test_disabled_super_admin_is_denied(): void
    {
        [$ad,$post,$query] = $this->context();
        DB::table('user_info')->where('u_id', $ad->adv_createby)->update(['user_status' => 0]);
        $this->getJson($this->url($query))->assertForbidden();
    }
}
