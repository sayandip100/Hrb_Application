<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListDisciplinesRequest;
use App\Http\Requests\StoreDisciplineRequest;
use App\Http\Requests\UpdateDisciplineRequest;
use App\Http\Resources\DisciplineResource;
use App\Models\Discipline;
use App\Models\Recruitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;


class DisciplineController extends Controller
{
    public function index(ListDisciplinesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Discipline::query()->with('recruitment:rm_id,rm_name');
        if (isset($data['status'])) {
            $query->where('catm_status', (int) $data['status']);
        }
        if (isset($data['recruitment_id'])) {
            $query->where('catm_master', (int) $data['recruitment_id']);
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(catm_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return DisciplineResource::collection(
            $query->orderByDesc('catm_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }

    public function show(Discipline $discipline): JsonResponse
    {
        return (new DisciplineResource($discipline->load('recruitment:rm_id,rm_name')))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreDisciplineRequest $request): JsonResponse
    {
        $data = $request->validated();
        $discipline = DB::transaction(function () use ($data): Discipline {
            $recruitment = $this->activeRecruitment(isset($data['recruitment_id']) ? (int) $data['recruitment_id'] : null);
            $discipline = new Discipline;
            $discipline->catm_name = trim($data['name']);
            $discipline->catm_master = $recruitment?->getKey();
            $discipline->catm_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $discipline->catm_createby = Auth::guard('staff')->id();
            $discipline->catm_status = 1;
            $discipline->save();
            $discipline->setRelation('recruitment', $recruitment);

            return $discipline;
        });

        return (new DisciplineResource($discipline))->additional([
            'message' => 'Discipline added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdateDisciplineRequest $request, Discipline $discipline): JsonResponse
    {
        $data = $request->validated();
        $discipline = DB::transaction(function () use ($discipline, $data): Discipline {
            $record = Discipline::query()->whereKey($discipline->getKey())->lockForUpdate()->firstOrFail();
            if (array_key_exists('name', $data)) {
                $record->catm_name = trim($data['name']);
            }
            if (array_key_exists('recruitment_id', $data)) {
                $recruitmentId = $data['recruitment_id'] === null ? null : (int) $data['recruitment_id'];
                if ($recruitmentId !== $record->catm_master) {
                    $record->catm_master = $this->activeRecruitment($recruitmentId)?->getKey();
                }
            }
            $record->save();

            return $record->load('recruitment:rm_id,rm_name');
        });

        return (new DisciplineResource($discipline))->additional([
            'message' => 'Discipline updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(Discipline $discipline): JsonResponse
    {
        return $this->setStatus($discipline, 0, 'Discipline locked successfully.');
    }

    public function unlock(Discipline $discipline): JsonResponse
    {
        return $this->setStatus($discipline, 1, 'Discipline unlocked successfully.');
    }

    private function setStatus(Discipline $discipline, int $status, string $message): JsonResponse
    {
        $discipline->catm_status = $status;
        $discipline->save();

        return (new DisciplineResource($discipline->refresh()->load('recruitment:rm_id,rm_name')))->additional([
            'message' => $message,
        ])->response()->header('Cache-Control', 'no-store');
    }

    private function activeRecruitment(?int $id): ?Recruitment
    {
        if ($id === null) {
            return null;
        }
        $recruitment = Recruitment::query()->whereKey($id)->lockForUpdate()->first();
        if (! $recruitment || $recruitment->rm_status !== 1) {
            throw ValidationException::withMessages([
                'recruitment_id' => 'Select an existing, unlocked recruitment.',
            ]);
        }

        return $recruitment;
    }
}
