<?php

namespace App\Http\Requests;

use App\Rules\EncryptedEntityId;
use Illuminate\Foundation\Http\FormRequest;

class ListAdvertisementResubmissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'advertisement_id' => ['sometimes', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement')],
            'post_id' => ['sometimes', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement-post')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
