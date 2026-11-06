<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\BlockSubdivisionOptionsRequest;
use App\Http\Requests\ListBlockMunicipalitiesRequest;
use App\Http\Requests\StoreBlockMunicipalityRequest;
use App\Http\Requests\UpdateBlockMunicipalityRequest;
use App\Http\Resources\BlockMunicipalityResource;
use App\Models\BlockMunicipality;
use App\Models\District;
use App\Models\Subdivision;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BlockMunicipalityController extends Controller
{
    public function index(ListBlockMunicipalitiesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $districts = District::blockCodeMap();
        $query = BlockMunicipality::query()->whereIn('district_id', $districts->keys()->all())->with('subdivision:subdiv_id,subdiv_name');
        if (isset($data['status'])) {
            $query->where('block_status', (int) $data['status']);
        }
        if (isset($data['type'])) {
            $query->where('block_type', $data['type']);
        }
        if (isset($data['district_id'])) {
            $id = PublicId::decode('district', $data['district_id']);
            $district = $districts->first(fn (District $district): bool => $district->district_id === $id);
            if ($district) {
                $query->where('district_id', $district->blockCode());
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        if (array_key_exists('subdivision_id', $data)) {
            if ($data['subdivision_id'] === null) {
                $query->whereNull('subd_id');
            } else {
                $query->where('subd_id', PublicId::decode('subdivision', $data['subdivision_id']));
            }
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(block_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }
        $page = $query->orderByDesc('block_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString();
        $page->getCollection()->each(fn (BlockMunicipality $record): BlockMunicipality => $record->setRelation('district', $districts->get($record->district_id)));

        return BlockMunicipalityResource::collection($page)->response()->header('Cache-Control', 'no-store');
    }

    public function districtOptions(): JsonResponse
    {
        $districts = District::blockCodeMap()->filter(fn (District $district): bool => $district->district_status === 1)
            ->sortBy('district_name')->values();

        return response()->json(['data' => $districts->map(fn (District $district): array => [
            'id' => PublicId::encode('district', $district->district_id), 'name' => $district->district_name,
        ])])->header('Cache-Control', 'no-store');
    }

    public function subdivisionOptions(BlockSubdivisionOptionsRequest $request): JsonResponse
    {
        $district = $this->selectedDistrict(PublicId::decode('district', $request->validated('district_id')));
        $subdivisions = Subdivision::query()->where('subdiv_district', $district->district_code)->where('subdiv_status', 1)
            ->orderBy('subdiv_name')->orderBy('subdiv_id')->get(['subdiv_id', 'subdiv_name']);

        return response()->json(['data' => $subdivisions->map(fn (Subdivision $subdivision): array => [
            'id' => PublicId::encode('subdivision', $subdivision->subdiv_id), 'name' => $subdivision->subdiv_name,
        ])])->header('Cache-Control', 'no-store');
    }

    public function show(BlockMunicipality $blockMunicipality): JsonResponse
    {
        return (new BlockMunicipalityResource($this->loadRelations($blockMunicipality)))->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreBlockMunicipalityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $record = DB::transaction(function () use ($data): BlockMunicipality {
            $this->lockBlockWrites();
            $district = $this->selectedDistrict(PublicId::decode('district', $data['district_id']));
            $subdivisionId = isset($data['subdivision_id']) ? PublicId::decode('subdivision', $data['subdivision_id']) : null;
            if ($subdivisionId !== null) {
                $this->selectedSubdivision($subdivisionId, $district);
            }
            $name = trim($data['name']);
            $this->assertUniqueName($name, $district->blockCode(), $subdivisionId);
            $record = new BlockMunicipality;
            $record->block_name = $name;
            $record->block_type = $data['type'];
            $record->district_id = $district->blockCode();
            $record->subd_id = $subdivisionId;
            $record->block_code = null;
            $record->block_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $record->block_createby = Auth::guard('staff')->id();
            $record->block_modifydate = null;
            $record->block_modifyby = null;
            $record->block_status = 1;
            $record->save();

            return $record;
        });

        return (new BlockMunicipalityResource($this->loadRelations($record)))->additional([
            'message' => 'Block/Municipality added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdateBlockMunicipalityRequest $request, BlockMunicipality $blockMunicipality): JsonResponse
    {
        $data = $request->validated();
        $record = DB::transaction(function () use ($data, $blockMunicipality): BlockMunicipality {
            $this->lockBlockWrites();
            $record = BlockMunicipality::query()->forMasterData()->whereKey($blockMunicipality->getKey())->lockForUpdate()->firstOrFail();
            $currentDistrict = District::blockCodeMap()->get($record->district_id);
            $district = isset($data['district_id'])
                ? $this->selectedDistrict(PublicId::decode('district', $data['district_id']), $record->district_id)
                : $currentDistrict;
            $districtChanged = $district->blockCode() !== $record->district_id;
            $subdivisionId = array_key_exists('subdivision_id', $data)
                ? ($data['subdivision_id'] === null ? null : PublicId::decode('subdivision', $data['subdivision_id']))
                : $record->subd_id;
            if ($subdivisionId !== null && ($districtChanged || array_key_exists('subdivision_id', $data))) {
                $this->selectedSubdivision($subdivisionId, $district, $districtChanged || $subdivisionId !== $record->subd_id);
            }
            $name = array_key_exists('name', $data) ? trim($data['name']) : $record->block_name;
            $this->assertUniqueName($name, $district->blockCode(), $subdivisionId, $record->getKey());
            $record->block_name = $name;
            $record->block_type = $data['type'] ?? $record->block_type;
            $record->district_id = $district->blockCode();
            $record->subd_id = $subdivisionId;
            $record->block_modifydate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $record->block_modifyby = Auth::guard('staff')->id();
            $record->save();

            return $record;
        });

        return (new BlockMunicipalityResource($this->loadRelations($record)))->additional([
            'message' => 'Block/Municipality updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(BlockMunicipality $blockMunicipality): JsonResponse
    {
        return $this->setStatus($blockMunicipality, 0, 'Block/Municipality locked successfully.');
    }

    public function unlock(BlockMunicipality $blockMunicipality): JsonResponse
    {
        return $this->setStatus($blockMunicipality, 1, 'Block/Municipality unlocked successfully.');
    }

    private function setStatus(BlockMunicipality $record, int $status, string $message): JsonResponse
    {
        $record->block_status = $status;
        $record->save();

        return (new BlockMunicipalityResource($this->loadRelations($record->refresh())))->additional([
            'message' => $message,
        ])->response()->header('Cache-Control', 'no-store');
    }

    private function loadRelations(BlockMunicipality $record): BlockMunicipality
    {
        $record->setRelation('district', District::blockCodeMap()->get($record->district_id));

        return $record->load('subdivision:subdiv_id,subdiv_name');
    }

    private function selectedDistrict(int $id, ?int $currentCode = null): District
    {
        $district = District::query()->whereKey($id)->lockForUpdate()->first();
        $code = $district?->blockCode();
        if (! $district || $code === null || District::blockCodeMap()->get($code)?->district_id !== $id
            || ($district->district_status !== 1 && $code !== $currentCode)) {
            throw ValidationException::withMessages(['district_id' => 'Select an existing, unlocked district with a valid, unique numeric district code.']);
        }

        return $district;
    }

    private function selectedSubdivision(int $id, District $district, bool $requireActive = true): Subdivision
    {
        $subdivision = Subdivision::query()->whereKey($id)->lockForUpdate()->first();
        if (! $subdivision || $subdivision->subdiv_district !== $district->district_code
            || ($requireActive && $subdivision->subdiv_status !== 1)) {
            throw ValidationException::withMessages(['subdivision_id' => 'Select an unlocked Sub-Division belonging to the selected district, or leave it empty.']);
        }

        return $subdivision;
    }

    private function lockBlockWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE block_master IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertUniqueName(string $name, int $districtCode, ?int $subdivisionId, ?int $exceptId = null): void
    {
        $query = BlockMunicipality::query()->where('district_id', $districtCode)->whereRaw('LOWER(TRIM(block_name)) = LOWER(?)', [$name]);
        if ($subdivisionId !== null) {
            $query->where('subd_id', $subdivisionId);
        }
        if ($exceptId !== null) {
            $query->where('block_id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A Block/Municipality with this name already exists in the selected district/Sub-Division.']);
        }
    }
}
