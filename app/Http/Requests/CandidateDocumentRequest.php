<?php

namespace App\Http\Requests;

use App\Domain\Identity\CandidateApplicationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CandidateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $image = in_array($this->input('purpose'), ['photo', 'signature'], true);

        return ['version' => ['required', 'integer', 'min:1'], 'purpose' => ['required', Rule::in(CandidateApplicationRules::PURPOSES)],
            'file' => $image ? ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'extensions:jpg,jpeg,png', 'max:2048']
                : ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120']];
    }
}
