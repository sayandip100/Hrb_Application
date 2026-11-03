<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffUserResource extends JsonResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('Cache-Control', 'no-store');
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->u_id,
            'name' => trim($this->firstname.' '.$this->lastname),
            'mobile' => $this->mobile,
            'role' => [
                'id' => $this->role->mu_id,
                'name' => $this->role->mu_name,
            ],
        ];
    }
}
