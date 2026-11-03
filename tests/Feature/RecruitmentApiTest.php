<?php

namespace Tests\Feature;

use App\Domain\Identity\OtpSender;
use App\Models\StaffUser;
use Database\Factories\RecruitmentFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fakes\FakeOtpSender;
use Tests\Support\EnforceCsrfForTesting;
use Tests\Support\StaffAuthTestCase;

class RecruitmentApiTest extends StaffAuthTestCase
{
    private const PREFIX = '/api/v1/master-data/recruitments';

    private function signInAsSuperAdmin(): StaffUser
    {
        $staff = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public function test_login_rotates_csrf_and_a_fresh_cookie_allows_recruitment_creation(): void
    {
        $sender = new FakeOtpSender;
        $this->app->instance(OtpSender::class, $sender);
        $staff = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $csrf = $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $beforeLoginCookie = collect($csrf->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');
        $this->withHeader('X-XSRF-TOKEN', rawurldecode($beforeLoginCookie->getValue()));
        $challenge = $this->browser('POST', '/api/v1/staff/auth/request-otp', [
            'mobile' => $staff->mobile, 'role_id' => 1,
        ])->assertAccepted()->json('data.challenge_id');
        $this->browser('POST', '/api/v1/staff/auth/login', [
            'challenge_id' => $challenge, 'otp' => $sender->lastOtp(),
        ])->assertOk();
        $this->browser('GET', self::PREFIX)->assertOk();
        $this->browser('POST', self::PREFIX, ['name' => 'Medical Officer'])->assertStatus(419);
        $csrf = $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $afterLoginCookie = collect($csrf->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');
        $this->withHeader('X-XSRF-TOKEN', rawurldecode($afterLoginCookie->getValue()));
        $this->browser('POST', self::PREFIX, ['name' => 'Medical Officer'])
            ->assertCreated()->assertJsonPath('data.created_by', $staff->u_id);
        $this->assertDatabaseHas('recruitment_master_tab', ['rm_name' => 'Medical Officer', 'rm_createby' => $staff->u_id]);
    }

    public function test_super_admin_can_list_active_and_locked_recruitments_alphabetically(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $locked = RecruitmentFactory::new()->locked()->create(['rm_name' => 'Zoology', 'rm_createby' => $admin->u_id]);
        $active = RecruitmentFactory::new()->create(['rm_name' => 'Anatomy', 'rm_createby' => $admin->u_id]);
        $this->getJson(self::PREFIX)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $active->rm_id)->assertJsonPath('data.0.is_locked', false)
            ->assertJsonPath('data.1.id', $locked->rm_id)->assertJsonPath('data.1.is_locked', true)
            ->assertJsonPath('meta.total', 2)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_list_can_filter_locked_records_search_and_paginate(): void
    {
        $admin = $this->signInAsSuperAdmin();
        RecruitmentFactory::new()->locked()->count(3)->sequence(
            ['rm_name' => 'Medical A'], ['rm_name' => 'Medical B'], ['rm_name' => 'Medical C'],
        )->create(['rm_createby' => $admin->u_id]);
        RecruitmentFactory::new()->create(['rm_name' => 'Medical D', 'rm_createby' => $admin->u_id]);
        RecruitmentFactory::new()->locked()->create(['rm_name' => 'Nursing', 'rm_createby' => $admin->u_id]);
        $this->getJson(self::PREFIX.'?search=medical&status=0&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Medical C')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
    }

    public function test_search_treats_wildcards_and_sql_text_as_literal_input(): void
    {
        $admin = $this->signInAsSuperAdmin();
        RecruitmentFactory::new()->create(['rm_name' => '100% Nursing', 'rm_createby' => $admin->u_id]);
        RecruitmentFactory::new()->create(['rm_name' => '1000 Nursing', 'rm_createby' => $admin->u_id]);
        $this->getJson(self::PREFIX.'?search='.urlencode('100%'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '100% Nursing');
        $this->getJson(self::PREFIX.'?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_super_admin_can_add_a_recruitment_with_server_owned_audit_fields(): void
    {
        $this->freezeTime();
        $admin = $this->signInAsSuperAdmin();
        $response = $this->postJson(self::PREFIX, ['name' => '  Medical Officer  '])
            ->assertCreated()->assertJsonPath('message', 'Recruitment added successfully.')
            ->assertJsonPath('data.name', 'Medical Officer')->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.is_locked', false)->assertJsonPath('data.created_by', $admin->u_id)
            ->assertJsonPath('data.created_at', now('Asia/Kolkata')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('recruitment_master_tab', [
            'rm_id' => $response->json('data.id'), 'rm_name' => 'Medical Officer',
            'rm_createby' => $admin->u_id, 'rm_status' => 1,
        ]);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([['name']])]
    public function test_invalid_names_are_rejected_with_422(mixed $name): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::PREFIX, ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('recruitment_master_tab', 0);
    }

    public function test_name_length_matches_the_existing_650_character_column(): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::PREFIX, ['name' => str_repeat('A', 651)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::PREFIX, ['name' => str_repeat('A', 650)])->assertCreated();
        $this->assertDatabaseCount('recruitment_master_tab', 1);
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_duplicate_names_are_rejected_even_if_case_spacing_or_status_differs(int $status): void
    {
        $admin = $this->signInAsSuperAdmin();
        RecruitmentFactory::new()->create(['rm_name' => ' Medical Officer ', 'rm_status' => $status, 'rm_createby' => $admin->u_id]);
        $this->postJson(self::PREFIX, ['name' => 'medical officer'])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'A recruitment with this name already exists.');
        $this->assertDatabaseCount('recruitment_master_tab', 1);
    }

    #[TestWith(['id'])]
    #[TestWith(['status'])]
    #[TestWith(['created_by'])]
    #[TestWith(['created_at'])]
    #[TestWith(['rm_id'])]
    #[TestWith(['rm_status'])]
    #[TestWith(['rm_createby'])]
    #[TestWith(['rm_createdate'])]
    public function test_client_cannot_override_ids_status_or_audit_fields(string $field): void
    {
        $this->signInAsSuperAdmin();
        $this->postJson(self::PREFIX, ['name' => 'Medical Officer', $field => 123])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('recruitment_master_tab', 0);
    }

    #[TestWith(['lock', 1, 0, true, 'Recruitment locked successfully.'])]
    #[TestWith(['unlock', 0, 1, false, 'Recruitment unlocked successfully.'])]
    public function test_super_admin_can_change_lock_status_without_changing_the_recruitment(
        string $operation, int $before, int $after, bool $locked, string $message,
    ): void {
        $admin = $this->signInAsSuperAdmin();
        $record = RecruitmentFactory::new()->create(['rm_status' => $before, 'rm_createby' => $admin->u_id]);
        $original = $record->getRawOriginal();
        $url = self::PREFIX.'/'.$record->rm_id.'/'.$operation;
        $this->patchJson($url, ['name' => 'Changed', 'rm_createby' => 999])
            ->assertOk()->assertJsonPath('message', $message)->assertJsonPath('data.status', $after)
            ->assertJsonPath('data.is_locked', $locked);
        $this->patchJson($url)->assertOk()->assertJsonPath('data.status', $after);
        $record->refresh();
        $this->assertSame($after, $record->rm_status);
        $this->assertSame($original['rm_name'], $record->rm_name);
        $this->assertSame($original['rm_createby'], $record->rm_createby);
        $this->assertSame($original['rm_createdate'], $record->getRawOriginal('rm_createdate'));
    }

    public function test_unknown_recruitment_returns_404_for_lock_and_unlock(): void
    {
        $this->signInAsSuperAdmin();
        $this->patchJson(self::PREFIX.'/999999/lock')->assertNotFound();
        $this->patchJson(self::PREFIX.'/999999/unlock')->assertNotFound();
    }

    public function test_guest_cannot_list_create_or_change_recruitments(): void
    {
        $record = RecruitmentFactory::new()->create();
        $this->getJson(self::PREFIX)->assertUnauthorized();
        $this->postJson(self::PREFIX, ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->patchJson(self::PREFIX.'/'.$record->rm_id.'/lock')->assertUnauthorized();
        $this->patchJson(self::PREFIX.'/'.$record->rm_id.'/unlock')->assertUnauthorized();
        $this->assertDatabaseCount('recruitment_master_tab', 1);
        $this->assertSame(1, $record->refresh()->rm_status);
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
    public function test_all_other_staff_roles_are_forbidden_from_recruitment_management(int $roleId): void
    {
        $role = StaffRoleFactory::new()->create(['mu_id' => $roleId]);
        $staff = StaffUserFactory::new()->for($role, 'role')->create();
        $record = RecruitmentFactory::new()->create();
        $this->actingAs($staff, 'staff');
        $this->getJson(self::PREFIX)->assertForbidden();
        $this->postJson(self::PREFIX, ['name' => 'Unauthorized'])->assertForbidden();
        $this->patchJson(self::PREFIX.'/'.$record->rm_id.'/lock')->assertForbidden();
        $this->patchJson(self::PREFIX.'/'.$record->rm_id.'/unlock')->assertForbidden();
        $this->assertDatabaseCount('recruitment_master_tab', 1);
        $this->assertSame(1, $record->refresh()->rm_status);
    }

    public function test_disabled_super_admin_cannot_change_recruitments(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $admin->user_status = 0;
        $admin->save();
        $this->postJson(self::PREFIX, ['name' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseCount('recruitment_master_tab', 0);
    }

    public function test_disabled_super_admin_role_cannot_access_recruitments(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $admin->role()->update(['mu_status' => 0]);
        $this->getJson(self::PREFIX)->assertForbidden();
    }

    public function test_creation_and_lock_changes_require_csrf_protection(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $record = RecruitmentFactory::new()->create(['rm_createby' => $admin->u_id]);
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfForTesting::class);
        $this->postJson(self::PREFIX, ['name' => 'Missing CSRF'])->assertStatus(419);
        $this->patchJson(self::PREFIX.'/'.$record->rm_id.'/lock')->assertStatus(419);
        $this->assertDatabaseCount('recruitment_master_tab', 1);
        $this->assertSame(1, $record->refresh()->rm_status);
    }

    #[TestWith(['per_page=101', 'per_page'])]
    #[TestWith(['per_page=0', 'per_page'])]
    #[TestWith(['page=0', 'page'])]
    #[TestWith(['status=2', 'status'])]
    #[TestWith(['search[]=invalid', 'search'])]
    public function test_invalid_list_filters_return_validation_errors(string $query, string $field): void
    {
        $this->signInAsSuperAdmin();
        $this->getJson(self::PREFIX.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}
