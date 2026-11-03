<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecruitmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->rm_id,
            'name' => $this->rm_name,
            'status' => $this->rm_status,
            'is_locked' => $this->rm_status === 0,
            'created_at' => $this->rm_createdate?->format('Y-m-d H:i:s'),
            'created_by' => $this->rm_createby,
        ];
    }
}
