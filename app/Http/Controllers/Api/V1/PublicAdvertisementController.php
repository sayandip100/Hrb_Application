<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\CandidateApplicationService;
use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicAdvertisementController extends Controller
{
    public function index(Request $request, CandidateApplicationService $service): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $now = now('Asia/Kolkata')->format('Y-m-d H:i:s');
        $query = Advertisement::query()->where('adv_status', 1)->where('adv_activated', 1)->where(function ($query) use ($now): void {
            $query->where(fn ($q) => $q->where('adv_type', 'New')->where('adv_start_time', '<', $now)->where('adv_end_time', '>', $now))
                ->orWhere(fn ($q) => $q->where('adv_type', 'Old')->where('adv_old_starttime', '<', $now)->where('adv_old_endtime', '>', $now));
        });

        return JsonResource::collection($query->orderByDesc('adv_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString()->through(fn ($ad) => $service->publicData($ad)));
    }

    public function show(string $advertisement, CandidateApplicationService $service): JsonResource
    {
        return new JsonResource($service->publicData(CandidateApplicationService::publicAdvertisement($advertisement), true));
    }
}
