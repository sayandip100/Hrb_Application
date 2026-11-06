<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QualificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('qualification', $this->qm_id),
            'name' => $this->qm_name,
            'recruitment_id' => PublicId::encode('recruitment', $this->qm_r_master),
            'recruitment' => $this->whenLoaded('recruitment', fn (): array => [
                'id' => PublicId::encode('recruitment', $this->recruitment->rm_id),
                'name' => $this->recruitment->rm_name,
            ]),
            'status' => $this->qm_status,
            'is_locked' => $this->qm_status === 0,
            'created_at' => $this->qm_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->qm_createby),
        ];
    }
}
