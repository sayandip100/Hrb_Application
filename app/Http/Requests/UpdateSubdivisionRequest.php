<?php

namespace App\Http\Requests;

use App\Rules\EncryptedDistrictId;
use Illuminate\Validation\Validator;

class UpdateSubdivisionRequest extends StoreSubdivisionRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'district_id' => ['sometimes', 'required', 'bail', 'string', 'max:1024', new EncryptedDistrictId],
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! array_key_exists('name', $this->all()) && ! array_key_exists('district_id', $this->all())) {
                $validator->errors()->add('name', 'Provide a name or district_id to update.');
            }
        }];
    }
}
