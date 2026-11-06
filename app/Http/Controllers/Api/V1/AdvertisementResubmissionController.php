<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\AdvertisementResubmissionService;
use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAdvertisementResubmissionsRequest;
use App\Http\Requests\PreviewAdvertisementResubmissionRequest;
use App\Http\Requests\ResubmissionOptionsRequest;
use App\Http\Requests\StoreAdvertisementResubmissionRequest;
use App\Http\Resources\AdvertisementResubmissionResource;
use App\Models\Advertisement;
use App\Models\AdvertisementResubmission;
use App\Models\Recruitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvertisementResubmissionController extends Controller
{
    public function __construct(private AdvertisementResubmissionService $service) {}

    public function formOptions(ResubmissionOptionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $recruitments = Recruitment::query()->where('rm_status', 1)->orderBy('rm_name')->get(['rm_id', 'rm_name']);
        $advertisements = $posts = collect();
        if (isset($data['recruitment_id'])) {
            $recruitment = Recruitment::query()->whereKey(PublicId::decode('recruitment', $data['recruitment_id']))->where('rm_status', 1)->first();
            if (! $recruitment) {
                throw ValidationException::withMessages(['recruitment_id' => 'Select an active recruitment.']);
            }
            $advertisements = Advertisement::query()->where('adv_recruit_master', $recruitment->rm_id)->where('adv_status', 1)
                ->orderBy('adv_no')->get(['adv_id', 'adv_auto_genno', 'adv_no']);
            if (isset($data['advertisement_id'])) {
                $advertisement = $advertisements->firstWhere('adv_id', PublicId::decode('advertisement', $data['advertisement_id']));
                if (! $advertisement) {
                    throw ValidationException::withMessages(['advertisement_id' => 'Select an unlocked advertisement belonging to this recruitment.']);
                }
                $posts = DB::table('advertisement_categoty as p')->join('category_master as c', 'c.catm_id', '=', 'p.acat_name')
                    ->where('p.acat_adv_master', $advertisement->adv_auto_genno)->orderBy('c.catm_name')->orderBy('p.acat_id')
                    ->get(['p.acat_id', 'p.acat_gender_set', 'p.acat_marital_set', 'c.catm_name']);
            }
        }

        return response()->json(['data' => [
            'recruitments' => $recruitments->map(fn ($row): array => ['id' => PublicId::encode('recruitment', $row->rm_id), 'name' => $row->rm_name]),
            'advertisements' => $advertisements->map(fn ($row): array => ['id' => PublicId::encode('advertisement', $row->adv_id), 'reference' => $row->adv_auto_genno, 'advertisement_number' => $row->adv_no]),
            'posts' => $posts->map(fn ($row): array => ['id' => PublicId::encode('advertisement-post', $row->acat_id), 'name' => $row->catm_name, 'gender' => $row->acat_gender_set, 'marital_status' => $row->acat_marital_set]),
        ]])->header('Cache-Control', 'no-store');
    }

    public function preview(PreviewAdvertisementResubmissionRequest $request): JsonResponse
    {
        [$advertisement, $post] = $this->service->selection($request->validated());
        $query = $this->service->eligible($advertisement->adv_auto_genno, $post->acat_id);

        return response()->json(['data' => ['eligible_candidates' => $query->distinct()->count('fv.f_uid')]])->header('Cache-Control', 'no-store');
    }

    public function index(ListAdvertisementResubmissionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = $this->service->windows();
        if (isset($data['advertisement_id'])) {
            $advertisement = Advertisement::query()->find(PublicId::decode('advertisement', $data['advertisement_id']));
            if (! $advertisement) {
                throw ValidationException::withMessages(['advertisement_id' => 'The selected advertisement does not exist.']);
            }
            $query->where('adv_resub_master', $advertisement->adv_auto_genno);
        }
        if (isset($data['post_id'])) {
            $query->where('adv_resub_cat', PublicId::decode('advertisement-post', $data['post_id']));
        }

        return AdvertisementResubmissionResource::collection($query->orderByDesc('adv_resub_id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString())
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreAdvertisementResubmissionRequest $request): JsonResponse
    {
        return (new AdvertisementResubmissionResource($this->service->create($request->validated(), Auth::guard('staff')->id())))
            ->additional(['message' => 'Resubmission window created successfully. Candidate notification emails are pending delivery.'])
            ->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function show(AdvertisementResubmission $resubmission): JsonResponse
    {
        return (new AdvertisementResubmissionResource($this->service->windows()->whereKey($resubmission->getKey())->firstOrFail()))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function process(AdvertisementResubmission $resubmission): JsonResponse
    {
        return (new AdvertisementResubmissionResource($this->service->process($resubmission, Auth::guard('staff')->id())))
            ->additional(['message' => 'Resubmission window processed successfully. Corrected eligible applications are ready for rechecking.'])
            ->response()->header('Cache-Control', 'no-store');
    }
}
