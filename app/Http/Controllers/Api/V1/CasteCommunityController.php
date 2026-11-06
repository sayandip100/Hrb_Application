<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListCasteCommunitiesRequest;
use App\Http\Requests\StoreCasteCommunityRequest;
use App\Http\Requests\UpdateCasteCommunityRequest;
use App\Http\Resources\CasteCommunityResource;
use App\Models\AgeRelaxation;
use App\Models\CasteCommunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CasteCommunityController extends Controller
{
    public function index(ListCasteCommunitiesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = CasteCommunity::query()->forMasterData()->with('caste:caste_id,caste_name');
        if (isset($data['status'])) {
            $query->where('csdetail_status', (int) $data['status']);
        }
        if (isset($data['caste_id'])) {
            $query->where('csdetail_master', PublicId::decode('age-relaxation', $data['caste_id']));
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(csdetail_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return CasteCommunityResource::collection(
            $query->orderBy('csdetail_name')->orderBy('csdetail_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }

    public function casteOptions(): JsonResponse
    {
        $castes = AgeRelaxation::query()->where('caste_cat', 2)->whereNull('caste_parent')->where('caste_status', 1)
            ->orderBy('caste_name')->orderBy('caste_id')->get(['caste_id', 'caste_name']);

        return response()->json(['data' => $castes->map(fn (AgeRelaxation $caste): array => [
            'id' => PublicId::encode('age-relaxation', $caste->caste_id),
            'name' => $caste->caste_name,
        ])])->header('Cache-Control', 'no-store');
    }

    public function show(CasteCommunity $casteCommunity): JsonResponse
    {
        return (new CasteCommunityResource($casteCommunity->load('caste:caste_id,caste_name')))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreCasteCommunityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $community = DB::transaction(function () use ($data): CasteCommunity {
            $caste = $this->selectableCaste(PublicId::decode('age-relaxation', $data['caste_id']));
            $community = new CasteCommunity;
            $community->csdetail_name = trim($data['name']);
            $community->csdetail_master = $caste->getKey();
            $community->csdetail_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $community->csdetail_createby = Auth::guard('staff')->id();
            $community->csdetail_modifydate = null;
            $community->csdetail_modifyby = null;
            $community->csdetail_status = 1;
            $community->save();
            $community->setRelation('caste', $caste);

            return $community;
        });

        return (new CasteCommunityResource($community))->additional([
            'message' => 'Caste community added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdateCasteCommunityRequest $request, CasteCommunity $casteCommunity): JsonResponse
    {
        $data = $request->validated();
        $community = DB::transaction(function () use ($data, $casteCommunity): CasteCommunity {
            $record = CasteCommunity::query()->forMasterData()->whereKey($casteCommunity->getKey())->lockForUpdate()->firstOrFail();
            if (array_key_exists('caste_id', $data)) {
                $id = PublicId::decode('age-relaxation', $data['caste_id']);
                if ($id !== $record->csdetail_master) {
                    $record->csdetail_master = $this->selectableCaste($id)->getKey();
                }
            }
            if (array_key_exists('name', $data)) {
                $record->csdetail_name = trim($data['name']);
            }
            $record->csdetail_modifydate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $record->csdetail_modifyby = Auth::guard('staff')->id();
            $record->save();

            return $record->load('caste:caste_id,caste_name');
        });

        return (new CasteCommunityResource($community))->additional([
            'message' => 'Caste community updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(CasteCommunity $casteCommunity): JsonResponse
    {
        return $this->setStatus($casteCommunity, 0, 'Caste community locked successfully.');
    }

    public function unlock(CasteCommunity $casteCommunity): JsonResponse
    {
        return $this->setStatus($casteCommunity, 1, 'Caste community unlocked successfully.');
    }

    private function setStatus(CasteCommunity $community, int $status, string $message): JsonResponse
    {
        $community->csdetail_status = $status;
        $community->save();

        return (new CasteCommunityResource($community->refresh()->load('caste:caste_id,caste_name')))->additional([
            'message' => $message,
        ])->response()->header('Cache-Control', 'no-store');
    }

    private function selectableCaste(int $id): AgeRelaxation
    {
        $caste = AgeRelaxation::query()->whereKey($id)->where('caste_cat', 2)->whereNull('caste_parent')
            ->where('caste_status', 1)->lockForUpdate()->first();
        if (! $caste) {
            throw ValidationException::withMessages(['caste_id' => 'Select an existing, unlocked top-level caste.']);
        }

        return $caste;
    }
}
