<?php

namespace App\Http\Requests;

use App\Domain\Identity\CandidateApplicationRules;
use Illuminate\Foundation\Http\FormRequest;

class SaveCandidateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = CandidateApplicationRules::section($this->route('section'));
        foreach ($rules as &$fieldRules) {
            $fieldRules = array_map(fn ($rule) => is_string($rule) ? str_replace('lte:items.', 'lte:data.items.', $rule) : $rule, $fieldRules);
        }
        unset($fieldRules);
        $keys = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));

        return array_merge(['version' => ['required', 'integer', 'min:1'], 'data' => ['required', 'array:'.implode(',', $keys), 'min:1']],
            array_combine(array_map(fn ($key) => 'data.'.$key, array_keys($rules)), array_values($rules)));
    }
}
