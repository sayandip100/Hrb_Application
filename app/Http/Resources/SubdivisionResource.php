<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubdivisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('subdivision', $this->subdiv_id),
            'name' => $this->subdiv_name,
            'district_id' => $this->whenLoaded('district', fn (): ?string => PublicId::encode('district', $this->district?->district_id)),
            'district' => $this->whenLoaded('district', fn (): array => [
                'id' => PublicId::encode('district', $this->district->district_id),
                'name' => $this->district->district_name,
            ]),
            'status' => $this->subdiv_status,
            'is_locked' => $this->subdiv_status === 0,
            'created_at' => $this->subdiv_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->subdiv_createby),
            'updated_at' => $this->subdiv_modifydate?->format('Y-m-d H:i:s'),
            'updated_by' => PublicId::encode('staff', $this->subdiv_modifyby),
        ];
    }
}
