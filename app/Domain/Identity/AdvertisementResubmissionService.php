<?php

namespace App\Domain\Identity;

use App\Models\Advertisement;
use App\Models\AdvertisementResubmission;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvertisementResubmissionService
{
    public function windows(): EloquentBuilder
    {
        return AdvertisementResubmission::query()->select('advertisement_resubmit_tab.*',
            'b.eligible_candidates', 'b.processed_candidates', 'b.deleted_checks', 'cm.catm_name as post_name')
            ->leftJoin('advertisement_resubmission_batches as b', 'b.resubmission_id', '=', 'adv_resub_id')
            ->leftJoin('advertisement_categoty as p', 'p.acat_id', '=', 'adv_resub_cat')
            ->leftJoin('category_master as cm', 'cm.catm_id', '=', 'p.acat_name')
            ->with('advertisement:adv_id,adv_auto_genno,adv_no')
            ->withCount(collect(['pending', 'sent', 'failed', 'invalid', 'skipped'])->mapWithKeys(fn (string $status): array => [
                'candidates as notifications_'.$status => fn (EloquentBuilder $query) => $query->where('notification_status', $status),
            ])->all());
    }

    /** @return array{0: Advertisement, 1: object} */
    public function selection(array $data, bool $lock = false): array
    {
        $query = Advertisement::query()->whereKey(PublicId::decode('advertisement', $data['advertisement_id']));
        $advertisement = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $advertisement || $advertisement->adv_status !== 1) {
            throw ValidationException::withMessages(['advertisement_id' => 'Select an existing unlocked advertisement.']);
        }
        $post = DB::table('advertisement_categoty as p')->join('category_master as c', 'c.catm_id', '=', 'p.acat_name')
            ->where('p.acat_id', PublicId::decode('advertisement-post', $data['post_id']))
            ->where('p.acat_adv_master', $advertisement->adv_auto_genno)
            ->select('p.acat_id', 'c.catm_name')->first();
        if (! $post) {
            throw ValidationException::withMessages(['post_id' => 'The selected post does not belong to this advertisement.']);
        }

        return [$advertisement, $post];
    }

    public function eligible(string $reference, int $postId): Builder
    {
        $query = DB::table('f_user_views as fv')->where('fv.f_applied_for', $reference)->where('fv.fu_category', $postId)
            ->where('fv.f_registered', 1)->where('fv.f_status', 1)->where('fv.fu_cancel_stat', 0);
        foreach (['fu_step_1', 'fu_step_2', 'fu_step_3', 'fu_step_4', 'fu_final_submit', 'fu_payment_stat'] as $column) {
            $query->where('fv.'.$column, 1);
        }

        return $query->whereExists(function (Builder $query): void {
            $query->selectRaw('1')->from('candidate_result_tab as cr')
                ->whereColumn('cr.cr_application_master', 'fv.f_application_no')->whereIn('cr.cr_approval', ['Approved', 'Rejected']);
        })->whereExists(function (Builder $query): void {
            $query->selectRaw('1')->from('checking_tab as ch')->whereColumn('ch.chk_user_application', 'fv.f_application_no')
                ->where('ch.chk2_approve', 'Rejected')->where('ch.chk_final_state', 'Rejected');
        });
    }

    public function create(array $data, int $staffId): AdvertisementResubmission
    {
        $id = DB::transaction(function () use ($data, $staffId): int {
            [$advertisement, $post] = $this->selection($data, true);
            $now = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            if ($data['ends_at'] <= $now) {
                throw ValidationException::withMessages(['ends_at' => 'The resubmission end time must be in the future (Asia/Kolkata).']);
            }
            $overlap = AdvertisementResubmission::query()->where('adv_resub_master', $advertisement->adv_auto_genno)
                ->where('adv_resub_cat', $post->acat_id)->where('adv_resub_status', 1)->where('adv_resub_chk_stat', 0)
                ->where('adv_resub_starttime', '<=', $data['ends_at'])->where('adv_resub_endtime', '>=', $data['starts_at'])->exists();
            abort_if($overlap, 409, 'An overlapping resubmission window already exists for this advertisement/post.');
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE frontend_users, f_user_details, candidate_result_tab, checking_tab, f_user_extraage, f_user_qualification, f_user_des_qualification, f_user_ess_experience, f_user_experience IN SHARE MODE');
            }
            $window = new AdvertisementResubmission;
            $window->adv_resub_master = $advertisement->adv_auto_genno;
            $window->adv_resub_cat = $post->acat_id;
            $window->adv_resub_starttime = $data['starts_at'];
            $window->adv_resub_endtime = $data['ends_at'];
            $window->adv_resub_reasons = $data['reason'] ?? null;
            $window->adv_resub_createdate = $now;
            $window->adv_resub_createby = $staffId;
            $window->adv_resub_status = 1;
            $window->adv_resub_chk_stat = 0;
            $window->save();
            DB::table('advertisement_resubmission_batches')->insert([
                'resubmission_id' => $window->getKey(), 'eligible_candidates' => 0, 'created_at' => $now,
            ]);
            $total = 0;
            $this->eligible($advertisement->adv_auto_genno, $post->acat_id)
                ->select('fv.f_uid', 'fv.f_application_no', 'fv.f_email')->distinct()
                ->chunkById(100, function ($candidates) use ($window, $now, &$total): void {
                    $snapshots = $this->snapshots($candidates->all());
                    $rows = [];
                    foreach ($candidates as $candidate) {
                        $rows[] = [
                            'resubmission_id' => $window->getKey(), 'candidate_uid' => $candidate->f_uid,
                            'application_no' => $candidate->f_application_no, 'email' => $candidate->f_email,
                            'snapshot' => json_encode($snapshots[$candidate->f_uid], JSON_THROW_ON_ERROR),
                            'notification_status' => filter_var($candidate->f_email, FILTER_VALIDATE_EMAIL) !== false ? 'pending' : 'invalid',
                            'attempts' => 0, 'created_at' => $now,
                        ];
                    }
                    DB::table('advertisement_resubmission_candidates')->insert($rows);
                    $total += count($rows);
                }, 'fv.f_uid', 'f_uid');
            if ($total === 0) {
                throw ValidationException::withMessages(['post_id' => 'No eligible candidates with rejected sections were found.']);
            }
            DB::table('advertisement_resubmission_batches')->where('resubmission_id', $window->getKey())->update(['eligible_candidates' => $total]);

            return $window->getKey();
        }, 3);

        return $this->windows()->whereKey($id)->firstOrFail();
    }

    /** Snapshot only this window's candidates, without copying authentication secrets. */
    private function snapshots(array $candidates): array
    {
        $uids = array_column($candidates, 'f_uid');
        $applications = array_column($candidates, 'f_application_no');
        $sets = [
            'frontend_users' => DB::table('frontend_users')->whereIn('f_uid', $uids)->select('f_uid', 'f_applied_for', 'f_application_no', 'f_full_name', 'f_email', 'f_registered', 'f_status', 'f_createdate', 'f_modifydate')->get()->groupBy('f_uid'),
            'candidate_result_tab' => DB::table('candidate_result_tab')->whereIn('cr_application_master', $applications)->get()->groupBy('cr_application_master'),
            'checking_tab' => DB::table('checking_tab')->whereIn('chk_user_application', $applications)->get()->groupBy('chk_user_application'),
        ];
        $userTables = ['f_user_details' => 'fu_master', 'f_user_extraage' => 'fu_ext_masteruser',
            'f_user_qualification' => 'fu_quali_masteruser', 'f_user_des_qualification' => 'fud_quali_masteruser',
            'f_user_ess_experience' => 'fues_exp_masteruser', 'f_user_experience' => 'fu_exp_masteruser'];
        foreach ($userTables as $table => $key) {
            $sets[$table] = DB::table($table)->whereIn($key, $uids)->get()->groupBy($key);
        }
        $snapshots = [];
        foreach ($candidates as $candidate) {
            foreach ($sets as $table => $rows) {
                $key = in_array($table, ['candidate_result_tab', 'checking_tab'], true) ? $candidate->f_application_no : $candidate->f_uid;
                $snapshots[$candidate->f_uid][$table] = $rows->get($key, collect())->all();
            }
        }

        return $snapshots;
    }

    public function process(AdvertisementResubmission $resubmission, int $staffId): AdvertisementResubmission
    {
        DB::transaction(function () use ($resubmission, $staffId): void {
            Advertisement::query()->where('adv_auto_genno', $resubmission->adv_resub_master)->lockForUpdate()->firstOrFail();
            $window = AdvertisementResubmission::query()->whereKey($resubmission->getKey())->lockForUpdate()->firstOrFail();
            $batch = DB::table('advertisement_resubmission_batches')->where('resubmission_id', $window->getKey())->first();
            abort_unless($batch, 409, 'This legacy window has no candidate snapshot and cannot be processed by this API.');
            if ($window->adv_resub_chk_stat === 1) {
                return;
            }
            abort_unless($window->adv_resub_status === 1, 409, 'This resubmission window is disabled.');
            $now = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            abort_if($now <= $window->adv_resub_endtime->format('Y-m-d H:i:s'), 409, 'Process the resubmission window only after its end time.');
            $processed = $deleted = 0;
            $window->candidates()->whereExists(function (Builder $query) use ($window): void {
                $query->selectRaw('1')->from('f_resubmit_log as l')
                    ->whereColumn('l.fresub_cand_regno', 'advertisement_resubmission_candidates.application_no')
                    ->where('l.fresub_cand_advno', $window->adv_resub_master)
                    ->whereBetween('l.fresub_createdate', [$window->adv_resub_starttime->format('Y-m-d H:i:s'), $window->adv_resub_endtime->format('Y-m-d H:i:s')])
                    ->where('l.fresub_createdate', '>=', $window->adv_resub_createdate->format('Y-m-d H:i:s'));
            })->chunkById(100, function ($candidates) use ($window, &$processed, &$deleted): void {
                $current = $this->eligible($window->adv_resub_master, $window->adv_resub_cat)
                    ->whereIn('fv.f_uid', $candidates->pluck('candidate_uid'))->pluck('fv.f_application_no', 'fv.f_uid');
                foreach ($candidates as $candidate) {
                    if ($current->get($candidate->candidate_uid) !== $candidate->application_no) {
                        continue;
                    }
                    $result = DB::table('candidate_result_tab')->where('cr_application_master', $candidate->application_no)
                        ->whereIn('cr_approval', ['Approved', 'Rejected'])->lockForUpdate()->first();
                    if (! $result) {
                        continue;
                    }
                    $ids = collect($candidate->snapshot['checking_tab'] ?? [])->pluck('chk_id')->all();
                    $checks = DB::table('checking_tab')->whereIn('chk_id', $ids)
                        ->where('chk_user_application', $candidate->application_no)
                        ->where('chk2_approve', 'Rejected')->where('chk_final_state', 'Rejected');
                    $lockedIds = (clone $checks)->lockForUpdate()->pluck('chk_id')->all();
                    if ($lockedIds === []) {
                        continue;
                    }
                    $deleted += DB::table('checking_tab')->whereIn('chk_id', $lockedIds)->delete();
                    $updates = ['cr_approval' => 'NotChecked', 'cr_reject_comments' => null];
                    foreach (['fu_dob_check', 'fu_address_check', 'fu_photo_check', 'fu_signature_check', 'fu_caste_check', 'fu_pwd_check', 'fu_exempted_check', 'fu_exservice_check', 'fu_ews_check'] as $column) {
                        $updates[$column] = null;
                    }
                    foreach (['fu_age_relax_check', 'fu_es_qualification_check', 'fu_es_service_check', 'fu_ds_qualification_check', 'fu_ds_service_check'] as $column) {
                        $updates[$column] = 'No';
                    }
                    DB::table('candidate_result_tab')->where('cr_id', $result->cr_id)->update($updates);
                    $processed++;
                }
            });
            DB::table('advertisement_resubmission_batches')->where('resubmission_id', $window->getKey())->update(['processed_candidates' => $processed, 'deleted_checks' => $deleted]);
            $window->adv_resub_chk_stat = 1;
            $window->adv_resub_chk_start = $now;
            $window->adv_resub_modifydate = $now;
            $window->adv_resub_modifyby = $staffId;
            $window->save();
        }, 3);

        return $this->windows()->whereKey($resubmission->getKey())->firstOrFail();
    }
}
