<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use App\Models\District;
use App\Models\StaffUser;
use App\Models\Subdivision;
use Database\Factories\DistrictFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Database\Factories\SubdivisionFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class SubdivisionApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/master-data/subdivisions';

    private function signIn(): StaffUser
    {
        $staff = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function district(StaffUser $staff, array $attributes = []): District
    {
        return DistrictFactory::new()->create(array_replace([
            'district_code' => fake()->unique()->numerify('00######'), 'dist_createby' => $staff->u_id,
        ], $attributes));
    }

    private function subdivision(StaffUser $staff, District $district, array $attributes = []): Subdivision
    {
        return SubdivisionFactory::new()->create(array_replace([
            'subdiv_district' => $district->district_code, 'subdiv_createby' => $staff->u_id,
        ], $attributes));
    }

    private function url(Subdivision $subdivision): string
    {
        return self::ENDPOINT.'/'.$this->publicId('subdivision', $subdivision->subdiv_id);
    }

    public function test_dropdown_returns_only_active_districts_with_unambiguous_compatible_codes(): void
    {
        $staff = $this->signIn();
        $last = $this->district($staff, ['district_name' => 'Zulu']);
        $first = $this->district($staff, ['district_name' => 'Alpha']);
        $this->district($staff, ['district_status' => 0]);
        $this->district($staff, ['district_code' => '12345678901']);
        $this->district($staff, ['district_code' => 'DUPLICATE']);
        $this->district($staff, ['district_code' => 'DUPLICATE']);
        $this->getJson(self::ENDPOINT.'/district-options')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alpha')->assertJsonPath('data.1.name', 'Zulu')
            ->assertJsonPath('data.0.id', $this->matchesPublicId('district', $first->district_id))
            ->assertJsonPath('data.1.id', $this->matchesPublicId('district', $last->district_id));
    }

    public function test_super_admin_can_add_using_the_dropdown_id_and_get_encrypted_details(): void
    {
        $this->freezeTime();
        $staff = $this->signIn();
        $district = $this->district($staff);
        $districtId = $this->getJson(self::ENDPOINT.'/district-options')->assertOk()->json('data.0.id');
        $response = $this->postJson(self::ENDPOINT, ['name' => '  Example Sub-Division  ', 'district_id' => $districtId])
            ->assertCreated()->assertJsonPath('message', 'Sub-Division added successfully.')
            ->assertJsonPath('data.name', 'Example Sub-Division')->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.is_locked', false)->assertJsonPath('data.district.name', $district->district_name)
            ->assertJsonPath('data.district_id', $this->matchesPublicId('district', $district->district_id))
            ->assertJsonPath('data.district.id', $this->matchesPublicId('district', $district->district_id))
            ->assertJsonPath('data.created_by', $this->matchesPublicId('staff', $staff->u_id))
            ->assertJsonPath('data.created_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'))
            ->assertJsonPath('data.updated_at', null)->assertJsonPath('data.updated_by', null)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('subdivision_tab', [
            'subdiv_id' => PublicId::decode('subdivision', $response->json('data.id')),
            'subdiv_name' => 'Example Sub-Division', 'subdiv_district' => $district->district_code,
            'subdiv_createby' => $staff->u_id, 'subdiv_status' => 1,
        ]);
        $this->getJson(self::ENDPOINT.'/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.name', 'Example Sub-Division');
    }

    public function test_list_includes_locked_subdivisions_and_locked_districts_newest_first_and_omits_orphans_like_legacy_join(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff, ['district_status' => 0]);
        $this->subdivision($staff, $district, ['subdiv_name' => 'Alpha']);
        $last = $this->subdivision($staff, $district, ['subdiv_name' => 'Zulu', 'subdiv_status' => 0]);
        SubdivisionFactory::new()->create(['subdiv_district' => 'ORPHAN', 'subdiv_createby' => $staff->u_id]);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $this->matchesPublicId('subdivision', $last->subdiv_id))
            ->assertJsonPath('data.0.is_locked', true)->assertJsonPath('data.1.name', 'Alpha');
    }

    public function test_search_status_district_filter_and_pagination_preserve_encrypted_filter(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        foreach (['Clinical A', 'Clinical B', 'Clinical C'] as $name) {
            $this->subdivision($staff, $district, ['subdiv_name' => $name, 'subdiv_status' => 0]);
        }
        $this->subdivision($staff, $district, ['subdiv_name' => 'Clinical D']);
        $this->subdivision($staff, $this->district($staff), ['subdiv_name' => 'Clinical Other', 'subdiv_status' => 0]);
        $id = $this->publicId('district', $district->district_id);
        $response = $this->getJson(self::ENDPOINT.'?search=clinical&status=0&district_id='.$id.'&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Clinical A')->assertJsonPath('meta.total', 3);
        $this->assertStringContainsString('district_id='.$id, $response->json('links.prev'));
        $this->subdivision($staff, $district, ['subdiv_name' => '100% Sub-Division']);
        $this->subdivision($staff, $district, ['subdiv_name' => '1000 Sub-Division']);
        $this->getJson(self::ENDPOINT.'?search='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ENDPOINT.'?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_edit_changes_name_and_district_and_records_modifier_preserving_creation_and_status(): void
    {
        $this->freezeTime();
        $staff = $this->signIn();
        $creator = StaffUserFactory::new()->for($staff->role, 'role')->create();
        $record = $this->subdivision($staff, $this->district($staff), [
            'subdiv_createby' => $creator->u_id, 'subdiv_code' => 'LEGACY-CODE',
            'subdiv_status' => 0, 'subdiv_createdate' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);
        $createdAt = $record->refresh()->getRawOriginal('subdiv_createdate');
        $parent = $this->district($staff);
        $this->patchJson($this->url($record), ['name' => ' Updated ', 'district_id' => $this->publicId('district', $parent->district_id)])
            ->assertOk()->assertJsonPath('message', 'Sub-Division updated successfully.')
            ->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.updated_by', $this->matchesPublicId('staff', $staff->u_id))
            ->assertJsonPath('data.updated_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'));
        $record->refresh();
        $this->assertSame($parent->district_code, $record->subdiv_district);
        $this->assertSame($createdAt, $record->getRawOriginal('subdiv_createdate'));
        $this->assertSame($creator->u_id, $record->subdiv_createby);
        $this->assertSame('LEGACY-CODE', $record->subdiv_code);
    }

    public function test_name_only_edit_keeps_locked_district_and_same_name_is_allowed_in_other_districts(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $payload = ['name' => 'Same name', 'district_id' => $this->publicId('district', $district->district_id)];
        $id = $this->postJson(self::ENDPOINT, $payload)->assertCreated()->json('data.id');
        $other = $this->district($staff);
        $this->postJson(self::ENDPOINT, ['name' => 'Same name', 'district_id' => $this->publicId('district', $other->district_id)])->assertCreated();
        $district->district_status = 0;
        $district->save();
        $this->patchJson(self::ENDPOINT.'/'.$id, ['name' => 'Changed'])->assertOk();
        $this->patchJson(self::ENDPOINT.'/'.$id, ['district_id' => $payload['district_id']])->assertOk();
        $this->assertDatabaseCount('subdivision_tab', 2);
    }

    #[TestWith(['lock', 0])]
    #[TestWith(['unlock', 1])]
    public function test_lock_changes_only_status_and_is_repeatable(string $action, int $status): void
    {
        $staff = $this->signIn();
        $record = $this->subdivision($staff, $this->district($staff), ['subdiv_status' => 1 - $status]);
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson($this->url($record).'/'.$action)->assertOk()->assertJsonPath('data.status', $status);
        $this->patchJson($this->url($record).'/'.$action)->assertOk();
        $before['subdiv_status'] = $status;
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    #[TestWith(['locked'])]
    #[TestWith(['long-code'])]
    #[TestWith(['duplicate-code'])]
    #[TestWith(['missing'])]
    public function test_invalid_parent_selection_is_rejected_for_add_and_edit(string $condition): void
    {
        $staff = $this->signIn();
        $record = $this->subdivision($staff, $this->district($staff));
        $before = $record->refresh()->getRawOriginal();
        $attributes = match ($condition) {
            'locked' => ['district_status' => 0],
            'long-code' => ['district_code' => '12345678901'],
            'duplicate-code' => ['district_code' => 'DUPLICATE'],
            default => [],
        };
        $id = $condition === 'missing' ? 999999 : $this->district($staff, $attributes)->district_id;
        if ($condition === 'duplicate-code') {
            $this->district($staff, $attributes);
        }
        $payload = ['name' => 'Must not save', 'district_id' => $this->publicId('district', $id)];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $this->patchJson($this->url($record), $payload)->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('subdivision_tab', 1);
    }

    #[TestWith(['id'])]
    #[TestWith(['status'])]
    #[TestWith(['created_by'])]
    #[TestWith(['created_at'])]
    #[TestWith(['updated_by'])]
    #[TestWith(['updated_at'])]
    #[TestWith(['subdiv_id'])]
    #[TestWith(['subdiv_district'])]
    #[TestWith(['subdiv_name'])]
    #[TestWith(['subdiv_createdate'])]
    #[TestWith(['subdiv_createby'])]
    #[TestWith(['subdiv_modifydate'])]
    #[TestWith(['subdiv_modifyby'])]
    #[TestWith(['subdiv_status'])]
    #[TestWith(['subdiv_code'])]
    #[TestWith(['code'])]
    public function test_client_cannot_override_internal_or_audit_fields(string $field): void
    {
        $staff = $this->signIn();
        $record = $this->subdivision($staff, $this->district($staff));
        $before = $record->refresh()->getRawOriginal();
        $payload = ['name' => 'Changed', 'district_id' => $this->publicId('district', $record->district->district_id), $field => '999'];
        $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($record), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    public function test_invalid_names_and_missing_fields_return_422_and_maximum_name_length_is_accepted(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $this->postJson(self::ENDPOINT)->assertUnprocessable()->assertJsonValidationErrors(['name', 'district_id']);
        foreach ([null, '', '   ', ['name'], str_repeat('a', 151)] as $name) {
            $this->postJson(self::ENDPOINT, ['name' => $name, 'district_id' => $this->publicId('district', $district->district_id)])
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $id = $this->postJson(self::ENDPOINT, ['name' => str_repeat('a', 150), 'district_id' => $this->publicId('district', $district->district_id)])
            ->assertCreated()->json('data.id');
        $this->patchJson(self::ENDPOINT.'/'.$id, [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::ENDPOINT.'/'.$id, ['name' => ' '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::ENDPOINT.'/'.$id, ['district_id' => null])->assertUnprocessable()->assertJsonValidationErrors('district_id');
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
        $record = $this->subdivision($staff, $this->district($staff));
        $before = $record->refresh()->getRawOriginal();
        $valid = $this->publicId('subdivision', $record->subdiv_id);
        $tampered = $valid;
        $tampered[40] = $tampered[40] === 'A' ? 'B' : 'A';
        foreach ([(string) $record->subdiv_id, 'invalid', $tampered, $this->publicId('experience', $record->subdiv_id), $this->publicId('subdivision', 999999)] as $id) {
            $url = self::ENDPOINT.'/'.$id;
            $this->getJson($url)->assertNotFound();
            $this->patchJson($url, ['name' => 'Must not save'])->assertNotFound();
            $this->patchJson($url.'/lock')->assertNotFound();
            $this->patchJson($url.'/unlock')->assertNotFound();
        }
        foreach ([1, '1', 'invalid', ['invalid'], $valid, $this->publicId('district', 2147483648)] as $id) {
            $this->postJson(self::ENDPOINT, ['name' => 'Must not save', 'district_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('district_id');
            $this->patchJson($this->url($record), ['district_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('district_id');
            $this->getJson(self::ENDPOINT.'?'.http_build_query(['district_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('district_id');
        }
        $this->assertSame($before, $record->refresh()->getRawOriginal());
    }

    public function test_orphan_subdivisions_cannot_be_read_or_changed(): void
    {
        $staff = $this->signIn();
        $record = SubdivisionFactory::new()->create(['subdiv_district' => 'ORPHAN', 'subdiv_createby' => $staff->u_id]);
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
        $record = $this->subdivision($staff, $this->district($staff));
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
        $record = $this->subdivision($staff, $this->district($staff));
        $this->assertAllEndpointsRefuse($record, 403);
    }

    private function assertAllEndpointsRefuse(Subdivision $record, int $status): void
    {
        $before = $record->refresh()->getRawOriginal();
        $this->getJson(self::ENDPOINT)->assertStatus($status);
        $this->getJson(self::ENDPOINT.'/district-options')->assertStatus($status);
        $this->getJson($this->url($record))->assertStatus($status);
        $this->postJson(self::ENDPOINT, ['name' => 'Must not save'])->assertStatus($status);
        $this->patchJson($this->url($record), ['name' => 'Must not save'])->assertStatus($status);
        $this->patchJson($this->url($record).'/lock')->assertStatus($status);
        $this->patchJson($this->url($record).'/unlock')->assertStatus($status);
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->assertDatabaseCount('subdivision_tab', 1);
    }

    public function test_disabled_super_admin_is_refused_and_writes_require_csrf(): void
    {
        $staff = $this->signIn();
        $record = $this->subdivision($staff, $this->district($staff));
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::ENDPOINT, ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson($this->url($record), ['name' => 'No CSRF'])->assertStatus(419);
        $this->patchJson($this->url($record).'/lock')->assertStatus(419);
        $this->patchJson($this->url($record).'/unlock')->assertStatus(419);
        $staff->user_status = 0;
        $staff->save();
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->assertDatabaseCount('subdivision_tab', 1);
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_duplicate_name_in_same_district_is_rejected_ignoring_case_spaces_and_status(int $status): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff);
        $this->subdivision($staff, $district, ['subdiv_name' => ' Existing Division ', 'subdiv_status' => $status]);
        $this->postJson(self::ENDPOINT, ['name' => 'existing division', 'district_id' => $this->publicId('district', $district->district_id)])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'A Sub-Division with this name already exists in the selected district.');
        $this->assertDatabaseCount('subdivision_tab', 1);
    }

    public function test_edit_cannot_duplicate_name_or_move_into_district_with_same_name(): void
    {
        $staff = $this->signIn();
        $first = $this->district($staff);
        $second = $this->district($staff);
        $record = $this->subdivision($staff, $first, ['subdiv_name' => 'Same name']);
        $this->subdivision($staff, $first, ['subdiv_name' => 'Other name']);
        $this->subdivision($staff, $second, ['subdiv_name' => 'Same name', 'subdiv_status' => 0]);
        $this->patchJson($this->url($record), ['name' => ' same NAME '])->assertOk();
        $before = $record->refresh()->getRawOriginal();
        $this->patchJson($this->url($record), ['name' => 'other NAME'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url($record), ['district_id' => $this->publicId('district', $second->district_id)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $record->refresh()->getRawOriginal());
        $this->patchJson($this->url($record), ['name' => 'New name', 'district_id' => $this->publicId('district', $second->district_id)])
            ->assertOk()->assertJsonPath('data.name', 'New name');
        $this->assertSame($second->district_code, $record->refresh()->subdiv_district);
    }

    public function test_ten_character_district_code_with_leading_zeroes_is_saved_as_string_not_primary_key(): void
    {
        $staff = $this->signIn();
        $district = $this->district($staff, ['district_code' => '0000001234']);
        $response = $this->postJson(self::ENDPOINT, ['name' => 'Example Division', 'district_id' => $this->publicId('district', $district->district_id)])
            ->assertCreated();
        $id = PublicId::decode('subdivision', $response->json('data.id'));
        $this->assertDatabaseHas('subdivision_tab', ['subdiv_id' => $id, 'subdiv_district' => '0000001234']);
        $this->getJson(self::ENDPOINT.'/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.district_id', $this->matchesPublicId('district', $district->district_id));
    }
}
