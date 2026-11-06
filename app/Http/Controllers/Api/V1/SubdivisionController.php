<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListSubdivisionsRequest;
use App\Http\Requests\StoreSubdivisionRequest;
use App\Http\Requests\UpdateSubdivisionRequest;
use App\Http\Resources\SubdivisionResource;
use App\Models\District;
use App\Models\Subdivision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubdivisionController extends Controller
{
    public function index(ListSubdivisionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Subdivision::query()->forMasterData()->with('district:district_id,district_code,district_name');
        if (isset($data['status'])) {
            $query->where('subdiv_status', (int) $data['status']);
        }
        if (isset($data['district_id'])) {
            $id = PublicId::decode('district', $data['district_id']);
            $query->whereHas('district', fn (Builder $district): Builder => $district->where('district_id', $id));
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(subdiv_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return SubdivisionResource::collection(
            $query->orderByDesc('subdiv_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }

    public function districtOptions(): JsonResponse
    {
        $districts = District::query()->selectableForSubdivisions()->orderBy('district_name')->orderBy('district_id')
            ->get(['district_id', 'district_name']);

        return response()->json(['data' => $districts->map(fn (District $district): array => [
            'id' => PublicId::encode('district', $district->district_id), 'name' => $district->district_name,
        ])])->header('Cache-Control', 'no-store');
    }

    public function show(Subdivision $subdivision): JsonResponse
    {
        return (new SubdivisionResource($subdivision->load('district:district_id,district_code,district_name')))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreSubdivisionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $subdivision = DB::transaction(function () use ($data): Subdivision {
            $this->lockSubdivisionWrites();
            $district = $this->selectedDistrict(PublicId::decode('district', $data['district_id']));
            $name = trim($data['name']);
            $this->assertUniqueName($name, $district->district_code);
            $subdivision = new Subdivision;
            $subdivision->subdiv_name = $name;
            $subdivision->subdiv_district = $district->district_code;
            $subdivision->subdiv_code = null;
            $subdivision->subdiv_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $subdivision->subdiv_createby = Auth::guard('staff')->id();
            $subdivision->subdiv_modifydate = null;
            $subdivision->subdiv_modifyby = null;
            $subdivision->subdiv_status = 1;
            $subdivision->save();
            $subdivision->setRelation('district', $district);

            return $subdivision;
        });

        return (new SubdivisionResource($subdivision))->additional([
            'message' => 'Sub-Division added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdateSubdivisionRequest $request, Subdivision $subdivision): JsonResponse
    {
        $data = $request->validated();
        $subdivision = DB::transaction(function () use ($data, $subdivision): Subdivision {
            $this->lockSubdivisionWrites();
            $record = Subdivision::query()->forMasterData()->whereKey($subdivision->getKey())->lockForUpdate()->firstOrFail();
            if (array_key_exists('district_id', $data)) {
                $currentDistrict = $record->district;
                $id = PublicId::decode('district', $data['district_id']);
                if ($id !== $currentDistrict?->district_id) {
                    $record->subdiv_district = $this->selectedDistrict($id)->district_code;
                }
            }
            $name = array_key_exists('name', $data) ? trim($data['name']) : $record->subdiv_name;
            $this->assertUniqueName($name, $record->subdiv_district, $record->getKey());
            $record->subdiv_name = $name;
            $record->subdiv_modifydate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $record->subdiv_modifyby = Auth::guard('staff')->id();
            $record->save();

            return $record->load('district:district_id,district_code,district_name');
        });

        return (new SubdivisionResource($subdivision))->additional([
            'message' => 'Sub-Division updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(Subdivision $subdivision): JsonResponse
    {
        return $this->setStatus($subdivision, 0, 'Sub-Division locked successfully.');
    }

    public function unlock(Subdivision $subdivision): JsonResponse
    {
        return $this->setStatus($subdivision, 1, 'Sub-Division unlocked successfully.');
    }

    private function setStatus(Subdivision $subdivision, int $status, string $message): JsonResponse
    {
        $subdivision->subdiv_status = $status;
        $subdivision->save();

        return (new SubdivisionResource($subdivision->refresh()->load('district:district_id,district_code,district_name')))->additional([
            'message' => $message,
        ])->response()->header('Cache-Control', 'no-store');
    }

    private function selectedDistrict(int $id): District
    {
        $district = District::query()->selectableForSubdivisions()->whereKey($id)->lockForUpdate()->first();
        if (! $district) {
            throw ValidationException::withMessages(['district_id' => 'Select an existing, unlocked district with a valid, unique district code.']);
        }

        return $district;
    }

    private function lockSubdivisionWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE subdivision_tab IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertUniqueName(string $name, string $districtCode, ?int $exceptId = null): void
    {
        $query = Subdivision::query()->where('subdiv_district', $districtCode)->whereRaw('LOWER(TRIM(subdiv_name)) = LOWER(?)', [$name]);
        if ($exceptId !== null) {
            $query->where('subdiv_id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A Sub-Division with this name already exists in the selected district.']);
        }
    }
}
