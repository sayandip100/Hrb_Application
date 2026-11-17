<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListStaffCandidateApplicationsRequest;
use App\Http\Requests\StaffCandidateOptionsRequest;
use App\Http\Resources\StaffCandidateApplicationResource;
use App\Models\Advertisement;
use App\Models\Recruitment;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StaffCandidateApplicationController extends Controller
{
    public function formOptions(StaffCandidateOptionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $advertisements = $posts = collect();
        if (isset($data['recruitment_id'])) {
            $advertisements = Advertisement::query()->where('adv_recruit_master', PublicId::decode('recruitment', $data['recruitment_id']))
                ->where('adv_status', 1)->orderBy('adv_no')->orderBy('adv_id')->get(['adv_id', 'adv_no', 'adv_auto_genno']);
            if (isset($data['advertisement_id'])) {
                $advertisement = $advertisements->firstWhere('adv_id', PublicId::decode('advertisement', $data['advertisement_id']));
                $posts = DB::table('advertisement_categoty as p')->join('category_master as c', 'c.catm_id', '=', 'p.acat_name')
                    ->where('p.acat_adv_master', $advertisement->adv_auto_genno)->orderBy('c.catm_name')->orderBy('p.acat_id')
                    ->get(['p.acat_id', 'c.catm_name', 'p.acat_gender_set', 'p.acat_marital_set']);
            }
        }

        return response()->json(['data' => [
            'recruitments' => Recruitment::query()->where('rm_status', 1)->orderBy('rm_name')->orderBy('rm_id')->get(['rm_id', 'rm_name'])
                ->map(fn ($r): array => ['id' => PublicId::encode('recruitment', $r->rm_id), 'name' => $r->rm_name]),
            'advertisements' => $advertisements->map(fn ($r): array => ['id' => PublicId::encode('advertisement', $r->adv_id), 'reference' => $r->adv_auto_genno, 'advertisement_number' => $r->adv_no]),
            'posts' => $posts->map(fn ($r): array => ['id' => PublicId::encode('advertisement-post', $r->acat_id), 'name' => $r->catm_name, 'gender' => $r->acat_gender_set, 'marital_status' => $r->acat_marital_set]),
        ]])->header('Cache-Control', 'no-store');
    }

    public function index(ListStaffCandidateApplicationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $reference = Advertisement::query()->whereKey(PublicId::decode('advertisement', $data['advertisement_id']))->value('adv_auto_genno');
        $query = DB::table('f_user_views')->select('f_uid', 'f_application_no', 'f_full_name', 'f_mobile', 'f_email')
            ->where('f_applied_for', $reference)->where('fu_category', PublicId::decode('advertisement-post', $data['post_id']))
            ->where('fu_cancel_stat', 0);
        foreach (['fu_step_1', 'fu_step_2', 'fu_step_3', 'fu_step_4', 'fu_final_submit', 'fu_payment_stat'] as $column) {
            $query->where($column, 1);
        }
        if (filled($data['search'] ?? null)) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                foreach (['f_application_no', 'f_full_name', 'f_mobile', 'f_email'] as $column) {
                    $query->orWhereRaw('LOWER('.$column.") LIKE LOWER(?) ESCAPE '!'", [$search]);
                }
            });
        }
        $sort = ['name' => 'f_full_name', 'application_number' => 'f_application_no', 'mobile' => 'f_mobile', 'email' => 'f_email'][$data['sort_by'] ?? 'name'];

        return StaffCandidateApplicationResource::collection(
            $query->orderBy($sort, $data['sort_direction'] ?? 'asc')->orderBy('f_application_no')->orderBy('f_uid')
                ->paginate((int) ($data['per_page'] ?? 25))->withQueryString(),
        )->response()->header('Cache-Control', 'no-store');
    }
}
