<?php

namespace App\Http\Requests;

use App\Domain\Identity\PublicId;
use App\Models\Recruitment;
use App\Rules\EncryptedRecruitmentId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListAdvertisementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recruitment_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedRecruitmentId],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'activated' => ['sometimes', 'integer', 'in:0,1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('recruitment_id')) {
                return;
            }
            if (! Recruitment::query()->whereKey(PublicId::decode('recruitment', $this->input('recruitment_id')))->exists()) {
                $validator->errors()->add('recruitment_id', 'The selected recruitment does not exist.');
            }
        }];
    }
}
