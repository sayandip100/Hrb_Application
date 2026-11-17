<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StaffCandidateActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', 'max:255']];
        $file = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5000'];
        if ($this->routeIs('candidates.applications.profile')) {
            return $rules + [
                'name' => ['sometimes', 'string', 'max:255'],
                'mobile' => ['sometimes', 'string', 'regex:/\A[0-9]{10}\z/'],
                'email' => ['sometimes', 'email:rfc', 'max:200'],
                'father_name' => ['sometimes', 'string', 'max:250'],
                'mother_name' => ['sometimes', 'string', 'max:250'],
                'evidence' => $file,
            ];
        }
        if ($this->routeIs('candidates.applications.dob')) {
            return $rules + ['dob' => ['required', 'date_format:Y-m-d', 'before:today']];
        }
        if ($this->routeIs('candidates.applications.reject')) {
            return $rules + ['evidence' => $file];
        }

        return $rules + [
            'section' => ['required', Rule::in(['essential-qualifications', 'desirable-qualifications', 'essential-experiences', 'desirable-experiences'])],
            'changes' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'changes.*' => ['array:entry_id,option_id'],
            'changes.*.entry_id' => ['required', 'string', 'max:1024'],
            'changes.*.option_id' => ['required', 'string', 'max:1024'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'This field is not accepted.');
            }
            if ($this->routeIs('candidates.applications.profile') && count(array_intersect(array_keys($this->all()), ['name', 'mobile', 'email', 'father_name', 'mother_name'])) === 0) {
                $validator->errors()->add('name', 'Provide at least one profile field to change.');
            }
        }];
    }
}
