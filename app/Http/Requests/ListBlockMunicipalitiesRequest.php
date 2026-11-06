<?php

namespace App\Http\Requests;

use App\Rules\EncryptedDistrictId;
use App\Rules\EncryptedSubdivisionId;
use Illuminate\Foundation\Http\FormRequest;

class ListBlockMunicipalitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'type' => ['sometimes', 'string', 'in:Block,Municipality'],
            'district_id' => ['sometimes', 'bail', 'string', 'max:1024', new EncryptedDistrictId],
            'subdivision_id' => ['sometimes', 'nullable', 'bail', 'string', 'max:1024', new EncryptedSubdivisionId],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
