<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgeRelaxationRequest extends FormRequest
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
            'category' => ['prohibited'],
            'parent_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'caste_id' => ['prohibited'],
            'caste_status' => ['prohibited'],
            'caste_cat' => ['prohibited'],
            'caste_parent' => ['prohibited'],
            'caste_attach' => ['prohibited'],
            'caste_createby' => ['prohibited'],
            'caste_createdate' => ['prohibited'],
        ];
    }
}
