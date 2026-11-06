<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockMunicipalityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('block-municipality', $this->block_id),
            'name' => $this->block_name, 'type' => $this->block_type,
            'district_id' => $this->whenLoaded('district', fn (): ?string => PublicId::encode('district', $this->district?->district_id)),
            'district' => $this->whenLoaded('district', fn (): array => [
                'id' => PublicId::encode('district', $this->district->district_id), 'name' => $this->district->district_name,
            ]),
            'subdivision_id' => PublicId::encode('subdivision', $this->subd_id),
            'subdivision' => $this->whenLoaded('subdivision', fn (): array => [
                'id' => PublicId::encode('subdivision', $this->subdivision->subdiv_id), 'name' => $this->subdivision->subdiv_name,
            ]),
            'status' => $this->block_status, 'is_locked' => $this->block_status === 0,
            'created_at' => $this->block_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->block_createby),
            'updated_at' => $this->block_modifydate?->format('Y-m-d H:i:s'),
            'updated_by' => PublicId::encode('staff', $this->block_modifyby),
        ];
    }
}
