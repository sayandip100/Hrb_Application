<?php

namespace App\Http\Requests;

use App\Rules\EncryptedDistrictId;
use Illuminate\Foundation\Http\FormRequest;

class StorePoliceStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'district_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedDistrictId],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'ps_id' => ['prohibited'],
            'ps_dist_master' => ['prohibited'],
            'ps_name' => ['prohibited'],
            'ps_createdate' => ['prohibited'],
            'ps_createby' => ['prohibited'],
            'ps_modifydate' => ['prohibited'],
            'ps_modifyby' => ['prohibited'],
            'ps_status' => ['prohibited'],
            'ps_code' => ['prohibited'],
            'code' => ['prohibited'],
        ];
    }
}
