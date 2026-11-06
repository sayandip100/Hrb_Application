<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use App\Models\StaffUser;
use Database\Factories\CasteIssuingAuthorityFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class CasteIssuingAuthorityApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/master-data/caste-issuing-authorities';

    private function signInAsSuperAdmin(): StaffUser
    {
        $admin = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($admin, 'staff');

        return $admin;
    }

    public function test_super_admin_can_create_caste_issuing_authority_with_server_owned_creation_metadata(): void
    {
        $this->freezeTime();
        $admin = $this->signInAsSuperAdmin();
        $response = $this->postJson(self::ENDPOINT, ['name' => '  District Authority  '])
            ->assertCreated()->assertJsonPath('message', 'Caste issuing authority added successfully.')
            ->assertJsonPath('data.name', 'District Authority')->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.is_locked', false)->assertJsonPath('data.created_by', $this->matchesPublicId('staff', $admin->u_id))
            ->assertJsonPath('data.created_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'))
            ->assertJsonPath('data.updated_by', null)->assertJsonPath('data.updated_at', null);
        $this->assertDatabaseHas('caste_issuing_auth_tab', [
            'cia_id' => PublicId::decode('caste-issuing-authority', $response->json('data.id')), 'cia_name' => 'District Authority',
            'cia_createby' => $admin->u_id, 'cia_modifyby' => null, 'cia_status' => 1,
        ]);
    }

    public function test_listing_returns_active_and_locked_authorities_alphabetically(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $first = CasteIssuingAuthorityFactory::new()->create(['cia_name' => 'Zulu Authority', 'cia_createby' => $admin->u_id]);
        $last = CasteIssuingAuthorityFactory::new()->create(['cia_name' => 'Alpha Authority', 'cia_createby' => $admin->u_id, 'cia_status' => 0]);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $this->matchesPublicId('caste-issuing-authority', $last->cia_id))->assertJsonPath('data.0.is_locked', true)
            ->assertJsonPath('data.1.id', $this->matchesPublicId('caste-issuing-authority', $first->cia_id))->assertJsonPath('meta.total', 2);
    }

    public function test_list_supports_search_status_and_pagination(): void
    {
        $admin = $this->signInAsSuperAdmin();
        CasteIssuingAuthorityFactory::new()->count(3)->sequence(
            ['cia_name' => 'Clinical A'], ['cia_name' => 'Clinical B'], ['cia_name' => 'Clinical C'],
        )->create(['cia_createby' => $admin->u_id, 'cia_status' => 0]);
        CasteIssuingAuthorityFactory::new()->create(['cia_name' => 'Clinical D', 'cia_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'?search=clinical&status=0&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Clinical C')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
    }

    public function test_search_treats_wildcards_and_sql_as_literal_text(): void
    {
        $admin = $this->signInAsSuperAdmin();
        CasteIssuingAuthorityFactory::new()->create(['cia_name' => '100% Clinical', 'cia_createby' => $admin->u_id]);
        CasteIssuingAuthorityFactory::new()->create(['cia_name' => '1000 Clinical', 'cia_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'?search='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ENDPOINT.'?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_details_returns_the_record_for_editing(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = CasteIssuingAuthorityFactory::new()->create(['cia_createby' => $admin->u_id]);
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id))->assertOk()
            ->assertJsonPath('data.id', $this->matchesPublicId('caste-issuing-authority', $record->cia_id))->assertJsonPath('data.name', $record->cia_name);
    }

    public function test_edit_records_the_current_modifier_and_preserves_original_creator_date_and_status(): void
    {
        $this->freezeTime();
        $admin = $this->signInAsSuperAdmin();
        $creator = StaffUserFactory::new()->for($admin->role, 'role')->create();
        $record = CasteIssuingAuthorityFactory::new()->create([
            'cia_createby' => $creator->u_id, 'cia_status' => 0,
            'cia_createdate' => now('Asia/Kolkata')->subDay()->format('Y-m-d H:i:s'),
        ]);
        $originalDate = $record->refresh()->getRawOriginal('cia_createdate');
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id), ['name' => '  Subdivisional Authority  '])
            ->assertOk()->assertJsonPath('message', 'Caste issuing authority updated successfully.')
            ->assertJsonPath('data.name', 'Subdivisional Authority')->assertJsonPath('data.created_by', $this->matchesPublicId('staff', $creator->u_id))
            ->assertJsonPath('data.updated_by', $this->matchesPublicId('staff', $admin->u_id))
            ->assertJsonPath('data.updated_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'))
            ->assertJsonPath('data.status', 0);
        $this->assertDatabaseHas('caste_issuing_auth_tab', [
            'cia_id' => $record->cia_id, 'cia_name' => 'Subdivisional Authority',
            'cia_modifyby' => $admin->u_id, 'cia_createby' => $creator->u_id,
        ]);
        $this->assertSame($originalDate, $record->refresh()->getRawOriginal('cia_createdate'));
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_duplicate_names_are_rejected_ignoring_case_whitespace_and_status(int $status): void
    {
        $admin = $this->signInAsSuperAdmin();
        CasteIssuingAuthorityFactory::new()->create(['cia_name' => ' District Authority ', 'cia_createby' => $admin->u_id, 'cia_status' => $status]);
        $this->postJson(self::ENDPOINT, ['name' => 'district authority'])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'A caste issuing authority with this name already exists.');
        $this->assertDatabaseCount('caste_issuing_auth_tab', 1);
    }

    public function test_edit_can_keep_its_own_name_but_cannot_take_another_records_name(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = CasteIssuingAuthorityFactory::new()->create(['cia_name' => 'Clinical', 'cia_createby' => $admin->u_id]);
        CasteIssuingAuthorityFactory::new()->create(['cia_name' => 'Teaching', 'cia_createby' => $admin->u_id]);
        $url = self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id);
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
        $record = CasteIssuingAuthorityFactory::new()->create(['cia_createby' => $admin->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->postJson(self::ENDPOINT, ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id), ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('caste_issuing_auth_tab', 1);
    }

    public function test_name_length_matches_the_existing_column(): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::ENDPOINT, ['name' => str_repeat('A', 251)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ENDPOINT, ['name' => str_repeat('A', 250)])->assertCreated();
        $this->assertDatabaseCount('caste_issuing_auth_tab', 1);
    }

    #[TestWith(['id'])]
    #[TestWith(['status'])]
    #[TestWith(['created_by'])]
    #[TestWith(['created_at'])]
    #[TestWith(['updated_by'])]
    #[TestWith(['updated_at'])]
    #[TestWith(['cia_id'])]
    #[TestWith(['cia_name'])]
    #[TestWith(['cia_status'])]
    #[TestWith(['cia_createby'])]
    #[TestWith(['cia_createdate'])]
    #[TestWith(['cia_modifyby'])]
    #[TestWith(['cia_modifydate'])]
    #[TestWith(['recruitment_id'])]
    public function test_client_cannot_override_server_owned_fields(string $field): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = CasteIssuingAuthorityFactory::new()->create(['cia_createby' => $admin->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $payload = ['name' => 'Different name', $field => 123];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('caste_issuing_auth_tab', 1);
    }

    #[TestWith(['lock', 1, 0, true, 'Caste issuing authority locked successfully.'])]
    #[TestWith(['unlock', 0, 1, false, 'Caste issuing authority unlocked successfully.'])]
    public function test_lock_actions_change_only_status_and_can_be_repeated(string $action, int $beforeStatus, int $afterStatus, bool $locked, string $message): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = CasteIssuingAuthorityFactory::new()->create(['cia_createby' => $admin->u_id, 'cia_status' => $beforeStatus]);
        $before = $record->refresh()->getRawOriginal();
        $url = self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id).'/'.$action;
        $this->patchJson($url, ['name' => 'Ignored'])->assertOk()->assertJsonPath('message', $message)
            ->assertJsonPath('data.status', $afterStatus)->assertJsonPath('data.is_locked', $locked);
        $this->patchJson($url)->assertOk();
        $this->assertSame(array_replace($before, ['cia_status' => $afterStatus]), $record->refresh()->getRawOriginal());
    }

    public function test_missing_caste_issuing_authority_returns_404(): void
    {
        $this->signInAsSuperAdmin();
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', 999999))->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', 999999), ['name' => 'New'])->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', 999999).'/lock')->assertNotFound();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', 999999).'/unlock')->assertNotFound();
    }

    public function test_guests_cannot_access_any_caste_issuing_authority_endpoint(): void
    {
        $record = CasteIssuingAuthorityFactory::new()->create();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id))->assertUnauthorized();
        $this->postJson(self::ENDPOINT, ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id), ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id).'/lock')->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id).'/unlock')->assertUnauthorized();
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
    public function test_other_roles_cannot_access_any_caste_issuing_authority_endpoint(int $roleId): void
    {
        $role = StaffRoleFactory::new()->create(['mu_id' => $roleId]);
        $staff = StaffUserFactory::new()->for($role, 'role')->create();
        $record = CasteIssuingAuthorityFactory::new()->create(['cia_createby' => $staff->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->actingAs($staff, 'staff');
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->getJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id))->assertForbidden();
        $this->postJson(self::ENDPOINT, ['name' => 'Unauthorized'])->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id), ['name' => 'Unauthorized'])->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id).'/lock')->assertForbidden();
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id).'/unlock')->assertForbidden();
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('caste_issuing_auth_tab', 1);
    }

    public function test_disabled_super_admin_cannot_create_caste_issuing_authorities(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $admin->user_status = 0;
        $admin->save();
        $this->postJson(self::ENDPOINT, ['name' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseCount('caste_issuing_auth_tab', 0);
    }

    public function test_write_operations_require_csrf(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = CasteIssuingAuthorityFactory::new()->create(['cia_createby' => $admin->u_id]);
        $before = $record->refresh()->getRawOriginal();
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT, ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id), ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id).'/lock')->assertStatus(419);
        $this->patchJson(self::ENDPOINT.'/'.$this->publicId('caste-issuing-authority', $record->cia_id).'/unlock')->assertStatus(419);
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
