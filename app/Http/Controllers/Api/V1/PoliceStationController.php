<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListPoliceStationsRequest;
use App\Http\Requests\StorePoliceStationRequest;
use App\Http\Requests\UpdatePoliceStationRequest;
use App\Http\Resources\PoliceStationResource;
use App\Models\District;
use App\Models\PoliceStation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PoliceStationController extends Controller
{
    public function index(ListPoliceStationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = PoliceStation::query()->forMasterData()->with('district:district_id,district_code,district_name');
        if (isset($data['status'])) {
            $query->where('ps_status', (int) $data['status']);
        }
        if (isset($data['district_id'])) {
            $id = PublicId::decode('district', $data['district_id']);
            $query->whereHas('district', fn (Builder $district): Builder => $district->where('district_id', $id));
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(ps_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return PoliceStationResource::collection(
            $query->orderByDesc('ps_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
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

    public function show(PoliceStation $policeStation): JsonResponse
    {
        return (new PoliceStationResource($policeStation->load('district:district_id,district_code,district_name')))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StorePoliceStationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $policeStation = DB::transaction(function () use ($data): PoliceStation {
            $this->lockPoliceStationWrites();
            $district = $this->selectedDistrict(PublicId::decode('district', $data['district_id']));
            $name = trim($data['name']);
            $this->assertUniqueName($name, $district->district_code);
            $policeStation = new PoliceStation;
            $policeStation->ps_name = $name;
            $policeStation->ps_dist_master = $district->district_code;
            $policeStation->ps_code = null;
            $policeStation->ps_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $policeStation->ps_createby = Auth::guard('staff')->id();
            $policeStation->ps_modifydate = null;
            $policeStation->ps_modifyby = null;
            $policeStation->ps_status = 1;
            $policeStation->save();
            $policeStation->setRelation('district', $district);

            return $policeStation;
        });

        return (new PoliceStationResource($policeStation))->additional([
            'message' => 'Police Station added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdatePoliceStationRequest $request, PoliceStation $policeStation): JsonResponse
    {
        $data = $request->validated();
        $policeStation = DB::transaction(function () use ($data, $policeStation): PoliceStation {
            $this->lockPoliceStationWrites();
            $record = PoliceStation::query()->forMasterData()->whereKey($policeStation->getKey())->lockForUpdate()->firstOrFail();
            if (array_key_exists('district_id', $data)) {
                $currentDistrict = $record->district;
                $id = PublicId::decode('district', $data['district_id']);
                if ($id !== $currentDistrict?->district_id) {
                    $record->ps_dist_master = $this->selectedDistrict($id)->district_code;
                }
            }
            $name = array_key_exists('name', $data) ? trim($data['name']) : $record->ps_name;
            $this->assertUniqueName($name, $record->ps_dist_master, $record->getKey());
            $record->ps_name = $name;
            $record->ps_modifydate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $record->ps_modifyby = Auth::guard('staff')->id();
            $record->save();

            return $record->load('district:district_id,district_code,district_name');
        });

        return (new PoliceStationResource($policeStation))->additional([
            'message' => 'Police Station updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(PoliceStation $policeStation): JsonResponse
    {
        return $this->setStatus($policeStation, 0, 'Police Station locked successfully.');
    }

    public function unlock(PoliceStation $policeStation): JsonResponse
    {
        return $this->setStatus($policeStation, 1, 'Police Station unlocked successfully.');
    }

    private function setStatus(PoliceStation $policeStation, int $status, string $message): JsonResponse
    {
        $policeStation->ps_status = $status;
        $policeStation->save();

        return (new PoliceStationResource($policeStation->refresh()->load('district:district_id,district_code,district_name')))->additional([
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

    private function lockPoliceStationWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE police_station_tab IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertUniqueName(string $name, string $districtCode, ?int $exceptId = null): void
    {
        $query = PoliceStation::query()->where('ps_dist_master', $districtCode)->whereRaw('LOWER(TRIM(ps_name)) = LOWER(?)', [$name]);
        if ($exceptId !== null) {
            $query->where('ps_id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A Police Station with this name already exists in the selected district.']);
        }
    }
}
