<?php

namespace App\Http\Requests;

use App\Rules\EncryptedDistrictId;
use Illuminate\Foundation\Http\FormRequest;

class BlockSubdivisionOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['district_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedDistrictId]];
    }
}
