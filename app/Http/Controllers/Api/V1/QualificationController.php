<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListQualificationsRequest;
use App\Http\Requests\StoreQualificationRequest;
use App\Http\Requests\UpdateQualificationRequest;
use App\Http\Resources\QualificationResource;
use App\Models\Qualification;
use App\Models\Recruitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationController extends Controller
{
    public function index(ListQualificationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Qualification::query()->with('recruitment:rm_id,rm_name');
        if (isset($data['status'])) {
            $query->where('qm_status', (int) $data['status']);
        }
        if (isset($data['recruitment_id'])) {
            $query->where('qm_r_master', PublicId::decode('recruitment', $data['recruitment_id']));
        }
        if (filled($data['search'] ?? null)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']);
            $query->whereRaw("LOWER(qm_name) LIKE LOWER(?) ESCAPE '!'", ['%'.$search.'%']);
        }

        return QualificationResource::collection(
            $query->orderByDesc('qm_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }

    public function show(Qualification $qualification): JsonResponse
    {
        return (new QualificationResource($qualification->load('recruitment:rm_id,rm_name')))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreQualificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $qualification = DB::transaction(function () use ($data): Qualification {
            $this->lockQualificationWrites();
            $this->assertUniqueName(trim($data['name']));
            $recruitment = $this->activeRecruitment(isset($data['recruitment_id']) ? PublicId::decode('recruitment', $data['recruitment_id']) : null);
            $qualification = new Qualification;
            $qualification->qm_name = trim($data['name']);
            $qualification->qm_r_master = $recruitment?->getKey();
            $qualification->qm_createdate = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $qualification->qm_createby = Auth::guard('staff')->id();
            $qualification->qm_status = 1;
            $qualification->save();
            $qualification->setRelation('recruitment', $recruitment);

            return $qualification;
        });

        return (new QualificationResource($qualification))->additional([
            'message' => 'Qualification added successfully.',
        ])->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdateQualificationRequest $request, Qualification $qualification): JsonResponse
    {
        $data = $request->validated();
        $qualification = DB::transaction(function () use ($qualification, $data): Qualification {
            $this->lockQualificationWrites();
            $record = Qualification::query()->whereKey($qualification->getKey())->lockForUpdate()->firstOrFail();
            if (array_key_exists('name', $data)) {
                $this->assertUniqueName(trim($data['name']), $record->getKey());
                $record->qm_name = trim($data['name']);
            }
            if (array_key_exists('recruitment_id', $data)) {
                $recruitmentId = $data['recruitment_id'] === null ? null : PublicId::decode('recruitment', $data['recruitment_id']);
                if ($recruitmentId !== $record->qm_r_master) {
                    $record->qm_r_master = $this->activeRecruitment($recruitmentId)?->getKey();
                }
            }
            $record->save();

            return $record->load('recruitment:rm_id,rm_name');
        });

        return (new QualificationResource($qualification))->additional([
            'message' => 'Qualification updated successfully.',
        ])->response()->header('Cache-Control', 'no-store');
    }

    public function lock(Qualification $qualification): JsonResponse
    {
        return $this->setStatus($qualification, 0, 'Qualification locked successfully.');
    }

    public function unlock(Qualification $qualification): JsonResponse
    {
        return $this->setStatus($qualification, 1, 'Qualification unlocked successfully.');
    }

    private function setStatus(Qualification $qualification, int $status, string $message): JsonResponse
    {
        $qualification->qm_status = $status;
        $qualification->save();

        return (new QualificationResource($qualification->refresh()->load('recruitment:rm_id,rm_name')))->additional([
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

    private function lockQualificationWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE qualification_master IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $query = Qualification::query()->whereRaw('LOWER(TRIM(qm_name)) = LOWER(?)', [$name]);
        if ($exceptId !== null) {
            $query->where('qm_id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A qualification with this name already exists.',
            ]);
        }
    }
}
