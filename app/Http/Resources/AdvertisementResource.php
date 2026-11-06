<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvertisementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicId::encode('advertisement', $this->adv_id),
            'reference' => $this->adv_auto_genno,
            'advertisement_number' => $this->adv_no,
            'recruitment_id' => PublicId::encode('recruitment', $this->adv_recruit_master),
            'recruitment' => $this->whenLoaded('recruitment', fn (): array => [
                'id' => PublicId::encode('recruitment', $this->recruitment->rm_id),
                'name' => $this->recruitment->rm_name,
            ]),
            'type' => $this->adv_type,
            'starts_at' => $this->adv_start_time?->format('Y-m-d H:i:s'),
            'ends_at' => $this->adv_end_time?->format('Y-m-d H:i:s'),
            'total_vacancies' => $this->adv_total_recruit,
            'status' => $this->adv_status,
            'is_locked' => $this->adv_status !== 1,
            'activated' => $this->adv_activated,
            'is_activated' => $this->adv_activated === 1,
            'has_document' => filled($this->adv_source_doc),
            'document_name' => filled($this->adv_source_doc) ? basename(str_replace('\\', '/', $this->adv_source_doc)) : null,
            'marks' => $this->whenLoaded('marks', fn (): array => [
                'academic' => $this->marks->amark_academic,
                'experience' => $this->marks->amark_experience,
                'interview' => $this->marks->amark_interview,
                'written' => $this->marks->amark_written,
            ]),
            'created_at' => $this->adv_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->adv_createby),
            'updated_at' => $this->adv_modifydate?->format('Y-m-d H:i:s'),
            'updated_by' => PublicId::encode('staff', $this->adv_modify_by),
        ];
    }
}
