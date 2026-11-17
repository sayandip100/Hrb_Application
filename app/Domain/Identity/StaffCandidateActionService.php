<?php

namespace App\Domain\Identity;

use App\Models\Advertisement;
use App\Models\CandidateApplication;
use App\Models\CandidateUser;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffCandidateActionService
{
    public function __construct(private StaffCandidateDocumentService $documents) {}

    public const SECTIONS = [
        'essential-qualifications' => ['table' => 'f_user_qualification', 'key' => 'fu_quali_id', 'owner' => 'fu_quali_masteruser', 'master' => 'fu_qualifiaction_name', 'doc' => 'fu_quali_docs', 'prefix' => 'fu', 'check' => 'fu_es_qualification', 'code' => 'EQ', 'type' => 'Essential', 'qualification' => true],
        'desirable-qualifications' => ['table' => 'f_user_des_qualification', 'key' => 'fud_quali_id', 'owner' => 'fud_quali_masteruser', 'master' => 'fud_qualifiaction_name', 'doc' => 'fud_quali_docs', 'prefix' => 'fud', 'check' => 'fu_ds_qualification', 'code' => 'DQ', 'type' => 'Desirable', 'qualification' => true],
        'essential-experiences' => ['table' => 'f_user_ess_experience', 'key' => 'fues_exp_id', 'owner' => 'fues_exp_masteruser', 'master' => 'fues_exp_workname', 'doc' => 'fues_exp_marksheet_doc', 'prefix' => 'fues', 'check' => 'fu_has_es_service', 'code' => 'ES', 'type' => 'Essential', 'qualification' => false],
        'desirable-experiences' => ['table' => 'f_user_experience', 'key' => 'fu_exp_id', 'owner' => 'fu_exp_masteruser', 'master' => 'fu_exp_workname', 'doc' => 'fu_exp_marksheet_doc', 'prefix' => 'fu', 'check' => 'fu_has_ds_service', 'code' => 'DS', 'type' => 'Desirable', 'qualification' => false],
    ];

    public const CHECKS = ['fu_dob', 'fu_address', 'fu_photo_doc', 'fu_signature_doc', 'fu_caste', 'fu_pwd', 'fu_exempted', 'fu_exservice', 'fu_ews', 'fu_age_relax', 'fu_es_qualification', 'fu_ds_qualification', 'fu_has_es_service', 'fu_has_ds_service'];

    public function resolve(string $token, bool $lock = false): CandidateUser
    {
        try {
            $id = PublicId::decode('candidate', $token);
        } catch (DecryptException) {
            abort(404);
        }
        $query = CandidateUser::query()->whereKey($id);
        $candidate = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $this->details($candidate, $lock);

        return $candidate;
    }

    public function details(CandidateUser $candidate, bool $lock = false): object
    {
        $query = DB::table('f_user_details')->where('fu_master', $candidate->f_uid);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        abort_unless($rows->count() === 1, 409, 'A single candidate application detail record is required.');
        $d = $rows->first();
        abort_unless((int) $d->fu_cancel_stat === 0, 409, 'The candidate application is cancelled.');
        foreach (['fu_step_1', 'fu_step_2', 'fu_step_3', 'fu_step_4', 'fu_final_submit', 'fu_payment_stat'] as $column) {
            abort_unless((int) $d->$column === 1, 409, 'This action requires a complete paid application.');
        }

        return $d;
    }

    public function result(CandidateUser $candidate, bool $lock = false): object
    {
        $query = DB::table('candidate_result_tab')->where('cr_application_master', $candidate->f_application_no);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        abort_unless($rows->count() === 1, 409, 'A single candidate result record is required.');

        return $rows->first();
    }

    private function snapshot(CandidateUser $candidate, bool $lock = false): array
    {
        $snapshot = ['profile' => array_intersect_key($candidate->getAttributes(), array_flip(['f_uid', 'f_application_no', 'f_applied_for', 'f_full_name', 'f_mobile', 'f_email', 'f_rename_doc', 'f_rename_reason', 'f_modifydate'])),
            'details' => (array) $this->details($candidate, $lock), 'result' => (array) $this->result($candidate, $lock)];
        foreach (self::SECTIONS as $section => $meta) {
            $query = DB::table($meta['table'])->where($meta['owner'], $candidate->f_uid)->orderBy($meta['key']);
            $snapshot[$section] = ($lock ? $query->lockForUpdate() : $query)->get()->all();
        }
        $query = DB::table('checking_tab')->where('chk_user_application', $candidate->f_application_no)->orderBy('chk_id');
        $snapshot['checks'] = ($lock ? $query->lockForUpdate() : $query)->get()->all();
        $ages = DB::table('f_user_extraage')->where('fu_ext_masteruser', $candidate->f_uid)->orderBy('fu_ext_id');
        $snapshot['age_relaxations'] = ($lock ? $ages->lockForUpdate() : $ages)->get()->all();

        return $snapshot;
    }

    public function revision(CandidateUser $candidate): string
    {
        return hash('sha256', json_encode($this->snapshot($candidate), JSON_THROW_ON_ERROR));
    }

    public function show(CandidateUser $candidate): array
    {
        $d = $this->details($candidate);
        $ad = Advertisement::query()->where('adv_auto_genno', $candidate->f_applied_for)->firstOrFail();
        $post = DB::table('advertisement_categoty as p')->leftJoin('category_master as c', 'c.catm_id', '=', 'p.acat_name')->where('p.acat_id', $d->fu_category)->first(['c.catm_name']);
        $personal = [];
        foreach (['dob', 'gender', 'marital_status', 'father_name', 'mother_name', 'read_write_bengali', 'pwd', 'pwd_percent', 'exempted', 'exservice', 'ews'] as $key) {
            $personal[$key] = $d->{'fu_'.$key};
        }
        $personal['caste_id'] = PublicId::encode('age-relaxation', $d->fu_caste_type);
        $personal['caste_community_id'] = PublicId::encode('caste-community', $d->fu_caste_community);
        $personal['caste_certificate_number'] = $d->fu_caste_number;
        $personal['caste_certificate_date'] = $d->fu_caste_issue_date;
        $personal['issuing_authority_id'] = PublicId::encode('caste-issuing-authority', $d->fu_caste_issue_whom);
        $personal['council_name'] = $d->fu_ext_council_name;
        $personal['council_number'] = $d->fu_ext_council_regno;
        $addresses = [];
        foreach (['present' => 'fu_', 'permanent' => 'fu_perma_'] as $kind => $prefix) {
            $addresses[$kind] = ['address' => $kind === 'present' ? $d->fu_address : $d->fu_perma_house_road,
                'house_road' => $d->{$prefix.'house_road'}, 'pincode' => $d->{$prefix.'pincode'}, 'post_office' => $d->{$prefix.'post_office'}, 'ward_gp' => $d->{$prefix.'ward_gp'}, 'block_type' => $d->{$prefix.'mb_type'}];
            foreach (['state' => ['state', 'state'], 'district' => ['district', $kind === 'present' ? 'district' : 'dist'], 'subdivision' => ['subdivision', 'sub_division'], 'block_municipality' => ['block-municipality', 'block_municipality'], 'police_station' => ['police-station', 'police_station']] as $key => [$type,$column]) {
                $addresses[$kind][$key.'_id'] = PublicId::encode($type, $d->{$prefix.$column});
            }
        }
        $r = $this->result($candidate);

        return ['id' => PublicId::encode('candidate', $candidate->f_uid), 'revision' => $this->revision($candidate),
            'application_number' => $candidate->f_application_no, 'name' => $candidate->f_full_name, 'mobile' => $candidate->f_mobile, 'email' => $candidate->f_email,
            'advertisement' => ['id' => PublicId::encode('advertisement', $ad->adv_id), 'number' => $ad->adv_no, 'reference' => $ad->adv_auto_genno, 'recruitment_id' => PublicId::encode('recruitment', $ad->adv_recruit_master)],
            'post' => ['id' => PublicId::encode('advertisement-post', $d->fu_category), 'name' => $post?->catm_name],
            'personal' => $personal, 'addresses' => $addresses, 'entries' => $this->entries($candidate),
            'age_relaxations' => DB::table('f_user_extraage as e')->leftJoin('caste_tab as m', 'm.caste_id', '=', 'e.fu_ext_ageid')
                ->where('e.fu_ext_masteruser', $candidate->f_uid)->orderBy('e.fu_ext_id')->get(['e.fu_ext_id', 'e.fu_ext_ageid', 'e.fu_ext_answer', 'e.fu_ext_reason', 'm.caste_name'])
                ->map(fn ($row): array => ['id' => PublicId::encode('candidate-age-claim', $row->fu_ext_id), 'section_id' => PublicId::encode('age-relaxation', $row->fu_ext_ageid), 'name' => $row->caste_name, 'answer' => $row->fu_ext_answer, 'reason' => $row->fu_ext_reason])->all(),
            'documents' => $this->documents->catalog($candidate, $d),
            'review' => ['status' => $r->cr_approval, 'rejection_reason' => $r->cr_reject_comments],
            'actions' => ['can_modify_profile' => true, 'can_modify_dob' => true, 'can_swap' => $r->cr_approval === 'NotChecked', 'can_reject' => $r->cr_approval !== 'Rejected', 'can_view_marks' => $this->hasMerit($candidate, $d)]];
    }

    public function entries(CandidateUser $candidate): array
    {
        $data = [];
        foreach (self::SECTIONS as $section => $m) {
            $q = $m['qualification'];
            $rows = DB::table($m['table'].' as e')->leftJoin(($q ? 'qualification_master' : 'experience_master_tab').' as m', 'm.'.($q ? 'qm_id' : 'expset_id'), '=', 'e.'.$m['master'])
                ->where('e.'.$m['owner'], $candidate->f_uid)->orderBy('e.'.$m['key'])->select('e.*', 'm.'.($q ? 'qm_name' : 'expset_name').' as master_name')->get();
            $data[$section] = $rows->map(function ($r) use ($section, $m, $q): array {
                $p = $m['prefix'];
                $item = ['id' => PublicId::encode('candidate-entry:'.$section, $r->{$m['key']}), 'master_id' => PublicId::encode($q ? 'qualification' : 'experience', $r->{$m['master']}), 'name' => $r->master_name];
                foreach ($q ? ['council_board', 'full_marks', 'marks_obtained', 'percent_of_marks', 'is_attempt', 'attempt_no', 'marksheet_issuedate'] : ['exp_org_name', 'exp_year', 'exp_month', 'exp_approval'] as $field) {
                    $item[$field] = $r->{$p.'_'.$field};
                }

                return $item;
            })->all();
        }

        return $data;
    }

    public function checks(CandidateUser $candidate, ?string $section = null, ?string $subtype = null): array
    {
        $query = DB::table('checking_tab')->where('chk_user_application', $candidate->f_application_no);
        if ($section) {
            $query->where('chk_type', $section);
        }
        $type = $section === 'fu_age_relax' ? 'age-relaxation' : (in_array($section, ['fu_es_qualification', 'fu_ds_qualification'], true) ? 'qualification' : 'experience');
        if ($subtype) {
            try {
                $id = PublicId::decode($type, $subtype);
            } catch (DecryptException) {
                throw ValidationException::withMessages(['subtype_id' => 'Invalid subtype ID for this section.']);
            }
            $query->where('chk_sub_typeid', $id);
        }

        return $query->orderBy('chk_id')->get()->map(function ($r): array {
            $type = $r->chk_type === 'fu_age_relax' ? 'age-relaxation' : (in_array($r->chk_type, ['fu_es_qualification', 'fu_ds_qualification'], true) ? 'qualification' : 'experience');

            return ['id' => PublicId::encode('candidate-check', $r->chk_id), 'section' => $r->chk_type, 'subtype_id' => PublicId::encode($type, $r->chk_sub_typeid),
                'level_1' => ['status' => $r->chk_approve, 'comments' => $r->chk_comments, 'staff_id' => PublicId::encode('staff', $r->chk_createby), 'checked_at' => $r->chk_appro_date],
                'level_2' => ['status' => $r->chk2_approve, 'comments' => $r->chk2_comments, 'staff_id' => PublicId::encode('staff', $r->chk2_appro_by), 'checked_at' => $r->chk2_appro_date],
                'admin' => ['status' => $r->chkadm_approve, 'comments' => $r->chkadm_comments, 'staff_id' => PublicId::encode('staff', $r->chkadm_appro_by), 'checked_at' => $r->chkadm_appro_date],
                'final_status' => $r->chk_final_state, 'marks' => (float) $r->chk_got_marks];
        })->all();
    }

    public function history(CandidateUser $candidate, int $page, int $perPage): mixed
    {
        return DB::table('checker_log')->where('chklog_app_no', $candidate->f_application_no)->orderBy('chklog_id')->paginate($perPage, ['chklog_id', 'chklog_type', 'chklog_type_id', 'chklog_user', 'chklog_approval', 'chklog_msg', 'chklog_createdate'], 'page', $page);
    }

    public function swapOptions(CandidateUser $candidate): array
    {
        $options = [];
        foreach (self::SECTIONS as $section => $m) {
            $q = $m['qualification'];
            $options[$section] = DB::table(($q ? 'advertisement_qualification' : 'advertisement_experience').' as o')
                ->join(($q ? 'qualification_master' : 'experience_master_tab').' as m', 'm.'.($q ? 'qm_id' : 'expset_id'), '=', 'o.'.($q ? 'aquali_exam' : 'aexpr_name'))
                ->where($q ? 'aquali_adv_master' : 'aexpr_adv_master', $candidate->f_applied_for)
                ->where($q ? 'aquali_examtype' : 'aexpr_type', $m['type'])->where($q ? 'aquali_status' : 'aexpr_status', 1)
                ->where($q ? 'qm_status' : 'expset_status', 1)->orderBy($q ? 'aquali_id' : 'aexpr_id')
                ->get()->map(fn ($r): array => ['id' => PublicId::encode($q ? 'advertisement-qualification' : 'advertisement-experience', $r->{$q ? 'aquali_id' : 'aexpr_id'}), 'name' => $r->{$q ? 'qm_name' : 'expset_name'}])->all();
        }

        return ['revision' => $this->revision($candidate), 'entries' => $this->entries($candidate), 'options' => $options, 'can_swap' => $this->result($candidate)->cr_approval === 'NotChecked'];
    }

    private function hasMerit(CandidateUser $c, object $d): bool
    {
        return DB::table('merit_list_tab')->where('mr_adv_master', $c->f_applied_for)->where('mr_category', $d->fu_category)->exists();
    }

    public function marks(CandidateUser $candidate): array
    {
        abort_unless($this->hasMerit($candidate, $this->details($candidate)), 409, 'Marks are available when a merit list exists for this advertisement/post.');
        $r = $this->result($candidate);

        return ['application_number' => $candidate->f_application_no, 'review_status' => $r->cr_approval, 'academic' => (float) $r->cr_academic, 'experience' => (float) $r->cr_experience,
            'interview_1' => (float) $r->cr_interview_1, 'interview_2' => (float) $r->cr_interview_2, 'written' => (float) $r->cr_written, 'total' => (float) $r->cr_total_marks, 'sections' => $this->checks($candidate)];
    }

    public function mutate(string $token, string $action, array $input, int $staffId, ?string $evidence): CandidateUser
    {
        return DB::transaction(function () use ($token, $action, $input, $staffId, $evidence): CandidateUser {
            $candidate = $this->resolve($token, true);
            $before = $this->snapshot($candidate, true);
            abort_unless(hash_equals(hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)), $input['revision']), 409, 'This application has changed. Reload before saving.');
            $d = (object) $before['details'];
            $r = (object) $before['result'];
            $changes = [];
            if ($action === 'profile') {
                $profile = [];
                foreach (['name' => 'f_full_name', 'mobile' => 'f_mobile', 'email' => 'f_email'] as $field => $column) {
                    if (isset($input[$field])) {
                        $profile[$column] = $input[$field];
                    }
                }
                if (isset($profile['f_email'])) {
                    $profile['f_email'] = mb_strtolower($profile['f_email']);
                }
                $mobile = $profile['f_mobile'] ?? $candidate->f_mobile;
                $email = $profile['f_email'] ?? $candidate->f_email;
                if ($mobile !== $candidate->f_mobile || $email !== $candidate->f_email) {
                    Advertisement::query()->where('adv_auto_genno', $candidate->f_applied_for)->lockForUpdate()->firstOrFail();
                    if (CandidateUser::query()->where('f_applied_for', $candidate->f_applied_for)->where('f_uid', '!=', $candidate->f_uid)
                        ->where(fn ($q) => $q->where('f_mobile', $mobile)->orWhereRaw('LOWER(f_email) = ?', [$email]))->exists()) {
                        throw ValidationException::withMessages(['mobile' => 'Mobile/email is already used for this advertisement.']);
                    }
                }
                $profile['f_rename_doc'] = $evidence;
                $profile['f_rename_reason'] = $input['reason'];
                $profile['f_modifydate'] = now('Asia/Kolkata')->format('Y-m-d H:i:s');
                DB::table('frontend_users')->where('f_uid', $candidate->f_uid)->update($profile);
                foreach (['father_name', 'mother_name'] as $key) {
                    if (isset($input[$key])) {
                        $changes['fu_'.$key] = $input[$key];
                    }
                }
                if (isset($input['father_name'])) {
                    $changes['f_c_r_doc'] = $evidence;
                    $changes['f_rename_resn'] = $input['reason'];
                }
                if (isset($input['mother_name'])) {
                    $changes['m_c_r_doc'] = $evidence;
                    $changes['m_rename_resn'] = $input['reason'];
                }
            } elseif ($action === 'dob') {
                $this->validateDob($candidate, $d, $input['dob']);
                $changes['fu_dob'] = $input['dob'];
            } elseif ($action === 'reject') {
                abort_if($r->cr_approval === 'Rejected', 409, 'Candidate is already rejected.');
                DB::table('candidate_result_tab')->where('cr_id', $r->cr_id)->update(['cr_approval' => 'Rejected', 'cr_reject_comments' => $input['reason'], 'cr_reject_document' => $evidence, 'cr_admitcard_date' => now('Asia/Kolkata')->format('Y-m-d H:i:s')]);
            } else {
                abort_unless($r->cr_approval === 'NotChecked', 409, 'Swapping requires NotChecked result status.');
                $this->swap($candidate, $input, $staffId);
            }
            if ($changes !== []) {
                DB::table('f_user_details')->where('fu_details_id', $d->fu_details_id)->update($changes);
            }
            $this->syncDraft($candidate, $action, $input);
            $candidate->refresh();
            DB::table('candidate_admin_events')->insert(['candidate_uid' => $candidate->f_uid, 'staff_id' => $staffId, 'action' => $action, 'reason' => $input['reason'],
                'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($this->snapshot($candidate), JSON_THROW_ON_ERROR), 'created_at' => now('Asia/Kolkata')->format('Y-m-d H:i:s')]);

            return $candidate;
        }, 3);
    }

    private function validateDob(CandidateUser $candidate, object $d, string $dob): void
    {
        $ad = Advertisement::query()->where('adv_auto_genno', $candidate->f_applied_for)->firstOrFail();
        abort_if(blank($ad->adv_age_limit) || blank($ad->adv_min_age_limit), 409, 'Advertisement DOB limits are not configured.');
        $caste = DB::table('caste_tab')->where('caste_id', $d->fu_caste_type)->first();
        $ids = [(int) $d->fu_caste_type, (int) ($caste->caste_parent ?? 0)];
        foreach (['pwd' => 7, 'exempted' => 8, 'exservice' => 9, 'ews' => 10] as $claim => $id) {
            if ($d->{'fu_'.$claim} === 'Yes') {
                $ids[] = $id;
            }
        }
        foreach (DB::table('f_user_extraage')->where('fu_ext_masteruser', $candidate->f_uid)->where('fu_ext_answer', 'Yes')->get(['fu_ext_ageid']) as $claim) {
            $ids[] = (int) $claim->fu_ext_ageid;
        }
        $total = $group = 0;
        foreach (DB::table('advertisement_age_set')->where('advage_adv_master', $candidate->f_applied_for)->orderBy('advage_id')->get() as $rule) {
            $group = max($group, in_array((int) $rule->advage_section, $ids, true) ? (int) $rule->advage_up : 0);
            if ($rule->advage_type !== 'OR') {
                $total += $group;
                $group = 0;
            }
        }
        $total += $group;
        if ($ad->adv_age_updown > 0) {
            $total = min($total, (int) $ad->adv_age_updown);
        }
        $from = Carbon::parse($ad->adv_age_limit)->subYearsNoOverflow($total)->format('Y-m-d');
        $to = Carbon::parse($ad->adv_min_age_limit)->format('Y-m-d');
        if ($dob < $from || $dob > $to) {
            throw ValidationException::withMessages(['dob' => 'DOB is outside the advertisement range after applicable age relaxation.']);
        }
    }

    private function swap(CandidateUser $c, array $input, int $staff): void
    {
        $m = self::SECTIONS[$input['section']];
        $q = $m['qualification'];
        $plans = [];
        $seen = [];
        foreach ($input['changes'] as $i => $change) {
            try {
                $id = PublicId::decode('candidate-entry:'.$input['section'], $change['entry_id']);
                $optionId = PublicId::decode($q ? 'advertisement-qualification' : 'advertisement-experience', $change['option_id']);
            } catch (DecryptException) {
                throw ValidationException::withMessages(['changes.'.$i => 'Invalid encrypted entry or option ID.']);
            }
            if (isset($seen[$id])) {
                throw ValidationException::withMessages(['changes' => 'An entry may only be changed once.']);
            } $seen[$id] = true;
            $entry = DB::table($m['table'])->where($m['key'], $id)->where($m['owner'], $c->f_uid)->first();
            $option = DB::table($q ? 'advertisement_qualification' : 'advertisement_experience')->where($q ? 'aquali_id' : 'aexpr_id', $optionId)
                ->where($q ? 'aquali_adv_master' : 'aexpr_adv_master', $c->f_applied_for)->where($q ? 'aquali_examtype' : 'aexpr_type', $m['type'])->where($q ? 'aquali_status' : 'aexpr_status', 1)->first();
            $target = $option?->{$q ? 'aquali_exam' : 'aexpr_name'};
            $active = $target && DB::table($q ? 'qualification_master' : 'experience_master_tab')->where($q ? 'qm_id' : 'expset_id', $target)->where($q ? 'qm_status' : 'expset_status', 1)->exists();
            if (! $entry || ! $active) {
                throw ValidationException::withMessages(['changes.'.$i => 'Select an owned entry and active matching advertisement requirement.']);
            }
            $plans[] = ['entry' => $entry, 'target' => $target, 'option' => $optionId];
        }
        if ($q) {
            $final = DB::table($m['table'])->where($m['owner'], $c->f_uid)->pluck($m['master'], $m['key'])->all();
            foreach ($plans as $plan) {
                $final[$plan['entry']->{$m['key']}] = $plan['target'];
            }
            if (count($final) !== count(array_unique($final))) {
                throw ValidationException::withMessages(['changes' => 'The resulting qualification list must not contain duplicates.']);
            }
        }
        foreach ($plans as $plan) {
            $old = $plan['entry']->{$m['master']};
            if ((int) $old === (int) $plan['target']) {
                continue;
            }
            DB::table($m['table'])->where($m['key'], $plan['entry']->{$m['key']})->update([$m['master'] => $plan['target']]);
            $checks = DB::table('checking_tab')->where('chk_user_application', $c->f_application_no)->where('chk_type', $m['check'])->where('chk_sub_typeid', $old);
            $checks->delete();
            DB::table('qe_swip_log')->insert(['qes_type' => $m['code'], 'qes_prev_set' => $old, 'qes_new_set' => $plan['target'], 'qes_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'), 'qes_createby' => $staff]);
        }
        $sidecar = CandidateApplication::query()->where('candidate_uid', $c->f_uid)->lockForUpdate()->first();
        if ($sidecar) {
            $payload = $sidecar->payload;
            $section = $q ? 'qualifications' : 'experiences';
            $matchedPlans = [];
            foreach ($payload[$section]['items'] ?? [] as $i => $item) {
                $oldId = PublicId::decode($q ? 'advertisement-qualification' : 'advertisement-experience', $item['option_id']);
                $oldOption = DB::table($q ? 'advertisement_qualification' : 'advertisement_experience')->where($q ? 'aquali_id' : 'aexpr_id', $oldId)->first();
                if (! $oldOption || $oldOption->{$q ? 'aquali_examtype' : 'aexpr_type'} !== $m['type']) {
                    continue;
                }
                $oldMaster = $oldOption->{$q ? 'aquali_exam' : 'aexpr_name'};
                foreach ($plans as $j => $plan) {
                    if (isset($matchedPlans[$j]) || (int) $oldMaster !== (int) $plan['entry']->{$m['master']}) {
                        continue;
                    }
                    if (! $q) {
                        $docId = PublicId::decode('candidate-document', $item['document_id']);
                        $path = DB::table('candidate_documents')->where('id', $docId)->where('candidate_uid', $c->f_uid)->value('path');
                        if ($path !== $plan['entry']->{$m['doc']}
                            || ($item['organization'] ?? null) !== $plan['entry']->{$m['prefix'].'_exp_org_name'}
                            || (int) ($item['years'] ?? 0) !== (int) $plan['entry']->{$m['prefix'].'_exp_year'}
                            || (int) ($item['months'] ?? 0) !== (int) $plan['entry']->{$m['prefix'].'_exp_month'}) {
                            continue;
                        }
                    }
                    $payload[$section]['items'][$i]['option_id'] = PublicId::encode($q ? 'advertisement-qualification' : 'advertisement-experience', $plan['option']);
                    $matchedPlans[$j] = true;
                    break;
                }
            }
            if (count($matchedPlans) !== count($plans)) {
                abort(409, 'The saved candidate payload does not match these entries. Reconcile it before swapping.');
            }
            $sidecar->payload = $payload;
            $sidecar->version++;
            $sidecar->save();
        }
    }

    private function syncDraft(CandidateUser $candidate, string $action, array $input): void
    {
        if (! in_array($action, ['profile', 'dob'], true)) {
            return;
        }
        $application = CandidateApplication::query()->where('candidate_uid', $candidate->f_uid)->lockForUpdate()->first();
        if (! $application) {
            return;
        }
        $payload = $application->payload;
        foreach (['dob', 'father_name', 'mother_name'] as $key) {
            if (isset($input[$key])) {
                $payload['personal-details'][$key] = $input[$key];
            }
        }
        $application->payload = $payload;
        $application->version++;
        $application->save();
    }
}
