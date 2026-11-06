<?php

namespace App\Http\Resources;

use App\Domain\Identity\AdvertisementPayload;
use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvertisementDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('advertisement-draft', $this->id),
            'reference' => $this->reference,
            'state' => $this->state,
            'version' => $this->version,
            'details' => AdvertisementPayload::publicPayload($this->payload ?? []),
            'has_document' => $this->document_path !== null,
            'submitted_advertisement_id' => PublicId::encode('advertisement', $this->submitted_advertisement_id),
            'created_by' => PublicId::encode('staff', $this->created_by),
            'updated_by' => PublicId::encode('staff', $this->updated_by),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
        ];
    }
}
