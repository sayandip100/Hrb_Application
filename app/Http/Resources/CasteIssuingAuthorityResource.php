<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CasteIssuingAuthorityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('caste-issuing-authority', $this->cia_id),
            'name' => $this->cia_name,
            'status' => $this->cia_status,
            'is_locked' => $this->cia_status === 0,
            'created_at' => $this->cia_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->cia_createby),
            'updated_at' => $this->cia_modifydate?->format('Y-m-d H:i:s'),
            'updated_by' => PublicId::encode('staff', $this->cia_modifyby),
        ];
    }
}
