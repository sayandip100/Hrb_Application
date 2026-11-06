<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCasteIssuingAuthorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:250'],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'cia_id' => ['prohibited'],
            'cia_name' => ['prohibited'],
            'cia_status' => ['prohibited'],
            'cia_createby' => ['prohibited'],
            'cia_createdate' => ['prohibited'],
            'cia_modifyby' => ['prohibited'],
            'cia_modifydate' => ['prohibited'],
            'recruitment_id' => ['prohibited'],
        ];
    }
}
