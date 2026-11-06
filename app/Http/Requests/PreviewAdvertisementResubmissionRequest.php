<?php

namespace App\Http\Requests;

use App\Rules\EncryptedEntityId;
use Illuminate\Foundation\Http\FormRequest;

class PreviewAdvertisementResubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'advertisement_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement')],
            'post_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement-post')],
        ];
    }
}
