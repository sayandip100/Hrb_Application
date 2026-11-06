<?php

namespace App\Http\Requests;

use App\Rules\EncryptedRecruitmentId;
use Illuminate\Foundation\Http\FormRequest;

class ListDisciplinesRequest extends FormRequest
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
            'recruitment_id' => ['sometimes', 'bail', 'string', 'max:1024', new EncryptedRecruitmentId],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
