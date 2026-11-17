<?php

namespace App\Http\Requests;

use App\Domain\Identity\PublicId;
use App\Models\Advertisement;
use App\Models\Recruitment;
use App\Rules\EncryptedEntityId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class StaffCandidateOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recruitment_id' => ['required_with:advertisement_id', 'bail', 'string', 'max:1024', new EncryptedEntityId('recruitment')],
            'advertisement_id' => ['sometimes', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->validated();
            if (isset($data['recruitment_id']) && ! Recruitment::query()->whereKey(PublicId::decode('recruitment', $data['recruitment_id']))->where('rm_status', 1)->exists()) {
                $validator->errors()->add('recruitment_id', 'Select an active recruitment.');

                return;
            }
            if (isset($data['advertisement_id'])) {
                $advertisement = Advertisement::query()->whereKey(PublicId::decode('advertisement', $data['advertisement_id']))
                    ->where('adv_recruit_master', PublicId::decode('recruitment', $data['recruitment_id']))->where('adv_status', 1)->first();
                if (! $advertisement) {
                    $validator->errors()->add('advertisement_id', 'Select an unlocked advertisement belonging to this recruitment.');

                    return;
                }
                if (isset($data['post_id']) && ! DB::table('advertisement_categoty as p')->join('category_master as c', 'c.catm_id', '=', 'p.acat_name')
                    ->where('p.acat_id', PublicId::decode('advertisement-post', $data['post_id']))
                    ->where('p.acat_adv_master', $advertisement->adv_auto_genno)->exists()) {
                    $validator->errors()->add('post_id', 'Select a post belonging to this advertisement.');
                }
            }
        }];
    }
}
