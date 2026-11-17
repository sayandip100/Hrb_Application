<?php

namespace App\Domain\Identity;

use App\Models\CandidateApplication;
use App\Models\CandidateUser;
use Illuminate\Support\Facades\DB;

class CandidateApplicationSubmission
{
    public function __construct(private CandidateApplicationService $service) {}

    public function write(CandidateUser $candidate, CandidateApplication $application): void
    {
        $data = $application->payload;
        $personal = $data['personal-details'];
        $category = $data['category-relaxations'];
        $now = now('Asia/Kolkata')->format('Y-m-d H:i:s');
        $path = fn (?string $id, string $purpose): ?string => $id ? $this->service->document($candidate, $id, $purpose)->path : null;
        $details = ['fu_category' => PublicId::decode('advertisement-post', $data['post']['post_id']),
            'fu_step_1' => 1, 'fu_step_2' => 1, 'fu_step_3' => 1, 'fu_step_4' => 1, 'fu_final_submit' => 1,
            'fu_step1_datetime' => $now, 'fu_step2_submitdate' => $now, 'fu_step3_submitdate' => $now, 'fu_step4_submitdate' => $now,
            'fu_finalsubmit_date' => $now, 'fu_final_createdate' => $now, 'fu_pay_amount' => $application->fee,
            'fu_payment_stat' => $application->state === 'complete' ? 1 : 0, 'fu_payment_date' => $application->state === 'complete' ? $now : null,
            'fu_cancel_stat' => 0, 'fu_qualification_total' => count($data['qualifications']['items']),
            'fu_experience_total' => count($data['experiences']['items']), 'fu_has_service' => count($data['experiences']['items']) > 0 ? 'Yes' : 'No'];
        foreach (['dob' => 'fu_dob', 'father_name' => 'fu_father_name', 'mother_name' => 'fu_mother_name', 'gender' => 'fu_gender',
            'marital_status' => 'fu_marital_status', 'read_write_bengali' => 'fu_read_write_bengali', 'present_address' => 'fu_address',
            'permanent_address' => 'fu_perma_house_road', 'present_pincode' => 'fu_pincode', 'permanent_pincode' => 'fu_perma_pincode',
            'council_name' => 'fu_ext_council_name', 'council_number' => 'fu_ext_council_regno'] as $field => $column) {
            $details[$column] = $personal[$field] ?? null;
        }
        foreach (['present' => 'fu_', 'permanent' => 'fu_perma_'] as $address => $prefix) {
            foreach (['state' => ['state', 'state'], 'district' => ['district', 'dist'], 'subdivision' => ['subdivision', 'sub_division'],
                'block_municipality' => ['block-municipality', 'block_municipality'], 'police_station' => ['police-station', 'police_station']] as $field => [$type,$column]) {
                $target = $address === 'present' && $field === 'district' ? 'fu_district' : $prefix.$column;
                $details[$target] = isset($personal[$address.'_'.$field.'_id']) ? PublicId::decode($type, $personal[$address.'_'.$field.'_id']) : null;
            }
            $details[$prefix.'post_office'] = $personal[$address.'_post_office'] ?? null;
            $details[$prefix.'ward_gp'] = $personal[$address.'_ward_gp'] ?? null;
        }
        $details['fu_ext_council_reg_certificate'] = $path($personal['council_document_id'] ?? null, 'council');
        foreach (['photo', 'signature', 'address', 'dob'] as $purpose) {
            $details['fu_'.$purpose.'_doc'] = $path($personal[$purpose.'_document_id'], $purpose);
        }
        $details['fu_caste_type'] = PublicId::decode('age-relaxation', $category['caste_id']);
        $details['fu_caste'] = $details['fu_caste_type'] === 1 ? 'No' : 'Yes';
        $details['fu_caste_community'] = isset($category['caste_community_id']) ? PublicId::decode('caste-community', $category['caste_community_id']) : null;
        $details['fu_caste_issue_whom'] = isset($category['issuing_authority_id']) ? PublicId::decode('caste-issuing-authority', $category['issuing_authority_id']) : null;
        $details['fu_caste_number'] = $category['caste_certificate_number'] ?? null;
        $details['fu_caste_issue_date'] = $category['caste_certificate_date'] ?? null;
        $details['fu_caste_doc'] = $path($category['caste_document_id'] ?? null, 'caste');
        $details['fu_pwd_percent'] = $category['pwd_percent'] ?? null;
        foreach (['pwd' => 'pwd', 'exempted' => 'exc', 'exservice' => 'exs', 'ews' => 'ews'] as $field => $prefix) {
            $details['fu_'.$field] = $category[$field];
            $details['fu_'.$prefix.'_doc'] = $path($category[$field.'_document_id'] ?? null, $field);
        }
        $details['fu_age_relax'] = count($category['age_relaxations'] ?? []) > 0 ? 'Yes' : 'No';
        DB::table('f_user_details')->where('fu_master', $candidate->f_uid)->update($details);
        foreach ($category['age_relaxations'] ?? [] as $claim) {
            DB::table('f_user_extraage')->insert(['fu_ext_masteruser' => $candidate->f_uid, 'fu_ext_ageid' => PublicId::decode('age-relaxation', $claim['section_id']),
                'fu_ext_answer' => 'Yes', 'fu_ext_reason' => $claim['reason'] ?? null, 'fu_ext_doc' => $path($claim['document_id'], 'age-relaxation'), 'fu_ext_createdate' => $now, 'fu_ext_status' => 1]);
        }
        foreach ($data['qualifications']['items'] as $item) {
            $option = DB::table('advertisement_qualification')->where('aquali_id', PublicId::decode('advertisement-qualification', $item['option_id']))->first();
            $essential = $option->aquali_examtype === 'Essential';
            $prefix = $essential ? 'fu' : 'fud';
            $row = [$prefix.'_quali_masteruser' => $candidate->f_uid, $prefix.'_qualifiaction_name' => $option->aquali_exam, $prefix.'_council_board' => $item['board'],
                $prefix.'_full_marks' => $item['full_marks'], $prefix.'_marks_obtained' => $item['marks_obtained'],
                $prefix.'_percent_of_marks' => round($item['marks_obtained'] * 100 / $item['full_marks'], 4), $prefix.'_is_attempt' => $item['attempts'] > 1 ? 'Yes' : 'No',
                $prefix.'_attempt_no' => $item['attempts'], $prefix.'_marksheet_issuedate' => $item['issue_date'], $prefix.'_quali_docs' => $path($item['document_id'], 'qualification'),
                $prefix.'_quali_createdate' => $now, $prefix.'_quali_status' => 1];
            if ($essential) {
                $row['fu_is_pursuing'] = $item['pursuing'];
            }
            DB::table($essential ? 'f_user_qualification' : 'f_user_des_qualification')->insert($row);
        }
        foreach ($data['experiences']['items'] as $i => $item) {
            $option = DB::table('advertisement_experience')->where('aexpr_id', PublicId::decode('advertisement-experience', $item['option_id']))->first();
            $essential = $option->aexpr_type === 'Essential';
            $prefix = $essential ? 'fues' : 'fu';
            $row = [$prefix.'_exp_masteruser' => $candidate->f_uid, $prefix.'_exp_workname' => $option->aexpr_name, $prefix.'_exp_org_name' => $item['organization'],
                $prefix.'_exp_year' => $item['years'], $prefix.'_exp_month' => $item['months'], $prefix.'_exp_marksheet_doc' => $path($item['document_id'], 'experience'), $prefix.'_exp_createdate' => $now, $prefix.'_exp_status' => 1];
            if ($essential) {
                $row['fues_exp_serial'] = $i + 1;
            }
            DB::table($essential ? 'f_user_ess_experience' : 'f_user_experience')->insert($row);
        }
    }
}
