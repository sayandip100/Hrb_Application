<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisciplineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('discipline', $this->catm_id),
            'name' => $this->catm_name,
            'recruitment_id' => PublicId::encode('recruitment', $this->catm_master),
            'recruitment' => $this->whenLoaded('recruitment', fn (): array => [
                'id' => PublicId::encode('recruitment', $this->recruitment->rm_id),
                'name' => $this->recruitment->rm_name,
            ]),
            'status' => $this->catm_status,
            'is_locked' => $this->catm_status === 0,
            'created_at' => $this->catm_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->catm_createby),
        ];
    }
}
