<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:21844'],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'expset_id' => ['prohibited'],
            'expset_status' => ['prohibited'],
            'expset_createby' => ['prohibited'],
            'expset_createdate' => ['prohibited'],
            'expset_modifyby' => ['prohibited'],
            'expset_modifydate' => ['prohibited'],
            'recruitment_id' => ['prohibited'],
        ];
    }
}
