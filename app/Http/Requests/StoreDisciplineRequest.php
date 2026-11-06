<?php

namespace App\Http\Requests;

use App\Rules\EncryptedRecruitmentId;
use Illuminate\Foundation\Http\FormRequest;

class StoreDisciplineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'recruitment_id' => ['sometimes', 'nullable', 'bail', 'string', 'max:1024', new EncryptedRecruitmentId],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'catm_id' => ['prohibited'],
            'catm_status' => ['prohibited'],
            'catm_createby' => ['prohibited'],
            'catm_createdate' => ['prohibited'],
            'catm_master' => ['prohibited'],
        ];
    }
}
