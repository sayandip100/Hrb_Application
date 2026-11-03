<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class RequestStaffOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'regex:/^[0-9]{10,15}$/'],
            'role_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'user_id' => ['prohibited'],
            'email' => ['prohibited'],
        ];
    }
}
