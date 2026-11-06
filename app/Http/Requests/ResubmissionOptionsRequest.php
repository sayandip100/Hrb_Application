<?php

namespace App\Http\Requests;

use App\Rules\EncryptedEntityId;
use Illuminate\Foundation\Http\FormRequest;

class ResubmissionOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recruitment_id' => ['required_with:advertisement_id', 'bail', 'string', 'max:1024', new EncryptedEntityId('recruitment')],
            'advertisement_id' => ['sometimes', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement')],
        ];
    }
}
