<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListExperiencesRequest;
use App\Http\Requests\StoreExperienceRequest;
use App\Http\Resources\ExperienceResource;
use App\Models\Experience;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExperienceController extends Controller
{
    public function index(ListExperiencesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Experience::query();
        if (isset($data['status'])) {
            $query->where('expset_status', (int) $data['status']);
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(expset_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return ExperienceResource::collection(
            $query->orderByDesc('expset_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }

    public function show(Experience $experience): JsonResponse
    {
        return (new ExperienceResource($experience))->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreExperienceRequest $request): JsonResponse
    {
        $name = trim($request->validated('name'));
        $experience = DB::transaction(function () use ($name): Experience {
            $this->lockExperienceWrites();
            $this->assertUniqueName($name);
            $experience = new Experience;
            $experience->expset_name = $name;
            $experience->expset_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $experience->expset_createby = Auth::guard('staff')->id();
            $experience->expset_status = 1;
            $experience->expset_modifydate = null;
            $experience->expset_modifyby = null;
            $experience->save();

            return $experience;
        });

        return (new ExperienceResource($experience))->additional([
            'message' => 'Experience added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(StoreExperienceRequest $request, Experience $experience): JsonResponse
    {
        $name = trim($request->validated('name'));
        $experience = DB::transaction(function () use ($experience, $name): Experience {
            $this->lockExperienceWrites();
            $record = Experience::query()->whereKey($experience->getKey())->lockForUpdate()->firstOrFail();
            $this->assertUniqueName($name, $record->getKey());
            $record->expset_name = $name;
            $record->expset_modifydate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $record->expset_modifyby = Auth::guard('staff')->id();
            $record->save();

            return $record;
        });

        return (new ExperienceResource($experience))->additional([
            'message' => 'Experience updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(Experience $experience): JsonResponse
    {
        return $this->setStatus($experience, 0, 'Experience locked successfully.');
    }

    public function unlock(Experience $experience): JsonResponse
    {
        return $this->setStatus($experience, 1, 'Experience unlocked successfully.');
    }

    private function setStatus(Experience $experience, int $status, string $message): JsonResponse
    {
        $experience->expset_status = $status;
        $experience->save();

        return (new ExperienceResource($experience->refresh()))->additional([
            'message' => $message,
        ])->response()->header('Cache-Control', 'no-store');
    }

    private function lockExperienceWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE experience_master_tab IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $query = Experience::query()->whereRaw('LOWER(TRIM(expset_name)) = LOWER(?)', [$name]);
        if ($exceptId !== null) {
            $query->where('expset_id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'An experience with this name already exists.',
            ]);
        }
    }
}
