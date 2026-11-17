<?php

namespace App\Http\Requests;

use App\Rules\EncryptedEntityId;
use Illuminate\Validation\Rule;

class ListStaffCandidateApplicationsRequest extends StaffCandidateOptionsRequest
{
    public function rules(): array
    {
        return [
            'recruitment_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedEntityId('recruitment')],
            'advertisement_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement')],
            'post_id' => ['required', 'bail', 'string', 'max:1024', new EncryptedEntityId('advertisement-post')],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort_by' => ['sometimes', Rule::in(['name', 'application_number', 'mobile', 'email'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
