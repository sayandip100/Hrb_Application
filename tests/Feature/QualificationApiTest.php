<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Database\Factories\QualificationFactory;
use Database\Factories\RecruitmentFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class QualificationApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/master-data/qualifications';

    private function signInAsSuperAdmin(): StaffUser
    {
        $admin = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($admin, 'staff');

        return $admin;
    }

    public function test_listing_includes_recruitment_and_unlinked_records_newest_first(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $linked = QualificationFactory::new()->create(['qm_r_master' => $recruitment->rm_id, 'qm_createby' => $admin->u_id]);
        $unlinked = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $unlinked->qm_id)->assertJsonPath('data.0.recruitment', null)
            ->assertJsonPath('data.1.recruitment.name', $recruitment->rm_name)->assertJsonPath('meta.total', 2);
    }

    public function test_listing_filters_by_recruitment_status_and_search_with_pagination(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        QualificationFactory::new()->count(3)->create([
            'qm_name' => 'MBBS', 'qm_r_master' => $recruitment->rm_id, 'qm_status' => 0, 'qm_createby' => $admin->u_id,
        ]);
        QualificationFactory::new()->create(['qm_name' => 'MBBS', 'qm_r_master' => $recruitment->rm_id, 'qm_createby' => $admin->u_id]);
        QualificationFactory::new()->create(['qm_name' => 'MBBS', 'qm_status' => 0, 'qm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'?search=mbbs&status=0&recruitment_id='.$recruitment->rm_id.'&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_locked', true)
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
    }

    public function test_listing_treats_search_wildcards_and_sql_as_literal_text(): void
    {
        $admin = $this->signInAsSuperAdmin();
        QualificationFactory::new()->create(['qm_name' => '100% Nursing', 'qm_createby' => $admin->u_id]);
        QualificationFactory::new()->create(['qm_name' => '1000 Nursing', 'qm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'?search='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ENDPOINT.'?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_details_returns_the_selected_qualification_for_editing(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'/'.$record->qm_id)->assertOk()
            ->assertJsonPath('data.id', $record->qm_id)->assertJsonPath('data.name', $record->qm_name);
    }

    public function test_edit_can_update_name_and_recruitment_without_changing_audit_or_lock_status(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $record = QualificationFactory::new()->create(['qm_createby' => $admin->u_id, 'qm_status' => 0]);
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => '  Updated Qualification  ', 'recruitment_id' => $recruitment->rm_id])
            ->assertOk()->assertJsonPath('message', 'Qualification updated successfully.')
            ->assertJsonPath('data.name', 'Updated Qualification')->assertJsonPath('data.recruitment.id', $recruitment->rm_id)
            ->assertJsonPath('data.is_locked', true);
        $record->refresh();
        $this->assertSame($before['qm_createby'], $record->qm_createby);
        $this->assertSame($before['qm_createdate'], $record->getRawOriginal('qm_createdate'));
        $this->assertSame($recruitment->rm_id, $record->qm_r_master);
        $this->assertSame('Updated Qualification', $record->qm_name);
    }

    public function test_name_only_edit_preserves_a_locked_existing_recruitment(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $parent = RecruitmentFactory::new()->locked()->create(['rm_createby' => $admin->u_id]);
        $record = QualificationFactory::new()->create(['qm_r_master' => $parent->rm_id, 'qm_createby' => $admin->u_id]);
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => 'New name'])->assertOk()
            ->assertJsonPath('data.recruitment_id', $parent->rm_id);
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => 'Another name', 'recruitment_id' => $parent->rm_id])->assertOk();
        $this->assertSame($parent->rm_id, $record->refresh()->qm_r_master);
    }

    public function test_recruitment_link_can_be_removed_without_changing_the_name(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $parent = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $record = QualificationFactory::new()->create(['qm_r_master' => $parent->rm_id, 'qm_createby' => $admin->u_id]);
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['recruitment_id' => null])->assertOk()
            ->assertJsonPath('data.recruitment_id', null)->assertJsonPath('data.recruitment', null)
            ->assertJsonPath('data.name', $record->qm_name);
        $this->assertNull($record->refresh()->qm_r_master);
    }

    public function test_edit_rejects_missing_or_locked_new_parent_and_does_not_partially_save(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $parent = RecruitmentFactory::new()->locked()->create(['rm_createby' => $admin->u_id]);
        $record = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $originalName = $record->qm_name;
        foreach ([$parent->rm_id, 999999] as $id) {
            $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => 'Should not save', 'recruitment_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
        }
        $this->assertSame($originalName, $record->refresh()->qm_name);
        $this->assertNull($record->qm_r_master);
    }

    #[TestWith([['name' => ''], 'name'])]
    #[TestWith([['name' => null], 'name'])]
    #[TestWith([[], 'name'])]
    #[TestWith([['status' => 0], 'status'])]
    #[TestWith([['qm_createby' => 999], 'qm_createby'])]
    #[TestWith([['recruitment_id' => 'invalid'], 'recruitment_id'])]
    public function test_invalid_edits_are_rejected_without_changing_the_record(array $payload, string $field): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    #[TestWith(['lock', 1, 0, true])]
    #[TestWith(['unlock', 0, 1, false])]
    public function test_lock_and_unlock_change_only_status_and_can_be_repeated(string $action, int $before, int $after, bool $locked): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = QualificationFactory::new()->create(['qm_createby' => $admin->u_id, 'qm_status' => $before]);
        $original = $record->refresh()->getRawOriginal();
        $url = self::ENDPOINT.'/'.$record->qm_id.'/'.$action;
        $this->patchJson($url, ['name' => 'Ignored'])->assertOk()->assertJsonPath('data.status', $after)
            ->assertJsonPath('data.is_locked', $locked);
        $this->patchJson($url)->assertOk()->assertJsonPath('data.status', $after);
        $expected = array_replace($original, ['qm_status' => $after]);
        $this->assertSame($expected, $record->refresh()->getRawOriginal());
    }

    public function test_missing_records_return_404_for_details_edit_lock_and_unlock(): void
    {
        $this->signInAsSuperAdmin();
        $this->getJson(self::ENDPOINT.'/999999')->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/999999', ['name' => 'New'])->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/999999/lock')->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/999999/unlock')->assertNotFound();
    }

    public function test_qualification_edit_and_lock_actions_require_current_csrf(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = QualificationFactory::new()->create(['qm_createby' => $admin->u_id]);
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id.'/lock')->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id.'/unlock')->assertStatus(419);
        $this->assertSame(1, $record->refresh()->qm_status);
    }

    #[TestWith(['status=2', 'status'])]
    #[TestWith(['per_page=101', 'per_page'])]
    #[TestWith(['page=0', 'page'])]
    #[TestWith(['recruitment_id=0', 'recruitment_id'])]
    #[TestWith(['search[]=invalid', 'search'])]
    public function test_invalid_list_filters_return_422(string $query, string $field): void
    {
        $this->signInAsSuperAdmin();
        $this->getJson(self::ENDPOINT.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_super_admin_can_add_a_qualification_linked_to_an_unlocked_recruitment(): void
    {
        $this->freezeTime();
        $admin = $this->signInAsSuperAdmin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $response = $this->postJson(self::ENDPOINT, ['name' => '  MBBS  ', 'recruitment_id' => $recruitment->rm_id])
            ->assertCreated()->assertJsonPath('message', 'Qualification added successfully.')
            ->assertJsonPath('data.name', 'MBBS')->assertJsonPath('data.recruitment_id', $recruitment->rm_id)
            ->assertJsonPath('data.recruitment.name', $recruitment->rm_name)
            ->assertJsonPath('data.status', 1)->assertJsonPath('data.is_locked', false)
            ->assertJsonPath('data.created_by', $admin->u_id)
            ->assertJsonPath('data.created_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('qualification_master', [
            'qm_id' => $response->json('data.id'), 'qm_name' => 'MBBS',
            'qm_r_master' => $recruitment->rm_id, 'qm_createby' => $admin->u_id, 'qm_status' => 1,
        ]);
    }

    public function test_recruitment_can_be_omitted_like_the_original_form(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => 'General Medicine'])->assertCreated()
            ->assertJsonPath('data.recruitment_id', null)->assertJsonPath('data.recruitment', null);
        $this->assertDatabaseHas('qualification_master', ['qm_name' => 'General Medicine', 'qm_r_master' => null, 'qm_createby' => $admin->u_id]);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    public function test_null_or_empty_recruitment_is_saved_as_null(mixed $recruitmentId): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => 'General Medicine', 'recruitment_id' => $recruitmentId])
            ->assertCreated()->assertJsonPath('data.recruitment_id', null);
        $this->assertDatabaseHas('qualification_master', ['qm_name' => 'General Medicine', 'qm_r_master' => null]);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([['invalid']])]
    public function test_invalid_qualification_names_return_422(mixed $name): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('qualification_master', 0);
    }

    public function test_name_length_matches_the_existing_850_character_column(): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => str_repeat('A', 851)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ENDPOINT, ['name' => str_repeat('A', 850)])->assertCreated();
        $this->assertDatabaseCount('qualification_master', 1);
    }

    public function test_unknown_or_locked_recruitment_cannot_be_selected(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $locked = RecruitmentFactory::new()->locked()->create(['rm_createby' => $admin->u_id]);
        foreach ([$locked->rm_id, 999999] as $id) {
            $this->postJson(self::ENDPOINT, ['name' => 'MBBS', 'recruitment_id' => $id])
                ->assertUnprocessable()->assertJsonPath('errors.recruitment_id.0', 'Select an existing, unlocked recruitment.');
        }
        $this->assertDatabaseCount('qualification_master', 0);
        $this->assertSame(0, $locked->refresh()->rm_status);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestWith(['abc'])]
    #[TestWith([['invalid']])]
    #[TestWith([2147483648])]
    public function test_invalid_recruitment_ids_return_422(mixed $id): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => 'MBBS', 'recruitment_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
        $this->assertDatabaseCount('qualification_master', 0);
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_duplicate_qualification_names_are_rejected_globally_even_if_case_status_or_recruitment_differs(int $status): void
    {
        $admin = $this->signInAsSuperAdmin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        QualificationFactory::new()->create(['qm_name' => ' MBBS ', 'qm_status' => $status, 'qm_createby' => $admin->u_id]);
        $this->postJson(self::ENDPOINT, ['name' => 'mbbs', 'recruitment_id' => $recruitment->rm_id])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'A qualification with this name already exists.');
        $this->assertDatabaseCount('qualification_master', 1);
    }

    public function test_qualification_edit_can_keep_its_name_but_cannot_use_another_records_name(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = QualificationFactory::new()->create(['qm_name' => 'MBBS', 'qm_createby' => $admin->u_id]);
        QualificationFactory::new()->create(['qm_name' => 'BDS', 'qm_createby' => $admin->u_id]);
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => 'MBBS'])->assertOk();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => ' bds '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('MBBS', $record->refresh()->qm_name);
        $this->assertDatabaseCount('qualification_master', 2);
    }

    #[TestWith(['id'])]
    #[TestWith(['status'])]
    #[TestWith(['created_by'])]
    #[TestWith(['created_at'])]
    #[TestWith(['qm_id'])]
    #[TestWith(['qm_status'])]
    #[TestWith(['qm_createby'])]
    #[TestWith(['qm_createdate'])]
    #[TestWith(['qm_r_master'])]
    public function test_client_cannot_override_server_fields_or_bypass_the_recruitment_check(string $field): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => 'MBBS', $field => 123])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('qualification_master', 0);
    }

    public function test_unauthenticated_request_cannot_add_a_qualification(): void
    {
        $record = QualificationFactory::new()->create();
        $this->postJson(self::ENDPOINT, ['name' => 'MBBS'])->assertUnauthorized();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->getJson(self::ENDPOINT.'/'.$record->qm_id)->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id.'/lock')->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id.'/unlock')->assertUnauthorized();
    }

    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    #[TestWith([5])]
    #[TestWith([6])]
    #[TestWith([7])]
    #[TestWith([8])]
    #[TestWith([9])]
    #[TestWith([10])]
    #[TestWith([11])]
    public function test_other_staff_roles_cannot_add_qualifications(int $roleId): void
    {
        $role = StaffRoleFactory::new()->create(['mu_id' => $roleId]);
        $staff = StaffUserFactory::new()->for($role, 'role')->create();
        $record = QualificationFactory::new()->create(['qm_createby' => $staff->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->actingAs($staff, 'staff');
        $this->postJson(self::ENDPOINT, ['name' => 'MBBS'])->assertForbidden();
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->getJson(self::ENDPOINT.'/'.$record->qm_id)->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id, ['name' => 'Unauthorized'])->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id.'/lock')->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$record->qm_id.'/unlock')->assertForbidden();
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('qualification_master', 1);
    }

    public function test_disabled_super_admin_cannot_add_qualifications(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $admin->user_status = 0;
        $admin->save();
        $this->postJson(self::ENDPOINT, ['name' => 'MBBS'])->assertForbidden();
        $this->assertDatabaseCount('qualification_master', 0);
    }

    public function test_add_qualification_requires_csrf_protection(): void
    {
        $this->signInAsSuperAdmin();
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT, ['name' => 'MBBS'])->assertStatus(419);
        $this->assertDatabaseCount('qualification_master', 0);
    }
}
