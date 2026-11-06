<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use App\Models\Advertisement;
use App\Models\Recruitment;
use App\Models\StaffUser;
use Database\Factories\AdvertisementDraftFactory;
use Database\Factories\AdvertisementFactory;
use Database\Factories\AdvertisementMarkFactory;
use Database\Factories\RecruitmentFactory;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\StaffAuthTestCase;

class AdvertisementListApiTest extends StaffAuthTestCase
{
    private const ENDPOINT = '/api/v1/advertisements/all-advertisement-list';

    private function admin(): StaffUser
    {
        $admin = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($admin, 'staff');

        return $admin;
    }

    private function recruitment(StaffUser $admin, array $attributes = []): Recruitment
    {
        return RecruitmentFactory::new()->create(array_merge(['rm_createby' => $admin->u_id], $attributes));
    }

    private function advertisement(Recruitment $recruitment, array $attributes = [], bool $withMarks = true): Advertisement
    {
        $advertisement = AdvertisementFactory::new()->create(array_merge([
            'adv_recruit_master' => $recruitment->rm_id, 'adv_createby' => $recruitment->rm_createby,
        ], $attributes));
        if ($withMarks) {
            AdvertisementMarkFactory::new()->create(['amark_adv_master' => $advertisement->adv_auto_genno]);
        }

        return $advertisement;
    }

    private function path(Recruitment $recruitment, array $filters = []): string
    {
        return self::ENDPOINT.'?'.http_build_query(array_merge([
            'recruitment_id' => $this->publicId('recruitment', $recruitment->rm_id),
        ], $filters));
    }

