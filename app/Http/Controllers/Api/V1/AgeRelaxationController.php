<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAgeRelaxationsRequest;
use App\Http\Requests\StoreAgeRelaxationRequest;
use App\Http\Resources\AgeRelaxationResource;
use App\Models\AgeRelaxation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgeRelaxationController extends Controller
{
    public function index(ListAgeRelaxationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = AgeRelaxation::query();
        if (isset($data['status'])) {
            $query->where('caste_status', (int) $data['status']);
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(caste_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return AgeRelaxationResource::collection(
            $query->orderByDesc('caste_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }

    public function show(AgeRelaxation $ageRelaxation): JsonResponse
    {
        return (new AgeRelaxationResource($ageRelaxation))->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreAgeRelaxationRequest $request): JsonResponse
    {
        $name = trim($request->validated('name'));
        $ageRelaxation = DB::transaction(function () use ($name): AgeRelaxation {
            $this->lockAgeRelaxationWrites();
            $this->assertUniqueName($name);
            $ageRelaxation = new AgeRelaxation;
            $ageRelaxation->caste_name = $name;
            $ageRelaxation->caste_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $ageRelaxation->caste_createby = Auth::guard('staff')->id();
            $ageRelaxation->caste_status = 1;
            $ageRelaxation->caste_cat = 8;
            $ageRelaxation->caste_parent = null;
            $ageRelaxation->caste_attach = null;
            $ageRelaxation->save();

            return $ageRelaxation;
        });

        return (new AgeRelaxationResource($ageRelaxation))->additional([
            'message' => 'Age relaxation added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(StoreAgeRelaxationRequest $request, AgeRelaxation $ageRelaxation): JsonResponse
    {
        $name = trim($request->validated('name'));
        $ageRelaxation = DB::transaction(function () use ($ageRelaxation, $name): AgeRelaxation {
            $this->lockAgeRelaxationWrites();
            $record = AgeRelaxation::query()->whereKey($ageRelaxation->getKey())->lockForUpdate()->firstOrFail();
            $this->assertUniqueName($name, $record->getKey());
            $record->caste_name = $name;
            $record->save();

            return $record;
        });

        return (new AgeRelaxationResource($ageRelaxation))->additional([
            'message' => 'Age relaxation updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(AgeRelaxation $ageRelaxation): JsonResponse
    {
        return $this->setStatus($ageRelaxation, 0, 'Age relaxation locked successfully.');
    }

    public function unlock(AgeRelaxation $ageRelaxation): JsonResponse
    {
        return $this->setStatus($ageRelaxation, 1, 'Age relaxation unlocked successfully.');
    }

    private function setStatus(AgeRelaxation $ageRelaxation, int $status, string $message): JsonResponse
    {
        $ageRelaxation->caste_status = $status;
        $ageRelaxation->save();

        return (new AgeRelaxationResource($ageRelaxation->refresh()))->additional([
            'message' => $message,
        ])->response()->header('Cache-Control', 'no-store');
    }

    private function lockAgeRelaxationWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE caste_tab IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $query = DB::table('caste_tab')->whereRaw('LOWER(TRIM(caste_name)) = LOWER(?)', [$name]);
        if ($exceptId !== null) {
            $query->where('caste_id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'An age relaxation with this name already exists.',
            ]);
        }
    }
}
