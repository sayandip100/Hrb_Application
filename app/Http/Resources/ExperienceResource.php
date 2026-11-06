<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExperienceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('experience', $this->expset_id),
            'name' => $this->expset_name,
            'status' => $this->expset_status,
            'is_locked' => $this->expset_status === 0,
            'created_at' => $this->expset_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->expset_createby),
            'updated_at' => $this->expset_modifydate?->format('Y-m-d H:i:s'),
            'updated_by' => PublicId::encode('staff', $this->expset_modifyby),
        ];
    }
}
