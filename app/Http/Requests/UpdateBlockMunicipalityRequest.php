<?php

namespace App\Http\Requests;

use App\Rules\EncryptedDistrictId;
use Illuminate\Validation\Validator;

class UpdateBlockMunicipalityRequest extends StoreBlockMunicipalityRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => ['sometimes', 'required', 'string', 'in:Block,Municipality'],
            'district_id' => ['sometimes', 'required', 'bail', 'string', 'max:1024', new EncryptedDistrictId],
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(['name', 'type', 'district_id', 'subdivision_id'], array_keys($this->all())) === []) {
                $validator->errors()->add('name', 'Provide a name, type, district_id or subdivision_id to update.');
            }
        }];
    }
}
