<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListRecruitmentsRequest;
use App\Http\Requests\StoreRecruitmentRequest;
use App\Http\Resources\RecruitmentResource;
use App\Models\Recruitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecruitmentController extends Controller
{
    public function index(ListRecruitmentsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Recruitment::query();
        if (isset($data['status'])) {
            $query->where('rm_status', (int) $data['status']);
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(rm_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return RecruitmentResource::collection(
            $query->orderBy('rm_name')->orderBy('rm_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreRecruitmentRequest $request): JsonResponse
    {
        $name = trim($request->validated('name'));
        $recruitment = DB::transaction(function () use ($name): Recruitment {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement('LOCK TABLE recruitment_master_tab IN SHARE ROW EXCLUSIVE MODE');
            }
            if (Recruitment::query()->whereRaw('LOWER(TRIM(rm_name)) = LOWER(?)', [$name])->exists()) {
                throw ValidationException::withMessages(['name' => 'A recruitment with this name already exists.']);
            }

            $recruitment = new Recruitment;
            $recruitment->rm_name = $name;
            $recruitment->rm_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $recruitment->rm_createby = Auth::guard('staff')->id();
            $recruitment->rm_status = 1;
            $recruitment->save();

            return $recruitment;
        });

        return (new RecruitmentResource($recruitment))->additional([
            'message' => 'Recruitment added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function lock(Recruitment $recruitment): JsonResponse
    {
        return $this->setStatus($recruitment, 0, 'Recruitment locked successfully.');
    }

    public function unlock(Recruitment $recruitment): JsonResponse
    {
        return $this->setStatus($recruitment, 1, 'Recruitment unlocked successfully.');
    }

    private function setStatus(Recruitment $recruitment, int $status, string $message): JsonResponse
    {
        $recruitment->rm_status = $status;
        $recruitment->save();

        return (new RecruitmentResource($recruitment))->additional([
            'message' => $message,
        ])->response()->header('Cache-Control', 'no-store');
    }
}
