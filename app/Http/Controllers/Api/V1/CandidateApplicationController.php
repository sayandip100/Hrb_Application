<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\CandidateApplicationService;
use App\Domain\Identity\CandidateApplicationSubmission;
use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\CandidateDocumentRequest;
use App\Http\Requests\SaveCandidateSectionRequest;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\CandidateUser;
use App\Rules\EncryptedEntityId;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CandidateApplicationController extends Controller
{
    public function __construct(private CandidateApplicationService $service) {}

    private function candidate(): CandidateUser
    {
        return Auth::guard('candidate')->user();
    }

    private function response(array $data, string $message = ''): JsonResponse
    {
        return response()->json(array_filter(['data' => $data, 'message' => $message], fn ($value) => $value !== ''))->header('Cache-Control', 'no-store');
    }

    public function show(): JsonResponse
    {
        $application = $this->service->application($this->candidate());

        return $this->response(['application_number' => $this->candidate()->f_application_no, 'version' => $application->version, 'state' => $application->state, 'details' => $application->payload]);
    }

    public function options(): JsonResponse
    {
        return $this->response($this->service->publicData($this->service->advertisement($this->candidate()), true));
    }

    public function save(SaveCandidateSectionRequest $request, string $section): JsonResponse
    {
        $application = $this->service->save($this->candidate(), $section, $request->validated());

        return $this->response(['version' => $application->version, 'state' => $application->state, 'details' => $application->payload], 'Application section saved successfully.');
    }

    public function preview(): JsonResponse
    {
        return $this->response($this->service->preview($this->candidate(), $this->service->application($this->candidate())));
    }

    public function submit(Request $request, CandidateApplicationSubmission $writer): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $application = $this->service->submit($this->candidate(), (int) $data['version'], $writer);

        return $this->response(['application_number' => $this->candidate()->f_application_no, 'version' => $application->version,
            'state' => $application->state, 'fee' => $application->fee, 'submitted_at' => $application->submitted_at?->toIso8601String(),
            'payment_gateway_available' => false], $application->state === 'complete' ? 'Application submitted successfully.' : 'Application submitted. Payment is pending; the gateway is not configured yet.');
    }

    public function status(): JsonResponse
    {
        $candidate = $this->candidate();
        $application = CandidateApplication::query()->where('candidate_uid', $candidate->f_uid)->first();
        if (! $application) {
            $legacy = DB::table('f_user_details')->where('fu_master', $candidate->f_uid)->first();

            return $this->response(['application_number' => $candidate->f_application_no, 'state' => 'legacy', 'submitted' => (int) ($legacy->fu_final_submit ?? 0) === 1,
                'payment_complete' => (int) ($legacy->fu_payment_stat ?? 0) === 1, 'can_edit' => false]);
        }

        return $this->response(['application_number' => $candidate->f_application_no, 'version' => $application->version, 'state' => $application->state,
            'fee' => $application->fee, 'submitted_at' => $application->submitted_at?->toIso8601String(), 'can_edit' => $application->state === 'draft' && CandidateApplicationService::isOpen($this->service->advertisement($candidate)),
            'payment_complete' => $application->state === 'complete', 'payment_gateway_available' => false]);
    }

    public function receipt(): JsonResponse
    {
        $application = $this->service->application($this->candidate());
        abort_unless($application->state === 'complete', 409, 'A completed application is required for a receipt.');

        return $this->response(['application_number' => $this->candidate()->f_application_no, 'name' => $this->candidate()->f_full_name,
            'advertisement_number' => $this->service->advertisement($this->candidate())->adv_no, 'submitted_at' => $application->submitted_at?->toIso8601String(),
            'fee' => $application->fee, 'details' => $application->payload]);
    }

    public function payments(): JsonResponse
    {
        $application = $this->service->application($this->candidate());
        abort_unless($application->state === 'payment_pending', 409, 'This application does not require a pending payment.');
        abort(503, 'Payment gateway integration has been deferred. No payment has been initiated.');
    }

    public function upload(CandidateDocumentRequest $request): JsonResponse
    {
        $path = null;
        try {
            $result = DB::transaction(function () use ($request, &$path): array {
                $candidate = $this->candidate();
                $application = $this->service->application($candidate, true);
                $this->service->editable($candidate, $application, $request->integer('version'));
                $path = $request->file('file')->store('candidate-documents/'.$candidate->f_uid, 'local');
                $document = new CandidateDocument;
                $document->candidate_uid = $candidate->f_uid;
                $document->purpose = $request->input('purpose');
                $document->path = $path;
                $document->mime = $request->file('file')->getMimeType();
                $document->size = $request->file('file')->getSize();
                $document->save();
                $application->version++;
                $application->save();

                return ['id' => PublicId::encode('candidate-document', $document->id), 'purpose' => $document->purpose, 'version' => $application->version];
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return $this->response($result, 'Document uploaded successfully.')->setStatusCode(201);
    }

    private function ownedDocument(string $document): CandidateDocument
    {
        try {
            $id = PublicId::decode('candidate-document', $document);
        } catch (DecryptException) {
            abort(404);
        }

        return CandidateDocument::query()->whereKey($id)->where('candidate_uid', $this->candidate()->f_uid)->firstOrFail();
    }

    public function download(string $document): BinaryFileResponse
    {
        $file = $this->ownedDocument($document);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return response()->download(Storage::disk('local')->path($file->path), $file->purpose.'.'.pathinfo($file->path, PATHINFO_EXTENSION), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroyDocument(Request $request, string $document): JsonResponse
    {
        $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $path = DB::transaction(function () use ($request, $document): string {
            $application = $this->service->application($this->candidate(), true);
            $this->service->editable($this->candidate(), $application, $request->integer('version'));
            $file = $this->ownedDocument($document);
            $path = $file->path;
            $file->delete();
            $application->version++;
            $application->save();

            return $path;
        });
        Storage::disk('local')->delete($path);

        return $this->response(['version' => $this->service->application($this->candidate())->version], 'Document deleted successfully.');
    }

    public function locations(Request $request): JsonResponse
    {
        $data = $request->validate(['state_id' => ['sometimes', 'string', new EncryptedEntityId('state')],
            'district_id' => ['sometimes', 'string', new EncryptedEntityId('district')],
            'subdivision_id' => ['sometimes', 'string', new EncryptedEntityId('subdivision')]]);
        $result = ['states' => DB::table('state_master')->where('state_status', 1)->orderBy('state_name')->get()->map(fn ($r) => ['id' => PublicId::encode('state', $r->state_id), 'name' => $r->state_name]),
            'districts' => [], 'subdivisions' => [], 'blocks_municipalities' => [], 'police_stations' => []];
        if (isset($data['state_id'])) {
            $result['districts'] = DB::table('district_master')->where('state_id', PublicId::decode('state', $data['state_id']))->where('district_status', 1)->orderBy('district_name')->get()->map(fn ($r) => ['id' => PublicId::encode('district', $r->district_id), 'name' => $r->district_name]);
        }
        if (isset($data['district_id'])) {
            $id = PublicId::decode('district', $data['district_id']);
            $result['subdivisions'] = DB::table('subdivision_tab')->where('subdiv_district', (string) $id)->where('subdiv_status', 1)->orderBy('subdiv_name')->get()->map(fn ($r) => ['id' => PublicId::encode('subdivision', $r->subdiv_id), 'name' => $r->subdiv_name]);
            $result['police_stations'] = DB::table('police_station_tab')->where('ps_dist_master', (string) $id)->where('ps_status', 1)->orderBy('ps_name')->get()->map(fn ($r) => ['id' => PublicId::encode('police-station', $r->ps_id), 'name' => $r->ps_name]);
        }
        if (isset($data['subdivision_id'])) {
            $result['blocks_municipalities'] = DB::table('block_master')->where('subd_id', PublicId::decode('subdivision', $data['subdivision_id']))->where('block_status', 1)->orderBy('block_name')->get()->map(fn ($r) => ['id' => PublicId::encode('block-municipality', $r->block_id), 'name' => $r->block_name, 'type' => $r->block_type]);
        }

        return $this->response($result);
    }
}
