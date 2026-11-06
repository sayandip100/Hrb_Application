<?php

namespace App\Http\Resources;

use App\Domain\Identity\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvertisementResubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now('Asia/Kolkata')->format('Y-m-d H:i:s');
        $starts = $this->adv_resub_starttime->format('Y-m-d H:i:s');
        $ends = $this->adv_resub_endtime->format('Y-m-d H:i:s');
        $managed = $this->eligible_candidates !== null;
        $state = match (true) {
            $this->adv_resub_chk_stat === 1 => 'processed',
            $this->adv_resub_status !== 1 => 'disabled',
            $now < $starts => 'scheduled',
            $now > $ends => 'closed',
            default => 'open',
        };

        return [
            'id' => PublicId::encode('advertisement-resubmission', $this->adv_resub_id),
            'advertisement_id' => PublicId::encode('advertisement', $this->advertisement?->adv_id),
            'advertisement_number' => $this->advertisement?->adv_no,
            'reference' => $this->adv_resub_master,
            'post_id' => PublicId::encode('advertisement-post', $this->adv_resub_cat),
            'post_name' => $this->post_name,
            'starts_at' => $starts, 'ends_at' => $ends, 'timezone' => 'Asia/Kolkata',
            'reason' => $this->adv_resub_reasons, 'state' => $state,
            'is_managed' => $managed, 'can_process' => $managed && $state === 'closed',
            'eligible_candidates' => $managed ? (int) $this->eligible_candidates : null,
            'processed_candidates' => $this->processed_candidates === null ? null : (int) $this->processed_candidates,
            'deleted_checks' => $this->deleted_checks === null ? null : (int) $this->deleted_checks,
            'notifications' => $managed ? ['pending' => (int) $this->notifications_pending, 'sent' => (int) $this->notifications_sent,
                'failed' => (int) $this->notifications_failed, 'invalid' => (int) $this->notifications_invalid, 'skipped' => (int) $this->notifications_skipped] : null,
            'created_at' => $this->adv_resub_createdate?->format('Y-m-d H:i:s'),
            'created_by' => PublicId::encode('staff', $this->adv_resub_createby),
            'processed_at' => $this->adv_resub_chk_start?->format('Y-m-d H:i:s'),
            'updated_by' => PublicId::encode('staff', $this->adv_resub_modifyby),
        ];
    }
}
