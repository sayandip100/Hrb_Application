<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use App\Models\StaffUser;
use Database\Factories\AgeRelaxationFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class AgeRelaxationApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/master-data/age-relaxations';

    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    #[TestWith([5])]
    #[TestWith([6])]
    #[TestWith([7])]
    #[TestWith([null])]
    public function test_super_admin_can_manage_all_shared_categories_without_changing_their_category_or_hierarchy(?int $category): void
    {
        $admin = $this->signInAsSuperAdmin();
        $age = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id]);
        $other = AgeRelaxationFactory::new()->create([
            'caste_cat' => $category, 'caste_parent' => $age->caste_id,
            'caste_attach' => 1, 'caste_createby' => $admin->u_id,
        ]);
        $before = $other->refresh()->getRawOriginal();
        $untouched = $age->refresh()->getRawOriginal();
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $this->matchesPublicId('age-relaxation', $other->caste_id))->assertJsonPath('data.1.id', $this->matchesPublicId('age-relaxation', $age->caste_id));
        $url = self::ENDPOINT.'/'.$this->publicId('age-relaxation', $other->caste_id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.category', $category);
        $this->patchJson($url, ['name' => 'Updated category'])->assertOk()
            ->assertJsonPath('data.name', 'Updated category')->assertJsonPath('data.category', $category);
        $this->patchJson($url.'/lock')->assertOk()->assertJsonPath('data.status', 0);
        $this->patchJson($url.'/unlock')->assertOk()->assertJsonPath('data.status', 1);
        $this->assertSame(array_replace($before, ['caste_name' => 'Updated category']), $other->refresh()->getRawOriginal());
        $this->assertSame($untouched, $age->refresh()->getRawOriginal());
    }

    public function test_name_uniqueness_checks_other_categories_in_the_shared_table_like_ci3(): void
    {
        $admin = $this->signInAsSuperAdmin();
        AgeRelaxationFactory::new()->create(['caste_name' => 'Existing category', 'caste_cat' => 2, 'caste_createby' => $admin->u_id]);
        $this->postJson(self::ENDPOINT, ['name' => ' existing CATEGORY '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('caste_tab', 1);
    }

    private function signInAsSuperAdmin(): StaffUser
    {
        $admin = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($admin, 'staff');

        return $admin;
    }

    public function test_super_admin_can_create_age_relaxation_with_server_owned_creation_metadata(): void
    {
        $this->freezeTime();
        $admin = $this->signInAsSuperAdmin();
        $response = $this->postJson(self::ENDPOINT, ['name' => '  Special Age Relaxation  '])
            ->assertCreated()->assertJsonPath('message', 'Age relaxation added successfully.')
            ->assertJsonPath('data.name', 'Special Age Relaxation')->assertJsonPath('data.category', 8)
            ->assertJsonPath('data.status', 1)->assertJsonPath('data.is_locked', false)
            ->assertJsonPath('data.created_by', $this->matchesPublicId('staff', $admin->u_id))
            ->assertJsonPath('data.created_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('caste_tab', [
            'caste_id' => PublicId::decode('age-relaxation', $response->json('data.id')), 'caste_name' => 'Special Age Relaxation',
            'caste_createby' => $admin->u_id, 'caste_cat' => 8, 'caste_parent' => null, 'caste_attach' => null, 'caste_status' => 1,
        ]);
    }

    public function test_listing_returns_active_and_locked_age_relaxations_newest_first(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $first = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id]);
        $last = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id, 'caste_status' => 0]);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $this->matchesPublicId('age-relaxation', $last->caste_id))->assertJsonPath('data.0.is_locked', true)
            ->assertJsonPath('data.1.id', $this->matchesPublicId('age-relaxation', $first->caste_id))->assertJsonPath('meta.total', 2);
    }

    public function test_list_supports_search_status_and_pagination(): void
    {
        $admin = $this->signInAsSuperAdmin();
        AgeRelaxationFactory::new()->count(3)->sequence(
            ['caste_name' => 'Clinical A'], ['caste_name' => 'Clinical B'], ['caste_name' => 'Clinical C'],
        )->create(['caste_createby' => $admin->u_id, 'caste_status' => 0]);
        AgeRelaxationFactory::new()->create(['caste_name' => 'Clinical D', 'caste_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'?search=clinical&status=0&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Clinical A')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
    }

    public function test_search_treats_wildcards_and_sql_as_literal_text(): void
    {
        $admin = $this->signInAsSuperAdmin();
        AgeRelaxationFactory::new()->create(['caste_name' => '100% Clinical', 'caste_createby' => $admin->u_id]);
        AgeRelaxationFactory::new()->create(['caste_name' => '1000 Clinical', 'caste_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'?search='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ENDPOINT.'?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_details_returns_the_record_for_editing(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id))->assertOk()
            ->assertJsonPath('data.id', $this->matchesPublicId('age-relaxation', $record->caste_id))->assertJsonPath('data.name', $record->caste_name);
    }

    public function test_edit_changes_only_the_age_relaxation_name(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id, 'caste_status' => 0]);
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id), ['name' => '  Updated age relaxation  '])
            ->assertOk()->assertJsonPath('message', 'Age relaxation updated successfully.')
            ->assertJsonPath('data.name', 'Updated age relaxation')->assertJsonPath('data.status', 0);
        $this->assertSame(array_replace($before, ['caste_name' => 'Updated age relaxation']), $record->refresh()->getRawOriginal());
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_duplicate_names_are_rejected_ignoring_case_whitespace_and_status(int $status): void
    {
        $admin = $this->signInAsSuperAdmin();
        AgeRelaxationFactory::new()->create(['caste_name' => ' Special Age Relaxation ', 'caste_createby' => $admin->u_id, 'caste_status' => $status]);
        $this->postJson(self::ENDPOINT, ['name' => 'special age relaxation'])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'An age relaxation with this name already exists.');
        $this->assertDatabaseCount('caste_tab', 1);
    }

    public function test_edit_can_keep_its_own_name_but_cannot_take_another_records_name(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = AgeRelaxationFactory::new()->create(['caste_name' => 'Clinical', 'caste_createby' => $admin->u_id]);
        AgeRelaxationFactory::new()->create(['caste_name' => 'Teaching', 'caste_createby' => $admin->u_id]);
        $url = self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id);
        $this->patchJson($url, ['name' => 'Clinical'])->assertOk();
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson($url, ['name' => ' teaching '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([['invalid']])]
    public function test_name_is_required_and_must_be_a_string_for_add_and_edit(mixed $name): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->postJson(self::ENDPOINT, ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id), ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('caste_tab', 1);
    }

    public function test_name_length_matches_the_existing_column(): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => str_repeat('A', 21845)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ENDPOINT, ['name' => str_repeat('A', 21844)])->assertCreated();
        $this->assertDatabaseCount('caste_tab', 1);
    }

    #[TestWith(['id'])]
    #[TestWith(['status'])]
    #[TestWith(['created_by'])]
    #[TestWith(['created_at'])]
    #[TestWith(['updated_by'])]
    #[TestWith(['updated_at'])]
    #[TestWith(['caste_id'])]
    #[TestWith(['caste_status'])]
    #[TestWith(['caste_createby'])]
    #[TestWith(['caste_createdate'])]
    #[TestWith(['category'])]
    #[TestWith(['parent_id'])]
    #[TestWith(['caste_cat'])]
    #[TestWith(['caste_parent'])]
    #[TestWith(['caste_attach'])]
    public function test_client_cannot_override_server_owned_fields(string $field): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $payload = ['name' => 'Different name', $field => 123];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('caste_tab', 1);
    }

    #[TestWith(['lock', 1, 0, true, 'Age relaxation locked successfully.'])]
    #[TestWith(['unlock', 0, 1, false, 'Age relaxation unlocked successfully.'])]
    public function test_lock_actions_change_only_status_and_can_be_repeated(string $action, int $beforeStatus, int $afterStatus, bool $locked, string $message): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id, 'caste_status' => $beforeStatus]);
        $before = $record->refresh()->getRawOriginal();
        $url = self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id).'/'.$action;
        $this->patchJson($url, ['name' => 'Ignored'])->assertOk()->assertJsonPath('message', $message)
            ->assertJsonPath('data.status', $afterStatus)->assertJsonPath('data.is_locked', $locked);
        $this->patchJson($url)->assertOk();
        $this->assertSame(array_replace($before, ['caste_status' => $afterStatus]), $record->refresh()->getRawOriginal());
    }

    public function test_missing_age_relaxation_returns_404(): void
    {
        $this->signInAsSuperAdmin();
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', 999999))->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', 999999), ['name' => 'New'])->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', 999999).'/lock')->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', 999999).'/unlock')->assertNotFound();
    }

    public function test_guests_cannot_access_any_age_relaxation_endpoint(): void
    {
        $record = AgeRelaxationFactory::new()->create();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id))->assertUnauthorized();
        $this->postJson(self::ENDPOINT, ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id), ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id).'/lock')->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id).'/unlock')->assertUnauthorized();
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
    public function test_other_roles_cannot_access_any_age_relaxation_endpoint(int $roleId): void
    {
        $role = StaffRoleFactory::new()->create(['mu_id' => $roleId]);
        $staff = StaffUserFactory::new()->for($role, 'role')->create();
        $record = AgeRelaxationFactory::new()->create(['caste_createby' => $staff->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->actingAs($staff, 'staff');
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id))->assertForbidden();
        $this->postJson(self::ENDPOINT, ['name' => 'Unauthorized'])->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id), ['name' => 'Unauthorized'])->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id).'/lock')->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id).'/unlock')->assertForbidden();
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('caste_tab', 1);
    }

    public function test_disabled_super_admin_cannot_create_age_relaxations(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $admin->user_status = 0;
        $admin->save();
        $this->postJson(self::ENDPOINT, ['name' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseCount('caste_tab', 0);
    }

    public function test_write_operations_require_csrf(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = AgeRelaxationFactory::new()->create(['caste_createby' => $admin->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT, ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id), ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id).'/lock')->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('age-relaxation', $record->caste_id).'/unlock')->assertStatus(419);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    #[TestWith(['status=2', 'status'])]
    #[TestWith(['per_page=101', 'per_page'])]
    #[TestWith(['page=0', 'page'])]
    #[TestWith(['search[]=invalid', 'search'])]
    public function test_invalid_list_filters_return_422(string $query, string $field): void
    {
        $this->signInAsSuperAdmin();
        $this->getJson(self::ENDPOINT.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}
