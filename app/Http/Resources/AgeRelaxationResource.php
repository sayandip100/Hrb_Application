<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgeRelaxationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('age-relaxation', $this->caste_id),
            'name' => $this->caste_name,
            'category' => $this->caste_cat,
            'status' => $this->caste_status,
            'is_locked' => $this->caste_status === 0,
            'created_at' => $this->caste_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->caste_createby),
        ];
    }
}
