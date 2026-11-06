<?php

namespace App\Http\Requests;

use App\Rules\EncryptedDistrictId;
use App\Rules\EncryptedSubdivisionId;
use Illuminate\Foundation\Http\FormRequest;

class StoreBlockMunicipalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:Block,Municipality'],
            'district_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedDistrictId],
            'subdivision_id' => ['sometimes', 'nullable', 'bail', 'string', 'max:1024', new EncryptedSubdivisionId],
            'id' => ['prohibited'], 'status' => ['prohibited'], 'code' => ['prohibited'],
            'created_by' => ['prohibited'], 'created_at' => ['prohibited'],
            'updated_by' => ['prohibited'], 'updated_at' => ['prohibited'],
            'block_id' => ['prohibited'], 'block_name' => ['prohibited'], 'block_type' => ['prohibited'],
            'block_code' => ['prohibited'], 'subd_id' => ['prohibited'], 'block_status' => ['prohibited'],
            'block_createby' => ['prohibited'], 'block_createdate' => ['prohibited'],
            'block_modifyby' => ['prohibited'], 'block_modifydate' => ['prohibited'],
        ];
    }
}
