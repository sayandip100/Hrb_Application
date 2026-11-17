<?php

namespace App\Http\Requests;

use App\Rules\EncryptedEntityId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CandidateRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->routeIs('candidate.auth.register')) {
            return ['challenge_id' => ['required', 'uuid'], 'otp' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
                'password' => ['bail', 'required', 'string', 'confirmed', Password::min(12)->letters()->numbers(), function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('Password must not exceed 72 bytes.');
                    }
                }]];
        }

        return ['advertisement_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement')],
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:200'],
            'mobile' => ['required', 'string', 'regex:/\A[0-9]{10}\z/']];
    }
}
