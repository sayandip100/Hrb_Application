<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListCasteIssuingAuthoritiesRequest;
use App\Http\Requests\StoreCasteIssuingAuthorityRequest;
use App\Http\Resources\CasteIssuingAuthorityResource;
use App\Models\CasteIssuingAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CasteIssuingAuthorityController extends Controller
{
    public function index(ListCasteIssuingAuthoritiesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = CasteIssuingAuthority::query();
        if (isset($data['status'])) {
            $query->where('cia_status', (int) $data['status']);
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(cia_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return CasteIssuingAuthorityResource::collection(
            $query->orderBy('cia_name')->orderBy('cia_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }

    public function show(CasteIssuingAuthority $casteIssuingAuthority): JsonResponse
    {
        return (new CasteIssuingAuthorityResource($casteIssuingAuthority))->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreCasteIssuingAuthorityRequest $request): JsonResponse
    {
        $name = trim($request->validated('name'));
        $casteIssuingAuthority = DB::transaction(function () use ($name): CasteIssuingAuthority {
            $this->lockCasteIssuingAuthorityWrites();
            $this->assertUniqueName($name);
            $casteIssuingAuthority = new CasteIssuingAuthority;
            $casteIssuingAuthority->cia_name = $name;
            $casteIssuingAuthority->cia_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $casteIssuingAuthority->cia_createby = Auth::guard('staff')->id();
            $casteIssuingAuthority->cia_status = 1;
            $casteIssuingAuthority->cia_modifydate = null;
            $casteIssuingAuthority->cia_modifyby = null;
            $casteIssuingAuthority->save();

            return $casteIssuingAuthority;
        });

        return (new CasteIssuingAuthorityResource($casteIssuingAuthority))->additional([
            'message' => 'Caste issuing authority added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(StoreCasteIssuingAuthorityRequest $request, CasteIssuingAuthority $casteIssuingAuthority): JsonResponse
    {
        $name = trim($request->validated('name'));
        $casteIssuingAuthority = DB::transaction(function () use ($casteIssuingAuthority, $name): CasteIssuingAuthority {
            $this->lockCasteIssuingAuthorityWrites();
            $record = CasteIssuingAuthority::query()->whereKey($casteIssuingAuthority->getKey())->lockForUpdate()->firstOrFail();
            $this->assertUniqueName($name, $record->getKey());
            $record->cia_name = $name;
            $record->cia_modifydate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $record->cia_modifyby = Auth::guard('staff')->id();
            $record->save();

            return $record;
        });

        return (new CasteIssuingAuthorityResource($casteIssuingAuthority))->additional([
            'message' => 'Caste issuing authority updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(CasteIssuingAuthority $casteIssuingAuthority): JsonResponse
    {
        return $this->setStatus($casteIssuingAuthority, 0, 'Caste issuing authority locked successfully.');
    }

    public function unlock(CasteIssuingAuthority $casteIssuingAuthority): JsonResponse
    {
        return $this->setStatus($casteIssuingAuthority, 1, 'Caste issuing authority unlocked successfully.');
    }

    private function setStatus(CasteIssuingAuthority $casteIssuingAuthority, int $status, string $message): JsonResponse
    {
        $casteIssuingAuthority->cia_status = $status;
        $casteIssuingAuthority->save();

        return (new CasteIssuingAuthorityResource($casteIssuingAuthority->refresh()))->additional([
            'message' => $message,
        ])->response()->header('Cache-Control', 'no-store');
    }

    private function lockCasteIssuingAuthorityWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE caste_issuing_auth_tab IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $query = CasteIssuingAuthority::query()->whereRaw('LOWER(TRIM(cia_name)) = LOWER(?)', [$name]);
        if ($exceptId !== null) {
            $query->where('cia_id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A caste issuing authority with this name already exists.',
            ]);
        }
    }
}
