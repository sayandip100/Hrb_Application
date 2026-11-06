<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PoliceStationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('police-station', $this->ps_id),
            'name' => $this->ps_name,
            'district_id' => $this->whenLoaded('district', fn (): ?string => PublicId::encode('district', $this->district?->district_id)),
            'district' => $this->whenLoaded('district', fn (): array => [
                'id' => PublicId::encode('district', $this->district->district_id),
                'name' => $this->district->district_name,
            ]),
            'status' => $this->ps_status,
            'is_locked' => $this->ps_status === 0,
            'created_at' => $this->ps_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->ps_createby),
            'updated_at' => $this->ps_modifydate?->format('Y-m-d H:i:s'),
            'updated_by' => PublicId::encode('staff', $this->ps_modifyby),
        ];
    }
}
