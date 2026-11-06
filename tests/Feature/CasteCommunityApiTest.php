<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use App\Models\AgeRelaxation;
use App\Models\CasteCommunity;
use App\Models\StaffUser;
use Database\Factories\AgeRelaxationFactory;
use Database\Factories\CasteCommunityFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class CasteCommunityApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/master-data/caste-communities';

    private function signIn(): StaffUser
    {
        $staff = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function caste(StaffUser $staff, array $attributes = []): AgeRelaxation
    {
        return AgeRelaxationFactory::new()->create(array_replace([
            'caste_cat' => 2, 'caste_createby' => $staff->u_id,
        ], $attributes));
    }

    private function community(StaffUser $staff, AgeRelaxation $caste, array $attributes = []): CasteCommunity
    {
        return CasteCommunityFactory::new()->create(array_replace([
            'csdetail_master' => $caste->caste_id, 'csdetail_createby' => $staff->u_id,
        ], $attributes));
    }

    private function url(CasteCommunity $community): string
    {
        return self::ENDPOINT.'/'.$this->publicId('caste-community', $community->csdetail_id);
    }

    public function test_dropdown_returns_only_active_top_level_category_two_castes_in_name_order(): void
    {
        $staff = $this->signIn();
        $last = $this->caste($staff, ['caste_name' => 'Zulu']);
        $first = $this->caste($staff, ['caste_name' => 'Alpha']);
        $this->caste($staff, ['caste_cat' => 8]);
        $this->caste($staff, ['caste_parent' => $first->caste_id]);
        $this->caste($staff, ['caste_status' => 0]);
        $this->getJson(self::ENDPOINT.'/caste-options')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alpha')->assertJsonPath('data.1.name', 'Zulu')
            ->assertJsonPath('data.0.id', $this->matchesPublicId('age-relaxation', $first->caste_id))
            ->assertJsonPath('data.1.id', $this->matchesPublicId('age-relaxation', $last->caste_id));
    }

    public function test_super_admin_can_add_using_the_dropdown_id_and_get_encrypted_details(): void
    {
        $this->freezeTime();
        $staff = $this->signIn();
        $caste = $this->caste($staff);
        $casteId = $this->getJson(self::ENDPOINT.'/caste-options')->assertOk()->json('data.0.id');
        $response = $this->postJson(self::ENDPOINT, ['name' => '  Community A  ', 'caste_id' => $casteId])
            ->assertCreated()->assertJsonPath('message', 'Caste community added successfully.')
            ->assertJsonPath('data.name', 'Community A')->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.is_locked', false)->assertJsonPath('data.caste.name', $caste->caste_name)
            ->assertJsonPath('data.caste_id', $this->matchesPublicId('age-relaxation', $caste->caste_id))
            ->assertJsonPath('data.caste.id', $this->matchesPublicId('age-relaxation', $caste->caste_id))
            ->assertJsonPath('data.created_by', $this->matchesPublicId('staff', $staff->u_id))
            ->assertJsonPath('data.created_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'))
            ->assertJsonPath('data.updated_at', null)->assertJsonPath('data.updated_by', null)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('caste_details_tab', [
            'csdetail_id' => PublicId::decode('caste-community', $response->json('data.id')),
            'csdetail_name' => 'Community A', 'csdetail_master' => $caste->caste_id,
            'csdetail_createby' => $staff->u_id, 'csdetail_status' => 1,
        ]);
        $this->getJson(self::ENDPOINT.'/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.name', 'Community A');
    }

    public function test_list_matches_legacy_category_scope_and_includes_locked_communities_and_locked_parent_castes(): void
    {
        $staff = $this->signIn();
        $caste = $this->caste($staff, ['caste_status' => 0]);
        $first = $this->community($staff, $caste, ['csdetail_name' => 'Alpha', 'csdetail_status' => 0]);
        $this->community($staff, $caste, ['csdetail_name' => 'Zulu']);
        $other = $this->caste($staff, ['caste_cat' => 8]);
        $this->community($staff, $other, ['csdetail_name' => 'Not on this screen']);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $this->matchesPublicId('caste-community', $first->csdetail_id))
            ->assertJsonPath('data.0.is_locked', true)->assertJsonPath('data.1.name', 'Zulu');
    }

    public function test_search_status_caste_filter_and_pagination_preserve_encrypted_filter(): void
    {
        $staff = $this->signIn();
        $caste = $this->caste($staff);
        foreach (['Clinical A', 'Clinical B', 'Clinical C'] as $name) {
            $this->community($staff, $caste, ['csdetail_name' => $name, 'csdetail_status' => 0]);
        }
        $this->community($staff, $caste, ['csdetail_name' => 'Clinical D']);
        $this->community($staff, $this->caste($staff), ['csdetail_name' => 'Clinical Other', 'csdetail_status' => 0]);
        $id = $this->publicId('age-relaxation', $caste->caste_id);
        $response = $this->getJson(self::ENDPOINT.'?search=clinical&status=0&caste_id='.$id.'&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Clinical C')->assertJsonPath('meta.total', 3);
        $this->assertStringContainsString('caste_id='.$id, $response->json('links.prev'));
        $this->community($staff, $caste, ['csdetail_name' => '100% Community']);
        $this->community($staff, $caste, ['csdetail_name' => '1000 Community']);
        $this->getJson(self::ENDPOINT.'?search='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ENDPOINT.'?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_edit_changes_name_and_caste_and_records_modifier_preserving_creation_and_status(): void
    {
        $this->freezeTime();
        $staff = $this->signIn();
        $record = $this->community($staff, $this->caste($staff), [
            'csdetail_status' => 0, 'csdetail_createdate' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);
        $createdAt = $record->refresh()->getRawOriginal('csdetail_createdate');
        $parent = $this->caste($staff);
        $this->patchJson($this->url($record), ['name' => ' Updated ', 'caste_id' => $this->publicId('age-relaxation', $parent->caste_id)])
            ->assertOk()->assertJsonPath('message', 'Caste community updated successfully.')
            ->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.updated_by', $this->matchesPublicId('staff', $staff->u_id))
            ->assertJsonPath('data.updated_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'));
        $record->refresh();
        $this->assertSame($parent->caste_id, $record->csdetail_master);
        $this->assertSame($createdAt, $record->getRawOriginal('csdetail_createdate'));
        $this->assertSame($staff->u_id, $record->csdetail_createby);
    }

    public function test_name_only_edit_preserves_existing_locked_parent_and_repeated_names_remain_allowed(): void
    {
        $staff = $this->signIn();
        $caste = $this->caste($staff);
        $payload = ['name' => 'Same name', 'caste_id' => $this->publicId('age-relaxation', $caste->caste_id)];
        $this->postJson(self::ENDPOINT, $payload)->assertCreated();
        $id = $this->postJson(self::ENDPOINT, $payload)->assertCreated()->json('data.id');
        $caste->caste_status = 0;
        $caste->save();
        $this->patchJson(self::ENDPOINT.'/'.$id, ['name' => 'Changed'])->assertOk();
        $this->patchJson(self::ENDPOINT.'/'.$id, ['caste_id' => $payload['caste_id']])->assertOk();
        $this->assertDatabaseCount('caste_details_tab', 2);
    }

    #[TestWith(['lock', 0])]
    #[TestWith(['unlock', 1])]
    public function test_lock_changes_only_status_and_is_repeatable(string $action, int $status): void
    {
        $staff = $this->signIn();
        $record = $this->community($staff, $this->caste($staff), ['csdetail_status' => 1 - $status]);
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson($this->url($record).'/'.$action)->assertOk()->assertJsonPath('data.status', $status);
        $this->patchJson($this->url($record).'/'.$action)->assertOk();
        $before['csdetail_status'] = $status;
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    #[TestWith(['locked'])]
    #[TestWith(['child'])]
    #[TestWith(['category'])]
    #[TestWith(['missing'])]
    public function test_invalid_parent_selection_is_rejected_for_add_and_edit(string $condition): void
    {
        $staff = $this->signIn();
        $record = $this->community($staff, $this->caste($staff));
        $before = $record->refresh()->getRawOriginal();
        $attributes = match ($condition) {
            'locked' => ['caste_status' => 0],
            'child' => ['caste_parent' => $record->csdetail_master],
            'category' => ['caste_cat' => 8],
            default => [],
        };
        $id = $condition === 'missing' ? 999999 : $this->caste($staff, $attributes)->caste_id;
        $payload = ['name' => 'Must not save', 'caste_id' => $this->publicId('age-relaxation', $id)];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors('caste_id');
        $this->patchJson($this->url($record), $payload)->assertUnprocessable()->assertJsonValidationErrors('caste_id');
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('caste_details_tab', 1);
    }

    #[TestWith(['id'])]
    #[TestWith(['status'])]
    #[TestWith(['created_by'])]
    #[TestWith(['created_at'])]
    #[TestWith(['updated_by'])]
    #[TestWith(['updated_at'])]
    #[TestWith(['csdetail_id'])]
    #[TestWith(['csdetail_master'])]
    #[TestWith(['csdetail_name'])]
    #[TestWith(['csdetail_createdate'])]
    #[TestWith(['csdetail_createby'])]
    #[TestWith(['csdetail_modifydate'])]
    #[TestWith(['csdetail_modifyby'])]
    #[TestWith(['csdetail_status'])]
    public function test_client_cannot_override_internal_or_audit_fields(string $field): void
    {
        $staff = $this->signIn();
        $record = $this->community($staff, $this->caste($staff));
        $before = $record->refresh()->getRawOriginal();
        $payload = ['name' => 'Changed', 'caste_id' => $this->publicId('age-relaxation', $record->csdetail_master), $field => '999'];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($record), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    public function test_invalid_names_and_missing_fields_return_422_and_maximum_name_length_is_accepted(): void
    {
        $staff = $this->signIn();
        $caste = $this->caste($staff);
        $this->postJson(self::ENDPOINT)->assertUnprocessable()->assertJsonValidationErrors(['name', 'caste_id']);
        foreach ([null, '', '   ', ['name'], str_repeat('a', 451)] as $name) {
            $this->postJson(self::ENDPOINT, ['name' => $name, 'caste_id' => $this->publicId('age-relaxation', $caste->caste_id)])
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $id = $this->postJson(self::ENDPOINT, ['name' => str_repeat('a', 450), 'caste_id' => $this->publicId('age-relaxation', $caste->caste_id)])
            ->assertCreated()->json('data.id');
        $this->patchJson(self::ENDPOINT.'/'.$id, [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::ENDPOINT.'/'.$id, ['name' => ' '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::ENDPOINT.'/'.$id, ['caste_id' => null])->assertUnprocessable()->assertJsonValidationErrors('caste_id');
    }

    #[TestWith(['status=2', 'status'])]
    #[TestWith(['per_page=101', 'per_page'])]
    #[TestWith(['per_page=0', 'per_page'])]
    #[TestWith(['page=0', 'page'])]
    #[TestWith(['search[]=bad', 'search'])]
    #[TestWith(['search_length', 'search'])]
    public function test_invalid_list_inputs_return_422(string $query, string $field): void
    {
        $this->signIn();
        $query = $query === 'search_length' ? 'search='.str_repeat('a', 101) : $query;
        $this->getJson(self::ENDPOINT.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_plain_tampered_and_wrong_type_ids_are_rejected(): void
    {
        $staff = $this->signIn();
        $record = $this->community($staff, $this->caste($staff));
        $before = $record->refresh()->getRawOriginal();
        $valid = $this->publicId('caste-community', $record->csdetail_id);
        $tampered = $valid;
        $tampered[40] = $tampered[40] === 'A' ? 'B' : 'A';
        foreach ([(string) $record->csdetail_id, 'invalid', $tampered, $this->publicId('experience', $record->csdetail_id), $this->publicId('caste-community', 999999)] as $id) {
            $url = self::ENDPOINT.'/'.$id;
            $this->getJson($url)->assertNotFound();
            $this->patchJson($url, ['name' => 'Must not save'])->assertNotFound();
            $this->patchJson($url.'/lock')->assertNotFound();
            $this->patchJson($url.'/unlock')->assertNotFound();
        }
        foreach ([1, '1', 'invalid', ['invalid'], $valid, $this->publicId('age-relaxation', 2147483648)] as $id) {
            $this->postJson(self::ENDPOINT, ['name' => 'Must not save', 'caste_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('caste_id');
            $this->patchJson($this->url($record), ['caste_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('caste_id');
            $this->getJson(self::ENDPOINT.'?'.http_build_query(['caste_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('caste_id');
        }
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    public function test_communities_linked_to_other_categories_cannot_be_read_or_changed(): void
    {
        $staff = $this->signIn();
        $record = $this->community($staff, $this->caste($staff, ['caste_cat' => 8]));
        $before = $record->refresh()->getRawOriginal();
        $this->getJson($this->url($record))->assertNotFound();
        $this->patchJson($this->url($record), ['name' => 'Must not save'])->assertNotFound();
        $this->patchJson($this->url($record).'/lock')->assertNotFound();
        $this->patchJson($this->url($record).'/unlock')->assertNotFound();
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    public function test_guests_cannot_access_any_endpoint(): void
    {
        $staff = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $record = $this->community($staff, $this->caste($staff));
        $this->assertAllEndpointsRefuse($record, 401);
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
    public function test_other_roles_cannot_access_any_endpoint(int $roleId): void
    {
        $role = StaffRoleFactory::new()->create(['mu_id' => $roleId]);
        $staff = StaffUserFactory::new()->for($role, 'role')->create();
        $this->actingAs($staff, 'staff');
        $record = $this->community($staff, $this->caste($staff));
        $this->assertAllEndpointsRefuse($record, 403);
    }

    private function assertAllEndpointsRefuse(CasteCommunity $record, int $status): void
    {
        $before = $record->refresh()->getRawOriginal();
        $this->getJson(self::ENDPOINT)->assertStatus($status);
        $this->getJson(self::ENDPOINT.'/caste-options')->assertStatus($status);
        $this->getJson($this->url($record))->assertStatus($status);
        $this->postJson(self::ENDPOINT, ['name' => 'Must not save'])->assertStatus($status);
        $this->patchJson($this->url($record), ['name' => 'Must not save'])->assertStatus($status);
        $this->patchJson($this->url($record).'/lock')->assertStatus($status);
        $this->patchJson($this->url($record).'/unlock')->assertStatus($status);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('caste_details_tab', 1);
    }

    public function test_disabled_super_admin_is_refused_and_writes_require_csrf(): void
    {
        $staff = $this->signIn();
        $record = $this->community($staff, $this->caste($staff));
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT, ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson($this->url($record), ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson($this->url($record).'/lock')->assertStatus(419);
        $this->patchJson($this->url($record).'/unlock')->assertStatus(419);
        $staff->user_status = 0;
        $staff->save();
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->assertDatabaseCount('caste_details_tab', 1);
    }
}
