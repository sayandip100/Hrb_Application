<?php

namespace App\Domain\Identity;

use App\Models\Advertisement;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\CandidateUser;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CandidateApplicationService
{
    public static function isOpen(Advertisement $ad): bool
    {
        $now = now('Asia/Kolkata')->format('Y-m-d H:i:s');
        $start = $ad->adv_type === 'Old' ? $ad->adv_old_starttime : $ad->adv_start_time?->format('Y-m-d H:i:s');
        $end = $ad->adv_type === 'Old' ? $ad->adv_old_endtime : $ad->adv_end_time?->format('Y-m-d H:i:s');

        return $ad->adv_status === 1 && $ad->adv_activated === 1 && $start && $end && $start < $now && $end > $now;
    }

    public static function publicAdvertisement(string $token, bool $open = false): Advertisement
    {
        try {
            $id = PublicId::decode('advertisement', $token);
        } catch (DecryptException) {
            abort(404);
        }
        $ad = Advertisement::query()->whereKey($id)->where('adv_status', 1)->where('adv_activated', 1)->firstOrFail();
        abort_if($open && ! self::isOpen($ad), 409, 'This advertisement is not open for applications.');

        return $ad;
    }

    public function application(CandidateUser $candidate, bool $lock = false): CandidateApplication
    {
        $query = CandidateApplication::query()->where('candidate_uid', $candidate->f_uid);

        return ($lock ? $query->lockForUpdate() : $query)->firstOr(function (): never {
            abort(409, 'This is a legacy application. Its existing data must be imported before using the new application editor.');
        });
    }

    public function advertisement(CandidateUser $candidate): Advertisement
    {
        return Advertisement::query()->where('adv_auto_genno', $candidate->f_applied_for)->firstOrFail();
    }

    public function editable(CandidateUser $candidate, CandidateApplication $application, int $version): void
    {
        abort_unless($application->state === 'draft', 409, 'The submitted application cannot be edited.');
        abort_unless($application->version === $version, 409, 'This application has changed. Reload it before saving.');
        abort_unless(self::isOpen($this->advertisement($candidate)), 409, 'This advertisement is not open for applications.');
    }

    public function publicData(Advertisement $ad, bool $details = false): array
    {
        $data = ['id' => PublicId::encode('advertisement', $ad->adv_id), 'reference' => $ad->adv_auto_genno, 'advertisement_number' => $ad->adv_no,
            'type' => $ad->adv_type, 'starts_at' => $ad->adv_start_time?->format('Y-m-d H:i:s'), 'ends_at' => $ad->adv_end_time?->format('Y-m-d H:i:s'),
            'old_starts_at' => $ad->adv_old_starttime, 'old_ends_at' => $ad->adv_old_endtime, 'total_vacancies' => $ad->adv_total_recruit,
            'fee' => (float) $ad->adv_fees, 'can_apply' => self::isOpen($ad), 'timezone' => 'Asia/Kolkata'];
        if ($details) {
            $data['instructions'] = AdvertisementText::normalize(['temporary_appointment' => $ad->adv_app_temp_job, 'pay_scale' => $ad->adv_scale_pay,
                'age_writeup' => $ad->adv_age_writeup, 'essential_writeup' => $ad->adv_essen_qualification, 'desirable_writeup' => $ad->adv_desir_qualification,
                'marks_writeup' => $ad->adv_marks_writeup, 'miscellaneous_writeup' => $ad->adv_miscellenius, 'disability_writeup' => $ad->adv_disability, 'language_writeup' => $ad->adv_lang_writeup]);
            $data['requirements'] = ['dob_from' => $ad->adv_age_limit, 'dob_to' => $ad->adv_min_age_limit, 'maximum_age_relaxation_years' => (int) $ad->adv_age_updown,
                'minimum_pwd_percent' => (int) $ad->adv_pwd_percent, 'registration_certificate' => $ad->adv_reg_certificate, 'bengali_required' => $ad->adv_dictation_set];
            $data['options'] = $this->options($ad);
        }

        return $data;
    }

    public function options(Advertisement $ad): array
    {
        return [
            'posts' => DB::table('advertisement_categoty as p')->join('category_master as m', 'm.catm_id', '=', 'p.acat_name')
                ->where('p.acat_adv_master', $ad->adv_auto_genno)->orderBy('p.acat_id')->get()->map(fn ($p) => [
                    'id' => PublicId::encode('advertisement-post', $p->acat_id), 'name' => $p->catm_name, 'gender' => $p->acat_gender_set, 'marital_status' => $p->acat_marital_set, 'vacancies' => (int) $p->acat_total]),
            'qualifications' => DB::table('advertisement_qualification as q')->join('qualification_master as m', 'm.qm_id', '=', 'q.aquali_exam')
                ->where('q.aquali_adv_master', $ad->adv_auto_genno)->where('q.aquali_status', 1)->orderBy('q.aquali_id')->get()->map(fn ($q) => [
                    'id' => PublicId::encode('advertisement-qualification', $q->aquali_id), 'name' => $q->qm_name, 'type' => $q->aquali_examtype,
                    'relationship' => $q->aquali_relation, 'allow_pursuing' => $q->aquali_pursuing_chk, 'attempt_type' => $q->aquali_attempt]),
            'experiences' => DB::table('advertisement_experience as e')->join('experience_master_tab as m', 'm.expset_id', '=', 'e.aexpr_name')
                ->where('e.aexpr_adv_master', $ad->adv_auto_genno)->where('e.aexpr_status', 1)->orderBy('e.aexpr_id')->get()->map(fn ($e) => [
                    'id' => PublicId::encode('advertisement-experience', $e->aexpr_id), 'name' => $e->expset_name, 'type' => $e->aexpr_type, 'relationship' => $e->aexpr_relation, 'minimum_months' => (int) $e->aexpr_min_month]),
            'age_fee_rules' => DB::table('advertisement_age_set as a')->join('caste_tab as c', 'c.caste_id', '=', 'a.advage_section')
                ->where('a.advage_adv_master', $ad->adv_auto_genno)->orderBy('a.advage_id')->get()->map(fn ($a) => [
                    'section_id' => PublicId::encode('age-relaxation', $a->advage_section), 'name' => $a->caste_name, 'category' => (int) $a->caste_cat,
                    'relationship' => $a->advage_type, 'years' => (int) $a->advage_up, 'fee_type' => $a->advage_feetype, 'partial_fee' => $a->advage_partfee]),
            'castes' => DB::table('caste_tab')->where('caste_status', 1)->whereIn('caste_cat', [1, 2])->orderBy('caste_name')->get()->map(fn ($c) => ['id' => PublicId::encode('age-relaxation', $c->caste_id), 'name' => $c->caste_name]),
            'caste_communities' => DB::table('caste_details_tab')->where('csdetail_status', 1)->orderBy('csdetail_name')->get()->map(fn ($c) => ['id' => PublicId::encode('caste-community', $c->csdetail_id), 'caste_id' => PublicId::encode('age-relaxation', $c->csdetail_master), 'name' => $c->csdetail_name]),
            'issuing_authorities' => DB::table('caste_issuing_auth_tab')->where('cia_status', 1)->orderBy('cia_name')->get()->map(fn ($c) => ['id' => PublicId::encode('caste-issuing-authority', $c->cia_id), 'name' => $c->cia_name]),
        ];
    }

    public function save(CandidateUser $candidate, string $section, array $data): CandidateApplication
    {
        return DB::transaction(function () use ($candidate, $section, $data): CandidateApplication {
            $application = $this->application($candidate, true);
            $this->editable($candidate, $application, (int) $data['version']);
            $payload = $application->payload;
            $payload[$section] = array_replace($payload[$section] ?? [], $data['data']);
            $errors = $this->semantic($candidate, $this->advertisement($candidate), $payload, false);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $application->payload = $payload;
            $application->version++;
            $application->save();

            return $application;
        });
    }

    public function document(CandidateUser $candidate, mixed $token, string $purpose): CandidateDocument
    {
        $id = PublicId::decode('candidate-document', $token);
        $document = CandidateDocument::query()->whereKey($id)->where('candidate_uid', $candidate->f_uid)->where('purpose', $purpose)->first();
        if (! $document || ! Storage::disk('local')->exists($document->path)) {
            throw ValidationException::withMessages(['documents' => 'A required document is missing, has the wrong purpose, or does not belong to you.']);
        }

        return $document;
    }

    public function semantic(CandidateUser $candidate, Advertisement $ad, array $payload, bool $complete): array
    {
        $errors = [];
        $personal = $payload['personal-details'] ?? [];
        $category = $payload['category-relaxations'] ?? [];
        if (isset($payload['post']['post_id'])) {
            $post = DB::table('advertisement_categoty')->where('acat_id', PublicId::decode('advertisement-post', $payload['post']['post_id']))->where('acat_adv_master', $ad->adv_auto_genno)->first();
            if (! $post) {
                $errors['post.post_id'] = 'Select a post belonging to this advertisement.';
            } elseif ($complete) {
                foreach (['gender' => 'acat_gender_set', 'marital_status' => 'acat_marital_set'] as $field => $column) {
                    if ($post->$column !== 'All' && ($personal[$field] ?? null) !== $post->$column) {
                        $errors['personal-details.'.$field] = 'You do not meet the selected post restriction.';
                    }
                }
            }
        }
        foreach (['present', 'permanent'] as $address) {
            $state = isset($personal[$address.'_state_id']) ? PublicId::decode('state', $personal[$address.'_state_id']) : null;
            $district = isset($personal[$address.'_district_id']) ? PublicId::decode('district', $personal[$address.'_district_id']) : null;
            $subdivision = isset($personal[$address.'_subdivision_id']) ? PublicId::decode('subdivision', $personal[$address.'_subdivision_id']) : null;
            if ($state !== null && ! DB::table('state_master')->where('state_id', $state)->where('state_status', 1)->exists()) {
                $errors['personal-details.'.$address.'_state_id'] = 'Select an active state.';
            }
            if ($district !== null && (! $state || ! DB::table('district_master')->where('district_id', $district)->where('state_id', $state)->where('district_status', 1)->exists())) {
                $errors['personal-details.'.$address.'_district_id'] = 'Select a district in the chosen state.';
            }
            if ($subdivision !== null && (! $district || ! DB::table('subdivision_tab')->where('subdiv_id', $subdivision)->where('subdiv_district', (string) $district)->where('subdiv_status', 1)->exists())) {
                $errors['personal-details.'.$address.'_subdivision_id'] = 'Select a subdivision in the chosen district.';
            }
            foreach (['block_municipality' => ['block_master', 'block_id', 'subd_id', 'block_status', 'block-municipality', $subdivision],
                'police_station' => ['police_station_tab', 'ps_id', 'ps_dist_master', 'ps_status', 'police-station', $district]] as $field => [$table,$key,$parent,$status,$type,$expected]) {
                if (isset($personal[$address.'_'.$field.'_id']) && (! $expected || ! DB::table($table)->where($key, PublicId::decode($type, $personal[$address.'_'.$field.'_id']))->where($parent, (string) $expected)->where($status, 1)->exists())) {
                    $errors['personal-details.'.$address.'_'.$field.'_id'] = 'Select a location belonging to the chosen parent.';
                }
            }
        }
        $caste = null;
        if (isset($category['caste_id'])) {
            $caste = DB::table('caste_tab')->where('caste_id', PublicId::decode('age-relaxation', $category['caste_id']))->whereIn('caste_cat', [1, 2])->where('caste_status', 1)->first();
            if (! $caste) {
                $errors['category-relaxations.caste_id'] = 'Select an active caste/category.';
            }
        }
        if (isset($category['caste_community_id'])) {
            if (! $caste || ! DB::table('caste_details_tab')->where('csdetail_id', PublicId::decode('caste-community', $category['caste_community_id']))->where('csdetail_master', $caste->caste_id)->where('csdetail_status', 1)->exists()) {
                $errors['category-relaxations.caste_community_id'] = 'Select a community belonging to the chosen caste.';
            }
        }
        if (isset($category['issuing_authority_id']) && ! DB::table('caste_issuing_auth_tab')->where('cia_id', PublicId::decode('caste-issuing-authority', $category['issuing_authority_id']))->where('cia_status', 1)->exists()) {
            $errors['category-relaxations.issuing_authority_id'] = 'Select an active issuing authority.';
        }
        $purposes = ['photo', 'signature', 'address', 'dob', 'council'];
        foreach ($purposes as $purpose) {
            if (isset($personal[$purpose.'_document_id'])) {
                $this->document($candidate, $personal[$purpose.'_document_id'], $purpose);
            }
        }
        foreach (['caste', 'pwd', 'exempted', 'exservice', 'ews'] as $purpose) {
            if (isset($category[$purpose.'_document_id'])) {
                $this->document($candidate, $category[$purpose.'_document_id'], $purpose);
            }
        }
        $claimed = [];
        foreach ($category['age_relaxations'] ?? [] as $claim) {
            $id = PublicId::decode('age-relaxation', $claim['section_id']);
            $allowed = DB::table('advertisement_age_set')->join('caste_tab', 'caste_id', '=', 'advage_section')->where('advage_adv_master', $ad->adv_auto_genno)->where('advage_section', $id)->where('caste_cat', 8)->exists();
            if (! $allowed || in_array($id, $claimed, true)) {
                $errors['category-relaxations.age_relaxations'] = 'Select distinct additional relaxation rules from this advertisement.';
            }
            $claimed[] = $id;
            $this->document($candidate, $claim['document_id'], 'age-relaxation');
        }
        $matched = [];
        foreach (['qualifications', 'experiences'] as $section) {
            $qualification = $section === 'qualifications';
            $seen = [];
            foreach ($payload[$section]['items'] ?? [] as $i => $item) {
                $id = PublicId::decode($qualification ? 'advertisement-qualification' : 'advertisement-experience', $item['option_id']);
                $option = DB::table($qualification ? 'advertisement_qualification' : 'advertisement_experience')->where($qualification ? 'aquali_id' : 'aexpr_id', $id)
                    ->where($qualification ? 'aquali_adv_master' : 'aexpr_adv_master', $ad->adv_auto_genno)->where($qualification ? 'aquali_status' : 'aexpr_status', 1)->first();
                if (! $option || ($qualification && in_array($id, $seen, true))) {
                    $errors[$section.'.items.'.$i.'.option_id'] = 'Select a valid distinct requirement from this advertisement.';

                    continue;
                }
                $seen[] = $id;
                if ($qualification && ($item['pursuing'] ?? 'No') === 'Yes' && $option->aquali_pursuing_chk !== 'Yes') {
                    $errors[$section.'.items.'.$i.'.pursuing'] = 'Pursuing this qualification is not permitted.';
                }
                if (isset($item['document_id'])) {
                    $this->document($candidate, $item['document_id'], $qualification ? 'qualification' : 'experience');
                }
                $matched[$section][$id] = ($matched[$section][$id] ?? 0) + ($qualification ? 1 : ((int) ($item['years'] ?? 0) * 12 + (int) ($item['months'] ?? 0)));
            }
            if ($complete) {
                $rules = DB::table($qualification ? 'advertisement_qualification' : 'advertisement_experience')->where($qualification ? 'aquali_adv_master' : 'aexpr_adv_master', $ad->adv_auto_genno)
                    ->where($qualification ? 'aquali_examtype' : 'aexpr_type', 'Essential')->where($qualification ? 'aquali_status' : 'aexpr_status', 1)->orderBy($qualification ? 'aquali_id' : 'aexpr_id')->get();
                $group = false;
                $pendingGroup = false;
                foreach ($rules as $rule) {
                    $pendingGroup = true;
                    $id = $qualification ? $rule->aquali_id : $rule->aexpr_id;
                    $group = $group || (($matched[$section][$id] ?? 0) >= ($qualification ? 1 : max(1, $rule->aexpr_min_month)));
                    if (($qualification ? $rule->aquali_relation : $rule->aexpr_relation) !== 'OR') {
                        if (! $group) {
                            $errors[$section] = 'An essential AND/OR requirement has not been satisfied.';
                        }
                        $group = false;
                        $pendingGroup = false;
                    }
                }
                if ($pendingGroup && ! $group) {
                    $errors[$section] = 'An essential AND/OR requirement has not been satisfied.';
                }
            }
        }
        if ($complete) {
            if ($caste && (int) $caste->caste_id !== 1) {
                foreach (['caste_certificate_number', 'caste_certificate_date', 'issuing_authority_id', 'caste_document_id'] as $field) {
                    if (blank($category[$field] ?? null)) {
                        $errors['category-relaxations.'.$field] = 'This field is required for a reserved category claim.';
                    }
                }
            }
            foreach (['pwd', 'exempted', 'exservice', 'ews'] as $claim) {
                if (($category[$claim] ?? 'No') === 'Yes' && blank($category[$claim.'_document_id'] ?? null)) {
                    $errors['category-relaxations.'.$claim.'_document_id'] = 'Supporting evidence is required for this claim.';
                }
            }
            if (($category['pwd'] ?? 'No') === 'Yes' && (float) ($category['pwd_percent'] ?? 0) < (float) $ad->adv_pwd_percent) {
                $errors['category-relaxations.pwd_percent'] = 'The disability percentage does not meet the advertisement requirement.';
            }
            if ($ad->adv_dictation_set === 'Yes' && ($personal['read_write_bengali'] ?? 'No') !== 'Yes') {
                $errors['personal-details.read_write_bengali'] = 'Bengali proficiency is required.';
            }
            if ($ad->adv_reg_certificate === 'Yes') {
                foreach (['council_name', 'council_number', 'council_document_id'] as $field) {
                    if (blank($personal[$field] ?? null)) {
                        $errors['personal-details.'.$field] = 'Professional registration is required.';
                    }
                }
            }
            $years = $this->relaxationYears($ad, $category, $caste);
            if (isset($personal['dob']) && ($personal['dob'] < $ad->adv_age_limit || $personal['dob'] > $ad->adv_min_age_limit)) {
                $minimum = Carbon::parse($ad->adv_age_limit)->subYearsNoOverflow($years)->format('Y-m-d');
                if ($personal['dob'] < $minimum || $personal['dob'] > $ad->adv_min_age_limit) {
                    $errors['personal-details.dob'] = 'Date of birth is outside the permitted range after age relaxation.';
                }
            }
        }

        return $errors;
    }

    private function matchedSections(array $category, mixed $caste): array
    {
        $ids = $caste ? [(int) $caste->caste_id, (int) $caste->caste_parent] : [];
        foreach (['pwd' => 7, 'exempted' => 8, 'exservice' => 9, 'ews' => 10] as $field => $id) {
            if (($category[$field] ?? 'No') === 'Yes') {
                $ids[] = $id;
            }
        }
        foreach ($category['age_relaxations'] ?? [] as $claim) {
            $ids[] = PublicId::decode('age-relaxation', $claim['section_id']);
        }

        return $ids;
    }

    private function relaxationYears(Advertisement $ad, array $category, mixed $caste): int
    {
        $ids = $this->matchedSections($category, $caste);
        $total = $group = 0;
        foreach (DB::table('advertisement_age_set')->where('advage_adv_master', $ad->adv_auto_genno)->orderBy('advage_id')->get() as $rule) {
            $group = max($group, in_array((int) $rule->advage_section, $ids, true) ? (int) $rule->advage_up : 0);
            if ($rule->advage_type !== 'OR') {
                $total += $group;
                $group = 0;
            }
        }
        $total += $group;

        return $ad->adv_age_updown > 0 ? min($total, (int) $ad->adv_age_updown) : $total;
    }

    public function fee(Advertisement $ad, array $payload): string
    {
        $category = $payload['category-relaxations'] ?? [];
        $caste = isset($category['caste_id']) ? DB::table('caste_tab')->where('caste_id', PublicId::decode('age-relaxation', $category['caste_id']))->first() : null;
        $ids = $this->matchedSections($category, $caste);
        $fee = (float) $ad->adv_fees;
        foreach (DB::table('advertisement_age_set')->where('advage_adv_master', $ad->adv_auto_genno)->orderBy('advage_id')->get() as $rule) {
            if (! in_array((int) $rule->advage_section, $ids, true)) {
                continue;
            }
            if ($rule->advage_feetype === 'No') {
                $fee = 0;
                break;
            }
            if ($rule->advage_feetype === 'Part') {
                $fee = (float) $rule->advage_partfee;
            }
        }

        return number_format($fee, 2, '.', '');
    }

    public function preview(CandidateUser $candidate, CandidateApplication $application): array
    {
        $errors = [];
        $payload = $application->payload;
        $ad = $this->advertisement($candidate);
        foreach (['post', 'personal-details', 'category-relaxations', 'qualifications', 'experiences'] as $section) {
            $validator = Validator::make($payload[$section] ?? [], CandidateApplicationRules::section($section, true));
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$section.'.'.$field] = $messages;
            }
        }
        if ($errors === []) {
            try {
                $errors = $this->semantic($candidate, $ad, $payload, true);
            } catch (ValidationException $e) {
                $errors = $e->errors();
            }
        }

        return ['application_number' => $candidate->f_application_no, 'version' => $application->version, 'state' => $application->state,
            'details' => $payload, 'errors' => $errors, 'can_submit' => $errors === [] && self::isOpen($ad) && $application->state === 'draft',
            'fee' => $application->fee ?? $this->fee($ad, $payload), 'payment_gateway_available' => false];
    }

    public function submit(CandidateUser $candidate, int $version, CandidateApplicationSubmission $writer): CandidateApplication
    {
        return DB::transaction(function () use ($candidate, $version, $writer): CandidateApplication {
            $ad = Advertisement::query()->where('adv_auto_genno', $candidate->f_applied_for)->lockForUpdate()->firstOrFail();
            $application = $this->application($candidate, true);
            if ($application->state !== 'draft') {
                return $application;
            }
            $this->editable($candidate, $application, $version);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE advertisement_categoty, advertisement_qualification, advertisement_experience, advertisement_age_set IN SHARE MODE');
            }
            $preview = $this->preview($candidate, $application);
            if ($preview['errors'] !== []) {
                throw ValidationException::withMessages($preview['errors']);
            }
            $application->fee = $preview['fee'];
            $application->state = (float) $application->fee === 0.0 ? 'complete' : 'payment_pending';
            $application->submitted_at = now('Asia/Kolkata')->format('Y-m-d H:i:s');
            $application->version++;
            $writer->write($candidate, $application);
            $application->save();

            return $application;
        }, 3);
    }
}
