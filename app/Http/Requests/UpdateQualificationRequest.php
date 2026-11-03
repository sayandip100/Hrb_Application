<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class UpdateQualificationRequest extends StoreQualificationRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'name' => ['sometimes', 'required', 'string', 'max:850'],
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $this->all();
            if (! array_key_exists('name', $data) && ! array_key_exists('recruitment_id', $data)) {
                $validator->errors()->add('name', 'Provide a name or recruitment_id to update.');
            }
        }];
    }
}
