<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CasteCommunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('caste-community', $this->csdetail_id),
            'name' => $this->csdetail_name,
            'caste_id' => PublicId::encode('age-relaxation', $this->csdetail_master),
            'caste' => $this->whenLoaded('caste', fn (): array => [
                'id' => PublicId::encode('age-relaxation', $this->caste->caste_id),
                'name' => $this->caste->caste_name,
            ]),
            'status' => $this->csdetail_status,
            'is_locked' => $this->csdetail_status === 0,
            'created_at' => $this->csdetail_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->csdetail_createby),
            'updated_at' => $this->csdetail_modifydate?->format('Y-m-d H:i:s'),
            'updated_by' => PublicId::encode('staff', $this->csdetail_modifyby),
        ];
    }
}
