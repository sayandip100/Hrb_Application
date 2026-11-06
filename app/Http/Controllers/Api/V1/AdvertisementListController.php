<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAdvertisementsRequest;
use App\Http\Resources\AdvertisementResource;
use App\Models\Advertisement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AdvertisementListController extends Controller
{
    public function index(ListAdvertisementsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Advertisement::query()
            ->select(['adv_id', 'adv_auto_genno', 'adv_no', 'adv_recruit_master', 'adv_type',
                'adv_start_time', 'adv_end_time', 'adv_total_recruit', 'adv_source_doc',
                'adv_createdate', 'adv_createby', 'adv_modifydate', 'adv_modify_by', 'adv_status', 'adv_activated'])
            ->with(['recruitment:rm_id,rm_name', 'marks'])
            ->where('adv_recruit_master', PublicId::decode('recruitment', $data['recruitment_id']))
            ->whereHas('recruitment')->whereHas('marks');

        foreach (['status' => 'adv_status', 'activated' => 'adv_activated'] as $filter => $column) {
            if (isset($data[$filter])) {
                $query->where($column, (int) $data[$filter]);
            }
        }
        if (filled($data['search'] ?? null)) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw("LOWER(adv_no) LIKE LOWER(?) ESCAPE '!'", [$search])
                    ->orWhereRaw("LOWER(adv_auto_genno) LIKE LOWER(?) ESCAPE '!'", [$search]);
            });
        }

        return AdvertisementResource::collection(
            $query->orderByDesc('adv_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }
}
