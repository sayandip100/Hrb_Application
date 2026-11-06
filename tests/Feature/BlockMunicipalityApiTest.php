<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use App\Models\BlockMunicipality;
use App\Models\District;
use App\Models\StaffUser;
use App\Models\Subdivision;
use Database\Factories\BlockMunicipalityFactory;
use Database\Factories\DistrictFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Database\Factories\SubdivisionFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class BlockMunicipalityApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/master-data/block-municipalities';

    private function signIn(): StaffUser
    {
        $staff = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function district(StaffUser $staff, array $attributes = []): District
    {
        return DistrictFactory::new()->create(array_replace(['dist_createby' => $staff->u_id], $attributes));
    }

    private function subdivision(StaffUser $staff, District $district, array $attributes = []): Subdivision
    {
        return SubdivisionFactory::new()->create(array_replace([
            'subdiv_createby' => $staff->u_id, 'subdiv_district' => $district->district_code,
        ], $attributes));
    }

    private function record(StaffUser $staff, District $district, array $attributes = []): BlockMunicipality
    {
        return BlockMunicipalityFactory::new()->create(array_replace([
            'block_createby' => $staff->u_id, 'district_id' => $district->blockCode(),
        ], $attributes));
    }

    private function payload(District $district, array $attributes = []): array
    {
        return array_replace(['name' => 'Example Block', 'type' => 'Block', 'district_id' => $this->publicId('district', $district->district_id)], $attributes);
    }

    private function url(BlockMunicipality $record): string
    {
        return self::ENDPOINT.'/'.$this->publicId('block-municipality', $record->block_id);
    }

    #[TestWith(['Block', false])]
    #[TestWith(['Municipality', false])]
    #[TestWith(['Block', true])]
    #[TestWith(['Municipality', true])]
    public function test_create_with_optional_subdivision_saves_numeric_district_code_and_returns_encrypted_ids(string $type, bool $linked): void
    {
        $this->freezeTime();
        $staff = $this->signIn();
        $district = $this->district($staff, ['district_code' => '000001234']);
        $subdivision = $linked ? $this->subdivision($staff, $district) : null;
        $payload = $this->payload($district, ['name' => '  Example  ', 'type' => $type]);
        if ($linked) {
            $payload['subdivision_id'] = $this->publicId('subdivision', $subdivision->subdiv_id);
        }
        $response = $this->postJson(self::ENDPOINT, $payload)->assertCreated()
            ->assertJsonPath('message', 'Block/Municipality added successfully.')
            ->assertJsonPath('data.name', 'Example')->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.district_id', $this->matchesPublicId('district', $district->district_id))
            ->assertJsonPath('data.district.id', $this->matchesPublicId('district', $district->district_id))
            ->assertJsonPath('data.district.name', $district->district_name)
            ->assertJsonPath('data.created_by', $this->matchesPublicId('staff', $staff->u_id))
            ->assertJsonPath('data.created_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'))
            ->assertJsonPath('data.updated_by', null)->assertJsonPath('data.updated_at', null)
            ->assertJsonPath('data.status', 1)->assertJsonPath('data.is_locked', false);
        if ($linked) {
            $response->assertJsonPath('data.subdivision_id', $this->matchesPublicId('subdivision', $subdivision->subdiv_id))
                ->assertJsonPath('data.subdivision.name', $subdivision->subdiv_name);
        } else {
            $response->assertJsonPath('data.subdivision_id', null)->assertJsonPath('data.subdivision', null);
        }
        $this->assertDatabaseHas('block_master', [
            'block_id' => PublicId::decode('block-municipality', $response->json('data.id')),
            'district_id' => 1234, 'subd_id' => $subdivision?->subdiv_id, 'block_name' => 'Example',
            'block_type' => $type, 'block_createby' => $staff->u_id, 'block_status' => 1,
        ]);
        $this->getJson(self::ENDPOINT.'/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.name', 'Example');
    }

    public function test_district_dropdown_excludes_locked_invalid_overflow_and_ambiguous_numeric_codes(): void
    {
        $staff = $this->signIn();
        $this->district($staff, ['district_code' => '000', 'district_name' => 'Alpha']);
        $this->district($staff, ['district_code' => '2147483647', 'district_name' => 'Zulu']);
        $this->district($staff, ['district_status' => 0]);
        foreach (['invalid', '-1', '1.5', '2147483648', '0123', '123'] as $code) {
            $this->district($staff, ['district_code' => $code]);
        }
        $this->getJson(self::ENDPOINT.'/district-options')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alpha')->assertJsonPath('data.1.name', 'Zulu');
    }

    public function test_subdivision_dropdown_is_filtered_to_active_rows_in_selected_district_and_ids_work_in_create(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff, ['district_name' => 'Selected District']);
        $subdivision = $this->subdivision($staff, $district, ['subdiv_name' => 'Active']);
        $this->subdivision($staff, $district, ['subdiv_status' => 0]);
        $this->subdivision($staff, $this->district($staff, ['district_name' => 'Other District']));
        $options = $this->getJson(self::ENDPOINT.'/district-options')->assertOk()->json('data');
        $districtId = collect($options)->firstWhere('name', 'Selected District')['id'];
        $id = $this->getJson(self::ENDPOINT.'/subdivision-options?district_id='.$districtId)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Active')
            ->assertJsonPath('data.0.id', $this->matchesPublicId('subdivision', $subdivision->subdiv_id))->json('data.0.id');
        $this->postJson(self::ENDPOINT, $this->payload($district, ['subdivision_id' => $id]))->assertCreated();
        $this->getJson(self::ENDPOINT.'/subdivision-options')->assertUnprocessable()->assertJsonValidationErrors('district_id');
    }

    public function test_list_includes_active_locked_and_null_subdivision_records_newest_first_and_omits_orphan_districts(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff, ['district_status' => 0]);
        $this->record($staff, $district, ['block_name' => 'First']);
        $last = $this->record($staff, $district, ['block_name' => 'Last', 'block_status' => 0, 'subd_id' => 999999]);
        BlockMunicipalityFactory::new()->create(['district_id' => -1, 'block_createby' => $staff->u_id]);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $this->matchesPublicId('block-municipality', $last->block_id))
            ->assertJsonPath('data.0.is_locked', true)->assertJsonPath('data.0.subdivision', null)->assertJsonPath('data.1.name', 'First');
    }

    public function test_list_filters_and_pagination_preserve_encrypted_identifiers_and_literal_search(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $subdivision = $this->subdivision($staff, $district);
        foreach (['Place A', 'Place B', 'Place C'] as $name) {
            $this->record($staff, $district, ['block_name' => $name, 'subd_id' => $subdivision->subdiv_id, 'block_status' => 0]);
        }
        $this->record($staff, $district, ['block_type' => 'Municipality', 'subd_id' => $subdivision->subdiv_id]);
        $this->record($staff, $district, ['block_name' => 'Unlinked']);
        $query = http_build_query([
            'search' => 'place', 'status' => 0, 'type' => 'Block', 'district_id' => $this->publicId('district', $district->district_id),
            'subdivision_id' => $this->publicId('subdivision', $subdivision->subdiv_id), 'per_page' => 2, 'page' => 2,
        ]);
        $response = $this->getJson(self::ENDPOINT.'?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Place A')->assertJsonPath('meta.total', 3);
        $this->assertStringContainsString('district_id=', $response->json('links.prev'));
        $this->getJson(self::ENDPOINT.'?subdivision_id=')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Unlinked');
        $this->getJson(self::ENDPOINT.'?district_id='.$this->publicId('district', 999999))->assertOk()->assertJsonCount(0, 'data');
        $this->record($staff, $district, ['block_name' => '100% Place']);
        $this->record($staff, $district, ['block_name' => '1000 Place']);
        $this->getJson(self::ENDPOINT.'?search='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ENDPOINT.'?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_edit_changes_fields_preserves_creation_and_code_and_records_modifier(): void
    {
        $this->freezeTime();
        $staff = $this->signIn();
        $creator = StaffUserFactory::new()->for($staff->role, 'role')->create();
        $record = $this->record($staff, $this->district($staff), [
            'block_createby' => $creator->u_id, 'block_createdate' => now()->subDay()->format('Y-m-d H:i:s'),
            'block_code' => 'LEGACY', 'block_status' => 0,
        ]);
        $date = $record->refresh()->getRawOriginal('block_createdate');
        $district = $this->district($staff);
        $subdivision = $this->subdivision($staff, $district);
        $this->patchJson($this->url($record), $this->payload($district, [
            'name' => ' Changed ', 'type' => 'Municipality', 'subdivision_id' => $this->publicId('subdivision', $subdivision->subdiv_id),
        ]))->assertOk()->assertJsonPath('data.name', 'Changed')->assertJsonPath('data.type', 'Municipality')
            ->assertJsonPath('data.is_locked', true)->assertJsonPath('data.updated_by', $this->matchesPublicId('staff', $staff->u_id));
        $record->refresh();
        $this->assertSame($district->blockCode(), $record->district_id);
        $this->assertSame($subdivision->subdiv_id, $record->subd_id);
        $this->assertSame($date, $record->getRawOriginal('block_createdate'));
        $this->assertSame($creator->u_id, $record->block_createby);
        $this->assertSame('LEGACY', $record->block_code);
    }

    public function test_changing_district_requires_matching_or_cleared_subdivision_and_omission_preserves_link(): void
    {
        $staff = $this->signIn();
        $first = $this->district($staff);
        $subdivision = $this->subdivision($staff, $first);
        $record = $this->record($staff, $first, ['subd_id' => $subdivision->subdiv_id]);
        $this->patchJson($this->url($record), ['name' => 'Renamed'])->assertOk();
        $this->assertSame($subdivision->subdiv_id, $record->refresh()->subd_id);
        $other = $this->district($staff);
        $this->patchJson($this->url($record), ['district_id' => $this->publicId('district', $other->district_id)])
            ->assertUnprocessable()->assertJsonValidationErrors('subdivision_id');
        $this->assertSame($first->blockCode(), $record->refresh()->district_id);
        $this->patchJson($this->url($record), ['district_id' => $this->publicId('district', $other->district_id), 'subdivision_id' => null])
            ->assertOk()->assertJsonPath('data.subdivision_id', null);
        $this->assertSame($other->blockCode(), $record->refresh()->district_id);
        $this->assertNull($record->subd_id);
    }

    public function test_name_edit_can_preserve_existing_locked_parents(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff, ['district_status' => 0]);
        $subdivision = $this->subdivision($staff, $district, ['subdiv_status' => 0]);
        $record = $this->record($staff, $district, ['subd_id' => $subdivision->subdiv_id]);
        $this->patchJson($this->url($record), ['name' => 'Renamed'])->assertOk();
        $this->patchJson($this->url($record), ['district_id' => $this->publicId('district', $district->district_id), 'subdivision_id' => $this->publicId('subdivision', $subdivision->subdiv_id)])
            ->assertOk();
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_duplicates_within_subdivision_are_rejected_for_add_and_edit_regardless_of_type_or_status(int $status): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $subdivision = $this->subdivision($staff, $district);
        $this->record($staff, $district, ['block_name' => ' Existing ', 'subd_id' => $subdivision->subdiv_id, 'block_status' => $status]);
        $record = $this->record($staff, $district, ['block_name' => 'Other', 'subd_id' => $subdivision->subdiv_id]);
        $before = $record->refresh()->getRawOriginal();
        $payload = $this->payload($district, ['name' => 'existing', 'type' => 'Municipality', 'subdivision_id' => $this->publicId('subdivision', $subdivision->subdiv_id)]);
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url($record), $payload)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->patchJson($this->url($record), ['name' => ' other '])->assertOk();
        $this->assertDatabaseCount('block_master', 2);
    }

    public function test_legacy_duplicate_scope_uses_whole_district_when_subdivision_is_empty(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $first = $this->subdivision($staff, $district);
        $second = $this->subdivision($staff, $district);
        $this->record($staff, $district, ['block_name' => 'Existing', 'subd_id' => $first->subdiv_id]);
        $this->postJson(self::ENDPOINT, $this->payload($district, ['name' => 'existing', 'subdivision_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ENDPOINT, $this->payload($district, ['name' => 'Existing', 'subdivision_id' => $this->publicId('subdivision', $second->subdiv_id)]))->assertCreated();
        $this->postJson(self::ENDPOINT, $this->payload($this->district($staff), ['name' => 'Existing']))->assertCreated();
        $unlinked = $this->record($staff, $district, ['block_name' => 'Unlinked']);
        $this->postJson(self::ENDPOINT, $this->payload($district, ['name' => 'Unlinked', 'subdivision_id' => $this->publicId('subdivision', $first->subdiv_id)]))->assertCreated();
        $this->patchJson($this->url($unlinked), ['name' => 'Existing'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    #[TestWith(['locked'])]
    #[TestWith(['invalid'])]
    #[TestWith(['overflow'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['missing'])]
    public function test_unselectable_district_is_rejected_for_create_edit_and_subdivision_dropdown(string $condition): void
    {
        $staff = $this->signIn();
        $record = $this->record($staff, $this->district($staff));
        $attributes = match ($condition) {
            'locked' => ['district_status' => 0], 'invalid' => ['district_code' => 'ABC'],
            'overflow' => ['district_code' => '2147483648'], 'duplicate' => ['district_code' => '00999'], default => [],
        };
        $id = $condition === 'missing' ? 999999 : $this->district($staff, $attributes)->district_id;
        if ($condition === 'duplicate') {
            $this->district($staff, ['district_code' => '999']);
        }
        $token = $this->publicId('district', $id);
        $this->postJson(self::ENDPOINT, ['name' => 'New', 'type' => 'Block', 'district_id' => $token])->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $this->patchJson($this->url($record), ['district_id' => $token])->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $this->getJson(self::ENDPOINT.'/subdivision-options?district_id='.$token)->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $this->assertDatabaseCount('block_master', 1);
    }

    #[TestWith(['locked'])]
    #[TestWith(['other-district'])]
    #[TestWith(['missing'])]
    public function test_subdivision_must_be_active_and_belong_to_district(string $condition): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $record = $this->record($staff, $district);
        $id = $condition === 'missing' ? 999999 : $this->subdivision($staff, $condition === 'other-district' ? $this->district($staff) : $district, ['subdiv_status' => $condition === 'locked' ? 0 : 1])->subdiv_id;
        $payload = $this->payload($district, ['subdivision_id' => $this->publicId('subdivision', $id)]);
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors('subdivision_id');
        $this->patchJson($this->url($record), $payload)->assertUnprocessable()->assertJsonValidationErrors('subdivision_id');
        $this->assertNull($record->refresh()->subd_id);
    }

    #[TestWith(['lock', 0])]
    #[TestWith(['unlock', 1])]
    public function test_lock_actions_change_only_status_and_are_repeatable(string $action, int $status): void
    {
        $staff = $this->signIn();
        $record = $this->record($staff, $this->district($staff), ['block_status' => 1 - $status]);
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson($this->url($record).'/'.$action)->assertOk()->assertJsonPath('data.status', $status);
        $this->patchJson($this->url($record).'/'.$action)->assertOk();
        $before['block_status'] = $status;
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    #[TestWith(['id'])]
    #[TestWith(['status'])]
    #[TestWith(['code'])]
    #[TestWith(['created_by'])]
    #[TestWith(['created_at'])]
    #[TestWith(['updated_by'])]
    #[TestWith(['updated_at'])]
    #[TestWith(['block_id'])]
    #[TestWith(['block_name'])]
    #[TestWith(['block_type'])]
    #[TestWith(['block_code'])]
    #[TestWith(['subd_id'])]
    #[TestWith(['block_status'])]
    #[TestWith(['block_createby'])]
    #[TestWith(['block_createdate'])]
    #[TestWith(['block_modifyby'])]
    #[TestWith(['block_modifydate'])]
    public function test_internal_and_audit_fields_cannot_be_overridden(string $field): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $record = $this->record($staff, $district);
        $before = $record->refresh()->getRawOriginal();
        $payload = $this->payload($district, [$field => 999]);
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($record), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    public function test_required_fields_name_length_and_enum_validation(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $this->postJson(self::ENDPOINT)->assertUnprocessable()->assertJsonValidationErrors(['name', 'type', 'district_id']);
        foreach ([null, '', ' ', ['bad'], str_repeat('A', 101)] as $name) {
            $this->postJson(self::ENDPOINT, $this->payload($district, ['name' => $name]))->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        foreach ([null, '', 'block', 'City', ['Block']] as $type) {
            $this->postJson(self::ENDPOINT, $this->payload($district, ['type' => $type]))->assertUnprocessable()->assertJsonValidationErrors('type');
        }
        $id = $this->postJson(self::ENDPOINT, $this->payload($district, ['name' => str_repeat('A', 100)]))->assertCreated()->json('data.id');
        $this->patchJson(self::ENDPOINT.'/'.$id, [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::ENDPOINT.'/'.$id, ['type' => 'City'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->patchJson(self::ENDPOINT.'/'.$id, ['name' => ' '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::ENDPOINT.'/'.$id, ['district_id' => null])->assertUnprocessable()->assertJsonValidationErrors('district_id');
    }

    #[TestWith(['status=2', 'status'])]
    #[TestWith(['type=City', 'type'])]
    #[TestWith(['per_page=0', 'per_page'])]
    #[TestWith(['per_page=101', 'per_page'])]
    #[TestWith(['page=0', 'page'])]
    #[TestWith(['search[]=bad', 'search'])]
    #[TestWith(['search_length', 'search'])]
    public function test_invalid_list_inputs_return_422(string $query, string $field): void
    {
        $this->signIn();
        $query = $query === 'search_length' ? 'search='.str_repeat('a', 101) : $query;
        $this->getJson(self::ENDPOINT.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_plain_tampered_wrong_purpose_and_missing_identifiers_are_rejected(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $record = $this->record($staff, $district);
        $before = $record->refresh()->getRawOriginal();
        $token = $this->publicId('block-municipality', $record->block_id);
        $tampered = $token;
        $tampered[50] = $tampered[50] === 'A' ? 'B' : 'A';
        foreach ([(string) $record->block_id, 'invalid', $tampered, $this->publicId('subdivision', $record->block_id), $this->publicId('block-municipality', 999999)] as $id) {
            $url = self::ENDPOINT.'/'.$id;
            $this->getJson($url)->assertNotFound();
            $this->patchJson($url, ['name' => 'Must not save'])->assertNotFound();
            $this->patchJson($url.'/lock')->assertNotFound();
            $this->patchJson($url.'/unlock')->assertNotFound();
        }
        foreach (['district_id', 'subdivision_id'] as $field) {
            foreach ([1, '1', 'invalid', ['invalid'], $token] as $id) {
                $this->postJson(self::ENDPOINT, $this->payload($district, [$field => $id]))->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->patchJson($this->url($record), [$field => $id])->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->getJson(self::ENDPOINT.'?'.http_build_query([$field => $id]))->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    public function test_orphan_district_record_cannot_be_read_or_changed(): void
    {
        $staff = $this->signIn();
        $record = BlockMunicipalityFactory::new()->create(['district_id' => -1, 'block_createby' => $staff->u_id]);
        $this->getJson($this->url($record))->assertNotFound();
        $this->patchJson($this->url($record), ['name' => 'Must not save'])->assertNotFound();
        $this->patchJson($this->url($record).'/lock')->assertNotFound();
        $this->patchJson($this->url($record).'/unlock')->assertNotFound();
    }

    public function test_guests_cannot_access_any_endpoint(): void
    {
        $staff = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $record = $this->record($staff, $this->district($staff));
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
        $staff = StaffUserFactory::new()->for(StaffRoleFactory::new()->create(['mu_id' => $roleId]), 'role')->create();
        $this->actingAs($staff, 'staff');
        $record = $this->record($staff, $this->district($staff));
        $this->assertAllEndpointsRefuse($record, 403);
    }

    private function assertAllEndpointsRefuse(BlockMunicipality $record, int $status): void
    {
        $before = $record->refresh()->getRawOriginal();
        $this->getJson(self::ENDPOINT)->assertStatus($status);
        $this->getJson(self::ENDPOINT.'/district-options')->assertStatus($status);
        $this->getJson(self::ENDPOINT.'/subdivision-options')->assertStatus($status);
        $this->getJson($this->url($record))->assertStatus($status);
        $this->postJson(self::ENDPOINT, ['name' => 'Must not save'])->assertStatus($status);
        $this->patchJson($this->url($record), ['name' => 'Must not save'])->assertStatus($status);
        $this->patchJson($this->url($record).'/lock')->assertStatus($status);
        $this->patchJson($this->url($record).'/unlock')->assertStatus($status);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('block_master', 1);
    }

    public function test_disabled_admin_is_refused_and_write_actions_require_csrf(): void
    {
        $staff = $this->signIn();
        $record = $this->record($staff, $this->district($staff));
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT, ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson($this->url($record), ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson($this->url($record).'/lock')->assertStatus(419);
        $this->patchJson($this->url($record).'/unlock')->assertStatus(419);
        $staff->user_status = 0;
        $staff->save();
        $this->getJson(self::ENDPOINT)->assertForbidden();
    }
}
