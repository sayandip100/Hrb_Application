<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Database\Factories\DisciplineFactory;
use Database\Factories\RecruitmentFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class DisciplineApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/master-data/disciplines';

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
        $linked = DisciplineFactory::new()->create(['catm_master' => $recruitment->rm_id, 'catm_createby' => $admin->u_id]);
        $unlinked = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $unlinked->catm_id)->assertJsonPath('data.0.recruitment', null)
            ->assertJsonPath('data.1.recruitment.name', $recruitment->rm_name)->assertJsonPath('meta.total', 2);
    }

    public function test_listing_filters_by_recruitment_status_and_search_with_pagination(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        DisciplineFactory::new()->count(3)->create([
            'catm_name' => 'Cardiology', 'catm_master' => $recruitment->rm_id, 'catm_status' => 0, 'catm_createby' => $admin->u_id,
        ]);
        DisciplineFactory::new()->create(['catm_name' => 'Cardiology', 'catm_master' => $recruitment->rm_id, 'catm_createby' => $admin->u_id]);
        DisciplineFactory::new()->create(['catm_name' => 'Cardiology', 'catm_status' => 0, 'catm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'?search=cardio&status=0&recruitment_id='.$recruitment->rm_id.'&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_locked', true)
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
    }

    public function test_listing_treats_search_wildcards_and_sql_as_literal_text(): void
    {
        $admin = $this->signInAsSuperAdmin();
        DisciplineFactory::new()->create(['catm_name' => '100% Nursing', 'catm_createby' => $admin->u_id]);
        DisciplineFactory::new()->create(['catm_name' => '1000 Nursing', 'catm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'?search='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ENDPOINT.'?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_details_returns_the_selected_discipline_for_editing(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'/'.$record->catm_id)->assertOk()
            ->assertJsonPath('data.id', $record->catm_id)->assertJsonPath('data.name', $record->catm_name);
    }

    public function test_edit_can_update_name_and_recruitment_without_changing_audit_or_lock_status(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $record = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id, 'catm_status' => 0]);
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, ['name' => '  Updated Discipline  ', 'recruitment_id' => $recruitment->rm_id])
            ->assertOk()->assertJsonPath('message', 'Discipline updated successfully.')
            ->assertJsonPath('data.name', 'Updated Discipline')->assertJsonPath('data.recruitment.id', $recruitment->rm_id)
            ->assertJsonPath('data.is_locked', true);
        $record->refresh();
        $this->assertSame($before['catm_createby'], $record->catm_createby);
        $this->assertSame($before['catm_createdate'], $record->getRawOriginal('catm_createdate'));
        $this->assertSame($recruitment->rm_id, $record->catm_master);
        $this->assertSame('Updated Discipline', $record->catm_name);
    }

    public function test_name_only_edit_preserves_a_locked_existing_recruitment(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $parent = RecruitmentFactory::new()->locked()->create(['rm_createby' => $admin->u_id]);
        $record = DisciplineFactory::new()->create(['catm_master' => $parent->rm_id, 'catm_createby' => $admin->u_id]);
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, ['name' => 'New name'])->assertOk()
            ->assertJsonPath('data.recruitment_id', $parent->rm_id);
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, ['name' => 'Another name', 'recruitment_id' => $parent->rm_id])->assertOk();
        $this->assertSame($parent->rm_id, $record->refresh()->catm_master);
    }

    public function test_recruitment_link_can_be_removed_without_changing_the_name(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $parent = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $record = DisciplineFactory::new()->create(['catm_master' => $parent->rm_id, 'catm_createby' => $admin->u_id]);
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, ['recruitment_id' => null])->assertOk()
            ->assertJsonPath('data.recruitment_id', null)->assertJsonPath('data.recruitment', null)
            ->assertJsonPath('data.name', $record->catm_name);
        $this->assertNull($record->refresh()->catm_master);
    }

    public function test_edit_rejects_missing_or_locked_new_parent_and_does_not_partially_save(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $parent = RecruitmentFactory::new()->locked()->create(['rm_createby' => $admin->u_id]);
        $record = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id]);
        $originalName = $record->catm_name;
        foreach ([$parent->rm_id, 999999] as $id) {
            $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, ['name' => 'Should not save', 'recruitment_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
        }
        $this->assertSame($originalName, $record->refresh()->catm_name);
        $this->assertNull($record->catm_master);
    }

    #[TestWith([['name' => ''], 'name'])]
    #[TestWith([['name' => null], 'name'])]
    #[TestWith([[], 'name'])]
    #[TestWith([['status' => 0], 'status'])]
    #[TestWith([['catm_createby' => 999], 'catm_createby'])]
    #[TestWith([['recruitment_id' => 'invalid'], 'recruitment_id'])]
    public function test_invalid_edits_are_rejected_without_changing_the_record(array $payload, string $field): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    #[TestWith(['lock', 1, 0, true])]
    #[TestWith(['unlock', 0, 1, false])]
    public function test_lock_and_unlock_change_only_status_and_can_be_repeated(string $action, int $before, int $after, bool $locked): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id, 'catm_status' => $before]);
        $original = $record->refresh()->getRawOriginal();
        $url = self::ENDPOINT.'/'.$record->catm_id.'/'.$action;
        $this->patchJson($url, ['name' => 'Ignored'])->assertOk()->assertJsonPath('data.status', $after)
            ->assertJsonPath('data.is_locked', $locked);
        $this->patchJson($url)->assertOk()->assertJsonPath('data.status', $after);
        $expected = array_replace($original, ['catm_status' => $after]);
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

    public function test_discipline_edit_and_lock_actions_require_current_csrf(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = DisciplineFactory::new()->create(['catm_createby' => $admin->u_id]);
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id.'/lock')->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id.'/unlock')->assertStatus(419);
        $this->assertSame(1, $record->refresh()->catm_status);
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

    public function test_super_admin_can_add_a_discipline_linked_to_an_unlocked_recruitment(): void
    {
        $this->freezeTime();
        $admin = $this->signInAsSuperAdmin();
        $recruitment = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $response = $this->postJson(self::ENDPOINT, ['name' => '  Cardiology  ', 'recruitment_id' => $recruitment->rm_id])
            ->assertCreated()->assertJsonPath('message', 'Discipline added successfully.')
            ->assertJsonPath('data.name', 'Cardiology')->assertJsonPath('data.recruitment_id', $recruitment->rm_id)
            ->assertJsonPath('data.recruitment.name', $recruitment->rm_name)
            ->assertJsonPath('data.status', 1)->assertJsonPath('data.is_locked', false)
            ->assertJsonPath('data.created_by', $admin->u_id)
            ->assertJsonPath('data.created_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('category_master', [
            'catm_id' => $response->json('data.id'), 'catm_name' => 'Cardiology',
            'catm_master' => $recruitment->rm_id, 'catm_createby' => $admin->u_id, 'catm_status' => 1,
        ]);
    }

    public function test_recruitment_can_be_omitted_like_the_original_form(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => 'General Medicine'])->assertCreated()
            ->assertJsonPath('data.recruitment_id', null)->assertJsonPath('data.recruitment', null);
        $this->assertDatabaseHas('category_master', ['catm_name' => 'General Medicine', 'catm_master' => null, 'catm_createby' => $admin->u_id]);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    public function test_null_or_empty_recruitment_is_saved_as_null(mixed $recruitmentId): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => 'General Medicine', 'recruitment_id' => $recruitmentId])
            ->assertCreated()->assertJsonPath('data.recruitment_id', null);
        $this->assertDatabaseHas('category_master', ['catm_name' => 'General Medicine', 'catm_master' => null]);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([['invalid']])]
    public function test_invalid_discipline_names_return_422(mixed $name): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('category_master', 0);
    }

    public function test_name_length_matches_the_existing_150_character_column(): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => str_repeat('A', 151)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ENDPOINT, ['name' => str_repeat('A', 150)])->assertCreated();
        $this->assertDatabaseCount('category_master', 1);
    }

    public function test_unknown_or_locked_recruitment_cannot_be_selected(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $locked = RecruitmentFactory::new()->locked()->create(['rm_createby' => $admin->u_id]);
        foreach ([$locked->rm_id, 999999] as $id) {
            $this->postJson(self::ENDPOINT, ['name' => 'Cardiology', 'recruitment_id' => $id])
                ->assertUnprocessable()->assertJsonPath('errors.recruitment_id.0', 'Select an existing, unlocked recruitment.');
        }
        $this->assertDatabaseCount('category_master', 0);
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
        $this->postJson(self::ENDPOINT, ['name' => 'Cardiology', 'recruitment_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
        $this->assertDatabaseCount('category_master', 0);
    }

    public function test_repeated_names_remain_allowed_as_in_the_legacy_discipline_form(): void
    {
        $admin = $this->signInAsSuperAdmin();
        DisciplineFactory::new()->create(['catm_name' => 'Cardiology', 'catm_createby' => $admin->u_id]);
        $this->postJson(self::ENDPOINT, ['name' => 'Cardiology'])->assertCreated();
        $this->assertDatabaseCount('category_master', 2);
    }

    #[TestWith(['id'])]
    #[TestWith(['status'])]
    #[TestWith(['created_by'])]
    #[TestWith(['created_at'])]
    #[TestWith(['catm_id'])]
    #[TestWith(['catm_status'])]
    #[TestWith(['catm_createby'])]
    #[TestWith(['catm_createdate'])]
    #[TestWith(['catm_master'])]
    public function test_client_cannot_override_server_fields_or_bypass_the_recruitment_check(string $field): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => 'Cardiology', $field => 123])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('category_master', 0);
    }

    public function test_unauthenticated_request_cannot_add_a_discipline(): void
    {
        $record = DisciplineFactory::new()->create();
        $this->postJson(self::ENDPOINT, ['name' => 'Cardiology'])->assertUnauthorized();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->getJson(self::ENDPOINT.'/'.$record->catm_id)->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id.'/lock')->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id.'/unlock')->assertUnauthorized();
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
    public function test_other_staff_roles_cannot_add_disciplines(int $roleId): void
    {
        $role = StaffRoleFactory::new()->create(['mu_id' => $roleId]);
        $staff = StaffUserFactory::new()->for($role, 'role')->create();
        $record = DisciplineFactory::new()->create(['catm_createby' => $staff->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->actingAs($staff, 'staff');
        $this->postJson(self::ENDPOINT, ['name' => 'Cardiology'])->assertForbidden();
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->getJson(self::ENDPOINT.'/'.$record->catm_id)->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id, ['name' => 'Unauthorized'])->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id.'/lock')->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$record->catm_id.'/unlock')->assertForbidden();
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('category_master', 1);
    }

    public function test_disabled_super_admin_cannot_add_disciplines(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $admin->user_status = 0;
        $admin->save();
        $this->postJson(self::ENDPOINT, ['name' => 'Cardiology'])->assertForbidden();
        $this->assertDatabaseCount('category_master', 0);
    }

    public function test_add_discipline_requires_csrf_protection(): void
    {
        $this->signInAsSuperAdmin();
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT, ['name' => 'Cardiology'])->assertStatus(419);
        $this->assertDatabaseCount('category_master', 0);
    }
}
