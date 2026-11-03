<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class VerifyStaffOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'uuid'],
            'otp' => ['required', 'string', 'digits:6'],
            'role_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
