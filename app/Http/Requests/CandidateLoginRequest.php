<?php

namespace App\Http\Requests;

use App\Rules\EncryptedEntityId;
use Illuminate\Foundation\Http\FormRequest;

class CandidateLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['advertisement_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement')],
            'mobile' => ['required', 'string', 'regex:/\A[0-9]{10}\z/'], 'password' => ['required', 'string', 'max:200']];
    }
}
