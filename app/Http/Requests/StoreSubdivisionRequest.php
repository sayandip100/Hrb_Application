<?php

namespace App\Http\Requests;

use App\Rules\EncryptedDistrictId;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubdivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'district_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedDistrictId],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'subdiv_id' => ['prohibited'],
            'subdiv_district' => ['prohibited'],
            'subdiv_name' => ['prohibited'],
            'subdiv_createdate' => ['prohibited'],
            'subdiv_createby' => ['prohibited'],
            'subdiv_modifydate' => ['prohibited'],
            'subdiv_modifyby' => ['prohibited'],
            'subdiv_status' => ['prohibited'],
            'subdiv_code' => ['prohibited'],
            'code' => ['prohibited'],
        ];
    }
}
