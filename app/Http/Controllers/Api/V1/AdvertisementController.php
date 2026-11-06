<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\AdvertisementDraftService;
use App\Domain\Identity\AdvertisementPayload;
use App\Domain\Identity\AdvertisementSubmission;
use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAdvertisementRequest;
use App\Http\Resources\AdvertisementDraftResource;
use App\Models\AdvertisementDraft;
use App\Models\AgeRelaxation;
use App\Models\Discipline;
use App\Models\Experience;
use App\Models\Qualification;
use App\Models\Recruitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvertisementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['sometimes', Rule::in(['draft', 'submitted'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AdvertisementDraft::query();
        if (isset($data['state'])) {
            $query->where('state', $data['state']);
        }

        return AdvertisementDraftResource::collection($query->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 25)->withQueryString())->response()->header('Cache-Control', 'no-store');
    }

    public function store(SaveAdvertisementRequest $request, AdvertisementDraftService $service): JsonResponse
    {
        return $this->respond($service->create($request->validated(), (int) Auth::guard('staff')->id()),
            'Advertisement draft created successfully.', 201);
    }

    public function show(AdvertisementDraft $advertisementDraft): JsonResponse
    {
        return (new AdvertisementDraftResource($advertisementDraft))->response()->header('Cache-Control', 'no-store');
    }

    public function update(SaveAdvertisementRequest $request, AdvertisementDraft $advertisementDraft, AdvertisementDraftService $service): JsonResponse
    {
        $data = $request->validated();
        $version = (int) $data['version'];
        unset($data['version']);

        return $this->respond($service->update($advertisementDraft, $data, $version, (int) Auth::guard('staff')->id()),
            'Advertisement draft saved successfully.');
    }

    public function document(Request $request, AdvertisementDraft $advertisementDraft, AdvertisementDraftService $service): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:11000'],
        ]);

        return $this->respond($service->upload($advertisementDraft, $request->file('document'), (int) $data['version'],
            (int) Auth::guard('staff')->id()), 'Advertisement document saved successfully.');
    }

    public function downloadDocument(AdvertisementDraft $advertisementDraft): StreamedResponse
    {
        abort_if($advertisementDraft->document_path === null || ! Storage::disk('local')->exists($advertisementDraft->document_path), 404);
        $extension = pathinfo($advertisementDraft->document_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($advertisementDraft->document_path, 'advertisement.'.$extension,
            ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function submit(Request $request, AdvertisementDraft $advertisementDraft, AdvertisementDraftService $service, AdvertisementSubmission $submission): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->respond($service->submit($advertisementDraft, (int) $data['version'],
            (int) Auth::guard('staff')->id(), $submission), 'Advertisement submitted successfully. It is not yet activated.');
    }

    public function formOptions(): JsonResponse
    {
        return response()->json(['data' => [
            'recruitments' => Recruitment::query()->where('rm_status', 1)->orderBy('rm_name')->orderBy('rm_id')->get()
                ->map(fn (Recruitment $row): array => ['id' => PublicId::encode('recruitment', $row->rm_id), 'name' => $row->rm_name]),
            'experiences' => Experience::query()->where('expset_status', 1)->orderBy('expset_name')->orderBy('expset_id')->get()
                ->map(fn (Experience $row): array => ['id' => PublicId::encode('experience', $row->expset_id), 'name' => $row->expset_name]),
            'age_fee_sections' => AgeRelaxation::query()->where('caste_status', 1)->orderBy('caste_cat')->orderBy('caste_id')->get()
                ->map(fn (AgeRelaxation $row): array => ['id' => PublicId::encode('age-relaxation', $row->caste_id), 'name' => $row->caste_name, 'category' => $row->caste_cat]),
            'vacancy_categories' => AdvertisementPayload::VACANCIES,
            'relationships' => ['AND', 'OR', 'END'],
        ]])->header('Cache-Control', 'no-store');
    }

    public function recruitmentOptions(Recruitment $recruitment): JsonResponse
    {
        abort_unless($recruitment->rm_status === 1, 422, 'Select an active recruitment.');
        $disciplines = Discipline::query()->where('catm_status', 1)->where('catm_master', $recruitment->rm_id)->orderBy('catm_name')->orderBy('catm_id')->get();
        if ($disciplines->isEmpty()) {
            $disciplines = Discipline::query()->where('catm_status', 1)->whereNull('catm_master')->orderBy('catm_name')->orderBy('catm_id')->get();
        }
        $qualifications = Qualification::query()->where('qm_status', 1)->where(function ($query) use ($recruitment): void {
            $query->where('qm_r_master', $recruitment->rm_id)->orWhereNull('qm_r_master');
        })->orderBy('qm_name')->orderBy('qm_id')->get();

        return response()->json(['data' => [
            'disciplines' => $disciplines->map(fn (Discipline $row): array => ['id' => PublicId::encode('discipline', $row->catm_id), 'name' => $row->catm_name]),
            'qualifications' => $qualifications->map(fn (Qualification $row): array => ['id' => PublicId::encode('qualification', $row->qm_id), 'name' => $row->qm_name]),
        ]])->header('Cache-Control', 'no-store');
    }

    private function respond(AdvertisementDraft $draft, string $message, int $status = 200): JsonResponse
    {
        return (new AdvertisementDraftResource($draft))->additional(['message' => $message])->response()
            ->setStatusCode($status)->header('Cache-Control', 'no-store');
    }
}
