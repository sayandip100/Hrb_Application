<?php

namespace App\Http\Requests;

use App\Rules\EncryptedCasteId;
use Illuminate\Validation\Validator;

class UpdateCasteCommunityRequest extends StoreCasteCommunityRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'name' => ['sometimes', 'required', 'string', 'max:450'],
            'caste_id' => ['sometimes', 'required', 'bail', 'string', 'max:1024', new EncryptedCasteId],
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! array_key_exists('name', $this->all()) && ! array_key_exists('caste_id', $this->all())) {
                $validator->errors()->add('name', 'Provide a name or caste_id to update.');
            }
        }];
    }
}