    public function test_requires_authentication_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_rejects_non_super_admin_403(): void
    {
        $staff = StaffUserFactory::new()->create();
        $this->actingAs($staff, 'staff')->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_requires_a_recruitment_422(): void
    {
        $this->admin();
        $this->getJson(self::ENDPOINT)->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
    }

    #[TestWith(['145'])]
    #[TestWith(['bad-token'])]
    public function test_rejects_invalid_recruitment_identifier_422(string $id): void
    {
        $this->admin();
        $this->getJson(self::ENDPOINT.'?recruitment_id='.$id)->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
    }

    public function test_rejects_wrong_purpose_identifier_422(): void
    {
        $this->admin();
        $this->getJson(self::ENDPOINT.'?recruitment_id='.$this->publicId('advertisement', 1))
            ->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
    }

    public function test_rejects_missing_recruitment_422(): void
    {
        $this->admin();
        $this->getJson(self::ENDPOINT.'?recruitment_id='.$this->publicId('recruitment', 99999))
            ->assertUnprocessable()->assertJsonPath('errors.recruitment_id.0', 'The selected recruitment does not exist.');
    }

    #[TestWith(['status', 2])]
    #[TestWith(['activated', -1])]
    #[TestWith(['per_page', 101])]
    #[TestWith(['per_page', 0])]
    #[TestWith(['page', 0])]
    #[TestWith(['search', ['bad']])]
    #[TestWith(['search', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'])]
    public function test_rejects_invalid_filters_422(string $key, mixed $value): void
    {
        $recruitment = $this->recruitment($this->admin());
        $this->getJson($this->path($recruitment, [$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
    }

    public function test_lists_legacy_records_with_encrypted_ids_and_safe_document_metadata(): void
    {
        $admin = $this->admin();
        $recruitment = $this->recruitment($admin, ['rm_status' => 0]);
        $advertisement = $this->advertisement($recruitment, [
            'adv_no' => 'WBHRB/2026/001', 'adv_source_doc' => 'advertisements/private/file.pdf',
            'adv_modify_by' => $admin->u_id, 'adv_status' => 0, 'adv_activated' => 1,
        ]);
        $before = $advertisement->fresh()->getAttributes();
        $response = $this->getJson($this->path($recruitment))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.advertisement_number', 'WBHRB/2026/001')
            ->assertJsonPath('data.0.starts_at', '2027-01-01 10:00:00')
            ->assertJsonPath('data.0.total_vacancies', 5)
            ->assertJsonPath('data.0.is_locked', true)->assertJsonPath('data.0.is_activated', true)
            ->assertJsonPath('data.0.document_name', 'file.pdf')->assertJsonPath('data.0.has_document', true)
            ->assertJsonPath('data.0.id', $this->matchesPublicId('advertisement', $advertisement->adv_id))
            ->assertJsonPath('data.0.recruitment.id', $this->matchesPublicId('recruitment', $recruitment->rm_id))
            ->assertJsonPath('data.0.created_by', $this->matchesPublicId('staff', $admin->u_id))
            ->assertJsonPath('data.0.updated_by', $this->matchesPublicId('staff', $admin->u_id));
        $this->assertSame(40, $response->json('data.0.marks.academic'));
        $this->assertArrayNotHasKey('adv_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('adv_source_doc', $response->json('data.0'));
        $this->assertSame($before, $advertisement->fresh()->getAttributes());
    }

    public function test_paginates_newest_first_without_duplicate_marks_rows(): void
    {
        $recruitment = $this->recruitment($this->admin());
        $older = $this->advertisement($recruitment);
        $newer = $this->advertisement($recruitment);
        AdvertisementMarkFactory::new()->create(['amark_adv_master' => $newer->adv_auto_genno, 'amark_academic' => 55]);
        $this->getJson($this->path($recruitment, ['per_page' => 1]))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $newer->adv_auto_genno)->assertJsonPath('data.0.marks.academic', 55)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson($this->path($recruitment, ['per_page' => 1, 'page' => 2]))->assertOk()
            ->assertJsonPath('data.0.reference', $older->adv_auto_genno)->assertJsonPath('meta.current_page', 2);
    }

    public function test_matches_old_inner_join_scope_and_recruitment_filter(): void
    {
        $admin = $this->admin();
        $recruitment = $this->recruitment($admin);
        $visible = $this->advertisement($recruitment);
        $this->advertisement($recruitment, [], false);
        $this->advertisement($this->recruitment($admin));
        $this->getJson($this->path($recruitment))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $visible->adv_auto_genno);
    }

    public function test_filters_lock_activation_and_case_insensitive_search(): void
    {
        $recruitment = $this->recruitment($this->admin());
        $match = $this->advertisement($recruitment, ['adv_no' => 'WBHRB/Target/1', 'adv_status' => 0, 'adv_activated' => 1]);
        $this->advertisement($recruitment, ['adv_no' => 'WBHRB/Target/2', 'adv_status' => 1, 'adv_activated' => 1]);
        $this->advertisement($recruitment, ['adv_no' => 'WBHRB/Other/3', 'adv_status' => 0, 'adv_activated' => 1]);
        $this->advertisement($recruitment, ['adv_no' => 'WBHRB/Target/4', 'adv_status' => 0, 'adv_activated' => 0]);
        $this->getJson($this->path($recruitment, ['search' => 'target', 'status' => 0, 'activated' => 1]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $match->adv_auto_genno);
        $this->getJson($this->path($recruitment, ['search' => strtolower($match->adv_auto_genno)]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $match->adv_auto_genno);
    }

    public function test_treats_search_wildcards_and_quotes_as_literal_input(): void
    {
        $recruitment = $this->recruitment($this->admin());
        $match = $this->advertisement($recruitment, ['adv_no' => "TEST%_!'abc"]);
        $this->advertisement($recruitment, ['adv_no' => 'TESTanything']);
        $this->getJson($this->path($recruitment, ['search' => "%_!'abc"]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $match->adv_auto_genno);
    }

    public function test_includes_submitted_advertisement_once_and_keeps_draft_list_separate(): void
    {
        $admin = $this->admin();
        $recruitment = $this->recruitment($admin);
        $advertisement = $this->advertisement($recruitment, ['adv_source_doc' => '']);
        AdvertisementDraftFactory::new()->create(['created_by' => $admin->u_id]);
        AdvertisementDraftFactory::new()->create(['created_by' => $admin->u_id,
            'state' => 'submitted', 'submitted_advertisement_id' => $advertisement->adv_id]);
        $response = $this->getJson($this->path($recruitment))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.has_document', false)->assertJsonPath('data.0.document_name', null)
            ->assertJsonPath('data.0.updated_by', null);
        $this->assertSame($advertisement->adv_id, PublicId::decode('advertisement', $response->json('data.0.id')));
        $this->getJson('/api/v1/advertisements')->assertOk()->assertJsonCount(2, 'data');
    }
}
