<?php

namespace App\Domain\Identity;

use App\Models\AdvertisementDraft;
use Illuminate\Support\Facades\DB;

class AdvertisementSubmission
{
    /** Writes the legacy tables inside the caller's submission transaction. */
    public function write(AdvertisementDraft $draft, array $payload, int $staffId): int
    {
        $now = now('Asia/Kolkata')->format('Y-m-d H:i:s');
        $reference = $draft->reference;
        $id = DB::table('advertisement_master')->insertGetId([
            'adv_auto_genno' => $reference,
            'adv_no' => $payload['advertisement_number'],
            'adv_recruit_master' => $payload['recruitment_id'],
            'adv_type' => $payload['type'],
            'adv_start_time' => $payload['starts_at'], 'adv_end_time' => $payload['ends_at'],
            'adv_old_starttime' => $payload['type'] === 'Old' ? $payload['old_starts_at'] : null,
            'adv_old_endtime' => $payload['type'] === 'Old' ? $payload['old_ends_at'] : null,
            'adv_app_temp_job' => $payload['temporary_appointment'], 'adv_scale_pay' => $payload['pay_scale'],
            'adv_total_recruit' => $payload['total_vacancies'], 'adv_category_no' => count($payload['disciplines']),
            'adv_min_age_limit' => $payload['dob_to'], 'adv_age_limit' => $payload['dob_from'],
            'adv_age_updown' => $payload['age_relaxation_years'], 'adv_age_writeup' => $payload['age_writeup'] ?? null,
            'adv_has_experience' => $payload['has_experience'], 'adv_fees' => $payload['fee'],
            'adv_pwd_percent' => $payload['pwd_percent'], 'adv_dictation_set' => $payload['dictation'],
            'adv_lang_writeup' => $payload['dictation'] === 'Yes' ? $payload['language_writeup'] : null,
            'adv_essen_qualification' => $payload['essential_writeup'] ?? null,
            'adv_desir_qualification' => $payload['desirable_writeup'] ?? null,
            'adv_marks_writeup' => $payload['marks_writeup'] ?? null,
            'adv_miscellenius' => $payload['miscellaneous_writeup'] ?? null,
            'adv_disability' => $payload['disability_writeup'] ?? null,
            'adv_qualification_no' => $this->essentialGroupCount($payload['qualifications']),
            'adv_experience_no' => $this->essentialGroupCount($payload['experiences'] ?? []),
            'adv_source_doc' => $draft->document_path,
            'adv_createdate' => $now, 'adv_createby' => $draft->created_by,
            'adv_modifydate' => $now, 'adv_modify_by' => $staffId,
            'adv_status' => 1, 'adv_activated' => 0, 'draft_stat' => 1,
        ], 'adv_id');
        DB::table('advertisement_marks')->insert([
            'amark_adv_master' => $reference, 'amark_academic' => $payload['marks']['academic'],
            'amark_experience' => $payload['marks']['experience'], 'amark_interview' => $payload['marks']['interview'],
            'amark_written' => $payload['marks']['written'], 'amrk_createdate' => $now,
        ]);
        foreach ($payload['disciplines'] as $row) {
            $values = ['acat_adv_master' => $reference, 'acat_name' => $row['discipline_id'],
                'acat_gender_set' => $row['gender'], 'acat_marital_set' => $row['marital_status'],
                'acat_total' => array_sum($row['vacancies']), 'acat_createdate' => $now];
            foreach (AdvertisementPayload::VACANCIES as $category) {
                $values['acat_'.$category] = $row['vacancies'][$category] ?? 0;
            }
            DB::table('advertisement_categoty')->insert($values);
        }
        foreach ($payload['age_fee_rules'] ?? [] as $row) {
            DB::table('advertisement_age_set')->insert([
                'advage_adv_master' => $reference, 'advage_section' => $row['section_id'],
                'advage_type' => $row['relationship'], 'advage_up' => $row['years'],
                'advage_feetype' => $row['fee_type'],
                'advage_partfee' => $row['fee_type'] === 'Part' ? $row['partial_fee'] : null,
                'advage_createdate' => $now,
            ]);
        }
        foreach ($payload['qualifications'] as $row) {
            $qualificationId = DB::table('advertisement_qualification')->insertGetId([
                'aquali_adv_master' => $reference, 'aquali_exam' => $row['qualification_id'],
                'aquali_examtype' => $row['type'], 'aquali_finalexam' => $row['final_qualification'],
                'aquali_pursuing_chk' => $row['allow_pursuing'], 'aquali_marks' => $row['maximum_marks'],
                'aquali_relation' => $row['relationship'], 'aquali_category' => $row['distribution'],
                'aquali_attempt' => $row['attempt_type'],
                'aquali_fullpercent' => in_array($row['attempt_type'], ['Full', 'Percent'], true) ? $row['attempt_marks'] : null,
                'aquali_createdate' => $now, 'aquali_status' => 1,
            ], 'aquali_id');
            if ($row['distribution'] === 'Slab') {
                foreach ($row['slabs'] as $slab) {
                    DB::table('advertisement_quali_details')->insert([
                        'aq_qualification_ms' => $qualificationId, 'aq_detail_score_lvl' => $slab['threshold'],
                        'aq_detail_score_mark' => $slab['marks'], 'aq_detail_crdate' => $now,
                    ]);
                }
            }
            if ($row['attempt_type'] === 'Slab') {
                foreach ($row['deductions'] as $slab) {
                    DB::table('advertisement_deduct_details')->insert([
                        'aq_deduction_ms' => $qualificationId, 'aq_deduct_lvl' => $slab['attempt'],
                        'aq_deduct_mark' => $slab['marks'], 'aq_deduct_crdate' => $now,
                    ]);
                }
            }
        }
        foreach ($payload['experiences'] ?? [] as $row) {
            $experienceId = DB::table('advertisement_experience')->insertGetId([
                'aexpr_adv_master' => $reference, 'aexpr_name' => $row['experience_id'],
                'aexpr_type' => $row['type'], 'aexpr_relation' => $row['relationship'],
                'aexpr_marks' => $row['maximum_marks'], 'aexpr_min_month' => $row['minimum_months'],
                'aexpr_category' => $row['distribution'], 'aexpr_createdate' => $now, 'aexpr_status' => 1,
            ], 'aexpr_id');
            if ($row['distribution'] === 'Slab') {
                foreach ($row['slabs'] as $slab) {
                    DB::table('advertisement_exp_details')->insert([
                        'ae_experience_ms' => $experienceId, 'ae_range_words' => $slab['operator'],
                        'ae_detail_month' => $slab['months'], 'ae_detail_mark' => $slab['marks'], 'ae_detail_cr_date' => $now,
                    ]);
                }
            }
        }

        return (int) $id;
    }

    private function essentialGroupCount(array $rows): int
    {
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['type'] === 'Essential'));
        if ($rows === []) {
            return 0;
        }

        return 1 + count(array_filter($rows, fn (array $row): bool => $row['relationship'] === 'AND'));
    }
}
