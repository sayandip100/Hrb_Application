<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:850'],
            'recruitment_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'qm_id' => ['prohibited'],
            'qm_status' => ['prohibited'],
            'qm_createby' => ['prohibited'],
            'qm_createdate' => ['prohibited'],
            'qm_r_master' => ['prohibited'],
        ];
    }
}
