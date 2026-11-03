<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecruitmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:650'],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'rm_id' => ['prohibited'],
            'rm_status' => ['prohibited'],
            'rm_createby' => ['prohibited'],
            'rm_createdate' => ['prohibited'],
        ];
    }
}
