<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffCandidateApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('candidate', $this->f_uid),
            'application_number' => $this->f_application_no,
            'name' => $this->f_full_name,
            'mobile' => $this->f_mobile,
            'email' => $this->f_email,
        ];
    }
}
