<?php

namespace Tests\Feature;

use App\Domain\Identity\PublicId;
use Database\Factories\StaffRoleFactory;
use Database\Factories\StaffUserFactory;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\StaffAuthTestCase;

class MasterDataEncryptedIdsTest extends StaffAuthTestCase
{
    private function signIn(): void
    {
        $admin = StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')->create();
        $this->actingAs($admin, 'staff');
    }

    #[TestWith(['recruitments', 'recruitment', 'recruitment_master_tab', 'rm_id'])]
    #[TestWith(['disciplines', 'discipline', 'category_master', 'catm_id'])]
    #[TestWith(['qualifications', 'qualification', 'qualification_master', 'qm_id'])]
    #[TestWith(['experiences', 'experience', 'experience_master_tab', 'expset_id'])]
    #[TestWith(['caste-issuing-authorities', 'caste-issuing-authority', 'caste_issuing_auth_tab', 'cia_id'])]
    #[TestWith(['age-relaxations', 'age-relaxation', 'caste_tab', 'caste_id'])]
    public function test_returned_encrypted_id_works_for_listing_editing_and_lock_changes(string $path, string $type, string $table, string $primaryKey): void
    {
        $this->signIn();
        $url = '/api/v1/master-data/'.$path;
        $created = $this->postJson($url, ['name' => 'New master record'])->assertCreated();
        $id = $created->json('data.id');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $id);
        $this->assertFalse(ctype_digit($id));
        $databaseId = PublicId::decode($type, $id);
        $this->assertDatabaseHas($table, [$primaryKey => $databaseId]);
        $this->assertIsString($created->json('data.created_by'));
        $this->assertSame(auth('staff')->id(), PublicId::decode('staff', $created->json('data.created_by')));

        $listed = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->assertSame($databaseId, PublicId::decode($type, $listed));
        if ($path !== 'recruitments') {
            $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.name', 'New master record');
            $this->patchJson($url.'/'.$id, ['name' => 'Edited master record'])->assertOk()
                ->assertJsonPath('data.name', 'Edited master record');
        }
        $this->patchJson($url.'/'.$listed.'/lock')->assertOk()->assertJsonPath('data.is_locked', true);
        $this->patchJson($url.'/'.$id.'/unlock')->assertOk()->assertJsonPath('data.is_locked', false);
        $this->assertDatabaseCount($table, 1);
    }

    #[TestWith(['recruitments', 'recruitment'])]
    #[TestWith(['disciplines', 'discipline'])]
    #[TestWith(['qualifications', 'qualification'])]
    #[TestWith(['experiences', 'experience'])]
    #[TestWith(['caste-issuing-authorities', 'caste-issuing-authority'])]
    #[TestWith(['age-relaxations', 'age-relaxation'])]
    public function test_plain_tampered_wrong_purpose_and_unscoped_encrypted_ids_return_404_without_changing_record(string $path, string $type): void
    {
        $this->signIn();
        $url = '/api/v1/master-data/'.$path;
        $created = $this->postJson($url, ['name' => 'Protected record'])->assertCreated();
        $id = $created->json('data.id');
        $number = PublicId::decode($type, $id);
        $tampered = $id;
        $middle = intdiv(strlen($id), 2);
        $tampered[$middle] = $tampered[$middle] === 'A' ? 'B' : 'A';
        $wrongType = $type === 'experience' ? 'qualification' : 'experience';
        $unscoped = rtrim(strtr(Crypt::encryptString((string) $number), '+/', '-_'), '=');
        foreach ([(string) $number, 'invalid', $tampered, PublicId::encode($wrongType, $number), PublicId::encode('staff', $number), $unscoped] as $invalid) {
            if ($path !== 'recruitments') {
                $this->getJson($url.'/'.$invalid)->assertNotFound();
                $this->patchJson($url.'/'.$invalid, ['name' => 'Must not save'])->assertNotFound();
            }
            $this->patchJson($url.'/'.$invalid.'/lock')->assertNotFound();
            $this->patchJson($url.'/'.$invalid.'/unlock')->assertNotFound();
        }
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Protected record')->assertJsonPath('data.0.is_locked', false);
    }

    #[TestWith(['disciplines'])]
    #[TestWith(['qualifications'])]
    public function test_recruitment_tokens_work_in_payloads_filters_and_pagination_links(string $path): void
    {
        $this->signIn();
        $base = '/api/v1/master-data/';
        $recruitment = $this->postJson($base.'recruitments', ['name' => 'Parent recruitment'])->assertCreated()->json('data.id');
        $other = $this->postJson($base.'recruitments', ['name' => 'Other recruitment'])->assertCreated()->json('data.id');
        $url = $base.$path;
        $record = $this->postJson($url, ['name' => 'Linked record', 'recruitment_id' => $recruitment])->assertCreated();
        $this->assertSame(PublicId::decode('recruitment', $recruitment), PublicId::decode('recruitment', $record->json('data.recruitment_id')));
        $this->assertSame(PublicId::decode('recruitment', $recruitment), PublicId::decode('recruitment', $record->json('data.recruitment.id')));
        $this->postJson($url, ['name' => 'Second linked record', 'recruitment_id' => $recruitment])->assertCreated();
        $this->postJson($url, ['name' => 'Other linked record', 'recruitment_id' => $other])->assertCreated();
        $list = $this->getJson($url.'?recruitment_id='.$recruitment.'&per_page=1')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertStringContainsString('recruitment_id='.$recruitment, $list->json('links.next'));
        $this->getJson($list->json('links.next'))->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.total', 2);
        $this->patchJson($url.'/'.$record->json('data.id'), ['recruitment_id' => $other])->assertOk();
        $this->getJson($url.'?recruitment_id='.$other)->assertOk()->assertJsonPath('meta.total', 2);
    }

    #[TestWith(['disciplines'])]
    #[TestWith(['qualifications'])]
    public function test_numeric_and_wrong_type_recruitment_inputs_return_422(string $path): void
    {
        $this->signIn();
        $url = '/api/v1/master-data/'.$path;
        $record = $this->postJson($url, ['name' => 'Unlinked record'])->assertCreated()->json('data.id');
        foreach ([1, '1', 'invalid', ['invalid'], PublicId::encode('experience', 1), PublicId::encode('recruitment', 0), PublicId::encode('recruitment', 2147483648)] as $invalid) {
            $this->postJson($url, ['name' => 'Must not save', 'recruitment_id' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
            $this->patchJson($url.'/'.$record, ['recruitment_id' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
            $this->getJson($url.'?'.http_build_query(['recruitment_id' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('recruitment_id');
        }
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.recruitment_id', null);
    }
}
