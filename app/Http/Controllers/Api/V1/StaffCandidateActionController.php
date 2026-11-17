<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PublicId;
use App\Domain\Identity\StaffCandidateActionService;
use App\Domain\Identity\StaffCandidateDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffCandidateActionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class StaffCandidateActionController extends Controller
{
    public function __construct(private StaffCandidateActionService $service) {}

    private function response(array $data, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter(['data' => $data, 'message' => $message], fn ($v) => $v !== null))->header('Cache-Control', 'no-store');
    }

    public function show(string $candidate): JsonResponse
    {
        return $this->response($this->service->show($this->service->resolve($candidate)));
    }

    public function document(string $candidate, string $document, StaffCandidateDocumentService $documents): BinaryFileResponse
    {
        $c = $this->service->resolve($candidate);

        return $documents->download($c, $this->service->details($c), $document);
    }

    public function checks(Request $request, string $candidate): JsonResponse
    {
        $data = $request->validate(['section' => ['required_with:subtype_id', Rule::in(StaffCandidateActionService::CHECKS)],
            'subtype_id' => ['sometimes', 'string', 'max:1024']]);
        if (isset($data['subtype_id']) && ! in_array($data['section'] ?? null, ['fu_age_relax', 'fu_es_qualification', 'fu_ds_qualification', 'fu_has_es_service', 'fu_has_ds_service'], true)) {
            abort(422, 'Select a section with subtypes.');
        }
        $c = $this->service->resolve($candidate);
        $r = $this->service->result($c);

        return $this->response(['review_status' => $r->cr_approval, 'rejection_reason' => $r->cr_reject_comments,
            'checks' => $this->service->checks($c, $data['section'] ?? null, $data['subtype_id'] ?? null)]);
    }

    public function sections(Request $request, string $candidate): JsonResponse
    {
        $c = $this->service->resolve($candidate);
        $result = $this->service->show($c);
        $result['available_sections'] = StaffCandidateActionService::CHECKS;
        $result['checking'] = $this->checks($request, $candidate)->getData(true)['data'];
        $result['requirement_options'] = $this->service->swapOptions($c)['options'];

        return $this->response($result);
    }

    public function history(Request $request, string $candidate): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $c = $this->service->resolve($candidate);
        $rows = $this->service->history($c, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));
        $rows->through(function ($r): array {
            $type = $r->chklog_type === 'fu_age_relax' ? 'age-relaxation' : (in_array($r->chklog_type, ['fu_es_qualification', 'fu_ds_qualification'], true) ? 'qualification' : 'experience');

            return ['id' => PublicId::encode('candidate-check-log', $r->chklog_id), 'section' => $r->chklog_type,
                'subtype_id' => PublicId::encode($type, $r->chklog_type_id), 'staff_id' => PublicId::encode('staff', $r->chklog_user),
                'status' => $r->chklog_approval, 'comments' => $r->chklog_msg, 'created_at' => $r->chklog_createdate];
        });

        return response()->json($rows)->header('Cache-Control', 'no-store');
    }

    public function marks(string $candidate): JsonResponse
    {
        return $this->response($this->service->marks($this->service->resolve($candidate)));
    }

    public function swapOptions(string $candidate): JsonResponse
    {
        return $this->response($this->service->swapOptions($this->service->resolve($candidate)));
    }

    public function update(StaffCandidateActionRequest $request, string $candidate): JsonResponse
    {
        $action = match (true) {
            $request->routeIs('candidates.applications.profile') => 'profile',
            $request->routeIs('candidates.applications.dob') => 'dob',
            $request->routeIs('candidates.applications.reject') => 'reject',
            default => 'swap',
        };
        $this->service->resolve($candidate);
        $data = $request->validated();
        unset($data['evidence']);
        $path = null;
        try {
            if ($request->hasFile('evidence')) {
                $path = $request->file('evidence')->store('candidate-admin', 'local');
                abort_unless(is_string($path), 503, 'Evidence could not be stored.');
            }
            $c = $this->service->mutate($candidate, $action, $data, (int) Auth::guard('staff')->id(), $path);
        } catch (Throwable $e) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return $this->response($this->service->show($c), match ($action) {
            'profile' => 'Candidate profile updated successfully.','dob' => 'Candidate date of birth updated successfully.',
            'reject' => 'Candidate application rejected successfully.',default => 'Candidate requirements swapped successfully.',
        });
    }

    public function audit(Request $request, string $candidate): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $c = $this->service->resolve($candidate);
        $rows = DB::table('candidate_admin_events')->where('candidate_uid', $c->f_uid)->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25), ['id', 'staff_id', 'action', 'reason', 'created_at'], 'page', (int) ($data['page'] ?? 1));
        $rows->through(fn ($r): array => ['id' => PublicId::encode('candidate-admin-event', $r->id), 'staff_id' => PublicId::encode('staff', $r->staff_id), 'action' => $r->action, 'reason' => $r->reason, 'created_at' => $r->created_at]);

        return response()->json($rows)->header('Cache-Control', 'no-store');
    }
}
