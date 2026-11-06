<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class StoreAdvertisementResubmissionRequest extends PreviewAdvertisementResubmissionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'starts_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'ends_at' => ['required', 'date_format:Y-m-d H:i:s', 'after:starts_at'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'This field is not accepted.');
            }
        }];
    }
}
