<?php

namespace App\Http\Requests;

use App\Rules\EncryptedCasteId;
use Illuminate\Foundation\Http\FormRequest;

class StoreCasteCommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:450'],
            'caste_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedCasteId],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'csdetail_id' => ['prohibited'],
            'csdetail_master' => ['prohibited'],
            'csdetail_name' => ['prohibited'],
            'csdetail_createdate' => ['prohibited'],
            'csdetail_createby' => ['prohibited'],
            'csdetail_modifydate' => ['prohibited'],
            'csdetail_modifyby' => ['prohibited'],
            'csdetail_status' => ['prohibited'],
        ];
    }
}
