<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_resubmit_tab" (
    "adv_resub_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "adv_resub_master" varchar(30) NOT NULL,
    "adv_resub_cat" integer,
    "adv_resub_starttime" timestamp without time zone NOT NULL,
    "adv_resub_endtime" timestamp without time zone NOT NULL,
    "adv_resub_reasons" text,
    "adv_resub_chk_stat" smallint DEFAULT 0 NOT NULL CHECK ("adv_resub_chk_stat" BETWEEN -128 AND 127),
    "adv_resub_chk_start" timestamp without time zone,
    "adv_resub_createdate" timestamp without time zone NOT NULL,
    "adv_resub_createby" bigint NOT NULL,
    "adv_resub_modifydate" timestamp without time zone,
    "adv_resub_modifyby" bigint,
    "adv_resub_status" smallint DEFAULT 1 NOT NULL CHECK ("adv_resub_status" BETWEEN -128 AND 127)
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "f_resubmit_log" (
    "fresub_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "fresub_cand_advno" varchar(30) NOT NULL,
    "fresub_cand_regno" varchar(30) NOT NULL,
    "fresub_createdate" timestamp without time zone NOT NULL
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "frontend_users" (
    "f_uid" INTEGER PRIMARY KEY AUTOINCREMENT,
    "f_applied_for" varchar(30) NOT NULL,
    "f_application_no" varchar(30) NOT NULL,
    "f_mobile" varchar(20) NOT NULL,
    "f_set_pass" smallint DEFAULT 0 CHECK ("f_set_pass" BETWEEN -128 AND 127),
    "f_setpass_datetime" timestamp without time zone,
    "f_password" varchar(255),
    "f_full_name" varchar(255) NOT NULL,
    "f_email" varchar(200) NOT NULL,
    "f_email_otp" varchar(10),
    "f_otp" varchar(10),
    "f_otp_count" integer DEFAULT 0 NOT NULL,
    "f_otp_sendtime" timestamp without time zone,
    "f_registered" smallint DEFAULT 0 NOT NULL CHECK ("f_registered" BETWEEN -128 AND 127),
    "f_createdate" timestamp without time zone NOT NULL,
    "f_modifydate" timestamp without time zone,
    "f_rename_doc" varchar(255),
    "f_status" smallint DEFAULT 1 NOT NULL CHECK ("f_status" BETWEEN -128 AND 127),
    "f_rename_reason" varchar(255)
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "f_user_details" (
    "fu_details_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "fu_master" bigint NOT NULL,
    "fu_court_ref_no" varchar(100),
    "fu_category" bigint,
    "fu_step_1" smallint DEFAULT 0 NOT NULL CHECK ("fu_step_1" BETWEEN -128 AND 127),
    "fu_step1_datetime" timestamp without time zone,
    "fu_father_name" varchar(250),
    "fu_mother_name" varchar(250),
    "fu_dob" date,
    "fu_dob_doc" varchar(250),
    "fu_gender" varchar(50),
    "fu_marital_status" varchar(50),
    "fu_read_write_bengali" text CHECK ("fu_read_write_bengali" IN ('Yes', 'No')),
    "fu_state" integer,
    "fu_address" varchar(400),
    "fu_district" integer,
    "fu_sub_division" bigint,
    "fu_mb_type" text CHECK ("fu_mb_type" IN ('Block', 'Municipality')),
    "fu_block_municipality" bigint,
    "fu_police_station" bigint,
    "fu_other_district" varchar(200),
    "fu_other_sdiv" varchar(200),
    "fu_other_blockm" varchar(200),
    "fu_other_ps" varchar(200),
    "fu_ward_gp" varchar(200),
    "fu_house_road" varchar(250),
    "fu_post_office" varchar(200),
    "fu_pincode" varchar(10),
    "fu_same_address" text CHECK ("fu_same_address" IN ('Yes', 'No')),
    "fu_perma_state" integer,
    "fu_perma_dist" integer,
    "fu_perma_sub_division" bigint,
    "fu_perma_mb_type" text CHECK ("fu_perma_mb_type" IN ('Block', 'Municipality')),
    "fu_perma_block_municipality" bigint,
    "fu_perma_police_station" bigint,
    "fu_perma_other_district" varchar(200),
    "fu_perma_other_sdiv" varchar(200),
    "fu_perma_other_blockm" varchar(200),
    "fu_perma_other_ps" varchar(200),
    "fu_perma_ward_gp" varchar(200),
    "fu_perma_house_road" varchar(250),
    "fu_perma_post_office" varchar(200),
    "fu_perma_pincode" varchar(10),
    "fu_comunication_address" text CHECK ("fu_comunication_address" IN ('Present', 'Permanent')),
    "fu_address_doc" varchar(250),
    "fu_photo_doc" varchar(250),
    "fu_signature_doc" varchar(250),
    "fu_step_2" smallint DEFAULT 0 NOT NULL CHECK ("fu_step_2" BETWEEN -128 AND 127),
    "fu_step2_submitdate" timestamp without time zone,
    "fu_caste" text CHECK ("fu_caste" IN ('Yes', 'No')),
    "fu_caste_type" integer,
    "fu_caste_community" bigint,
    "fu_caste_number" varchar(150),
    "fu_caste_issue_whom" bigint,
    "fu_caste_issue_date" date,
    "fu_caste_doc" varchar(250),
    "fu_pwd" text CHECK ("fu_pwd" IN ('Yes', 'No')),
    "fu_pwd_percent" varchar(50),
    "fu_pwd_issue_whom" varchar(250),
    "fu_pwd_issue_date" date,
    "fu_pwd_doc" varchar(250),
    "fu_exempted" text CHECK ("fu_exempted" IN ('Yes', 'No')),
    "fu_exc_reason" varchar(300),
    "fu_exc_doc" varchar(250),
    "fu_exservice" text CHECK ("fu_exservice" IN ('Yes', 'No')),
    "fu_exs_reason" varchar(300),
    "fu_exs_doc" varchar(250),
    "fu_ews" text CHECK ("fu_ews" IN ('Yes', 'No')),
    "fu_ews_reason" varchar(300),
    "fu_ews_doc" varchar(250),
    "fu_step_3" smallint DEFAULT 0 NOT NULL CHECK ("fu_step_3" BETWEEN -128 AND 127),
    "fu_step3_submitdate" timestamp without time zone,
    "fu_age_relax" text CHECK ("fu_age_relax" IN ('Yes', 'No')),
    "fu_age_relax_reason" varchar(300),
    "fu_age_relax_doc" varchar(250),
    "fu_ext_council_name" varchar(250),
    "fu_ext_council_regno" varchar(150),
    "fu_ext_council_reg_certificate" varchar(250),
    "fu_has_service" text CHECK ("fu_has_service" IN ('Yes', 'No')),
    "fu_qualification_total" integer,
    "fu_experience_total" integer,
    "fu_step_4" smallint DEFAULT 0 NOT NULL CHECK ("fu_step_4" BETWEEN -128 AND 127),
    "fu_step4_submitdate" timestamp without time zone,
    "fu_final_createdate" timestamp without time zone,
    "fu_final_submit" smallint DEFAULT 0 NOT NULL CHECK ("fu_final_submit" BETWEEN -128 AND 127),
    "fu_finalsubmit_date" timestamp without time zone,
    "fu_pay_amount" double precision,
    "fu_payment_stat" smallint DEFAULT 0 NOT NULL CHECK ("fu_payment_stat" BETWEEN -128 AND 127),
    "fu_payment_date" timestamp without time zone,
    "fu_cancel_stat" smallint DEFAULT 0 NOT NULL CHECK ("fu_cancel_stat" BETWEEN -128 AND 127),
    "fu_canceldate" timestamp without time zone,
    "f_c_r_doc" varchar(255),
    "m_c_r_doc" varchar(255),
    "f_rename_resn" varchar(255),
    "m_rename_resn" varchar(255),
    "f_edit" integer DEFAULT 0
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "candidate_result_tab" (
    "cr_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "cr_application_master" varchar(30) NOT NULL,
    "cr_approval" text DEFAULT 'NotChecked' NOT NULL CHECK ("cr_approval" IN ('Approved', 'Rejected', 'Doubtful', 'NotChecked')),
    "cr_fprocess_date" timestamp without time zone,
    "cr_reject_comments" text,
    "cr_reject_document" varchar(200),
    "fu_dob_check" text CHECK ("fu_dob_check" IN ('Approved', 'Rejected')),
    "fu_address_check" text CHECK ("fu_address_check" IN ('Approved', 'Rejected')),
    "fu_photo_check" text CHECK ("fu_photo_check" IN ('Approved', 'Rejected')),
    "fu_signature_check" text CHECK ("fu_signature_check" IN ('Approved', 'Rejected')),
    "fu_caste_check" text CHECK ("fu_caste_check" IN ('Approved', 'Rejected', 'NotApplicable')),
    "fu_pwd_check" text CHECK ("fu_pwd_check" IN ('Approved', 'Rejected', 'NotApplicable')),
    "fu_exempted_check" text CHECK ("fu_exempted_check" IN ('Approved', 'Rejected', 'NotApplicable')),
    "fu_exservice_check" text CHECK ("fu_exservice_check" IN ('Approved', 'Rejected', 'NotApplicable')),
    "fu_ews_check" text CHECK ("fu_ews_check" IN ('Approved', 'Rejected', 'NotApplicable')),
    "fu_age_relax_check" text DEFAULT 'No' NOT NULL CHECK ("fu_age_relax_check" IN ('Yes', 'No')),
    "fu_es_qualification_check" text DEFAULT 'No' NOT NULL CHECK ("fu_es_qualification_check" IN ('Yes', 'No')),
    "fu_es_service_check" text DEFAULT 'No' NOT NULL CHECK ("fu_es_service_check" IN ('Yes', 'No')),
    "fu_ds_qualification_check" text DEFAULT 'No' NOT NULL CHECK ("fu_ds_qualification_check" IN ('Yes', 'No')),
    "fu_ds_service_check" text DEFAULT 'No' NOT NULL CHECK ("fu_ds_service_check" IN ('Yes', 'No')),
    "cr_academic" double precision DEFAULT 0.00 NOT NULL,
    "cr_experience" double precision DEFAULT 0.00 NOT NULL,
    "cr_interview_1" double precision DEFAULT 0.00 NOT NULL,
    "cr_interview_2" double precision DEFAULT 0.00 NOT NULL,
    "cr_written" double precision DEFAULT 0.00 NOT NULL,
    "cr_total_marks" double precision DEFAULT 0.00 NOT NULL,
    "cr_language_known" varchar(300),
    "cr_admitcard_issued" smallint DEFAULT 0 NOT NULL CHECK ("cr_admitcard_issued" BETWEEN -128 AND 127),
    "cr_admitcard_no" varchar(100),
    "cr_admitcard_date" timestamp without time zone,
    "cr_interview_pic" varchar(250),
    "cr_interview_pic_datetime" timestamp without time zone,
    "cr_interview_2_date" timestamp without time zone,
    "cr_written_exam_date" date,
    "cr_status" smallint DEFAULT 0 NOT NULL CHECK ("cr_status" BETWEEN -128 AND 127),
    "cr_empanel" smallint DEFAULT 0 NOT NULL CHECK ("cr_empanel" BETWEEN -128 AND 127),
    "cbt_num_flag" integer,
    "cbt_int_flag" integer DEFAULT 0,
    "abs_stat" integer DEFAULT 0,
    "pwd_stat" integer DEFAULT 0,
    "mt_stat" integer DEFAULT 0
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "checking_tab" (
    "chk_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "chk_user_application" varchar(30) NOT NULL,
    "chk_type" varchar(100) NOT NULL,
    "chk_sub_typeid" bigint,
    "chk_approve" text CHECK ("chk_approve" IN ('Approved', 'Rejected', 'Doubtful', 'Skip', 'Return')),
    "chk_comments" varchar(300),
    "chk_createdate" timestamp without time zone NOT NULL,
    "chk_appro_date" timestamp without time zone,
    "chk_createby" bigint,
    "chk_create_by_type" integer NOT NULL,
    "chk2_approve" text CHECK ("chk2_approve" IN ('Approved', 'Rejected', 'Doubtful', 'Skip', 'Return')),
    "chk2_comments" varchar(300),
    "chk2_appro_date" timestamp without time zone,
    "chk2_appro_by" bigint,
    "chkadm_approve" text CHECK ("chkadm_approve" IN ('Approved', 'Rejected')),
    "chkadm_comments" varchar(300),
    "chkadm_appro_date" timestamp without time zone,
    "chkadm_appro_by" bigint,
    "chk_status" smallint DEFAULT 1 NOT NULL CHECK ("chk_status" BETWEEN -128 AND 127),
    "chk_final_state" text CHECK ("chk_final_state" IN ('Approved', 'Rejected', 'Doubtful', 'Skip', 'Return')),
    "chk_got_marks" double precision DEFAULT 0.00 NOT NULL,
    "admin_apprv_date" timestamp without time zone,
    "admin_apprv_by" integer
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "f_user_extraage" (
    "fu_ext_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "fu_ext_masteruser" bigint NOT NULL,
    "fu_ext_ageid" integer NOT NULL,
    "fu_ext_answer" text CHECK ("fu_ext_answer" IN ('No', 'Yes')),
    "fu_ext_reason" text,
    "fu_ext_doc" varchar(250),
    "fu_ext_createdate" timestamp without time zone NOT NULL,
    "fu_ext_modifydate" timestamp without time zone,
    "fu_ext_status" smallint DEFAULT 1 NOT NULL CHECK ("fu_ext_status" BETWEEN -128 AND 127)
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "f_user_qualification" (
    "fu_quali_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "fu_quali_masteruser" bigint,
    "fu_qualifiaction_name" integer,
    "fu_council_board" varchar(200),
    "fu_state_of_passing" integer,
    "fu_full_marks" double precision,
    "fu_marks_obtained" double precision,
    "fu_percent_of_marks" double precision,
    "fu_fullmark_ck" double precision,
    "fu_obtainmark_ck" double precision,
    "fu_percentmark_ck" double precision,
    "fu_mark_modifyby" bigint,
    "fu_mark_modifydate" timestamp without time zone,
    "fu_is_attempt" text CHECK ("fu_is_attempt" IN ('No', 'Yes')),
    "fu_attempt_no" integer,
    "fu_marksheet_issuedate" date,
    "fu_quali_docs" varchar(250),
    "fu_is_pursuing" text CHECK ("fu_is_pursuing" IN ('No', 'Yes')),
    "fu_quali_createdate" timestamp without time zone NOT NULL,
    "fu_quali_status" smallint DEFAULT 1 NOT NULL CHECK ("fu_quali_status" BETWEEN -128 AND 127),
    "fu_attempt_no_ck" integer,
    "fu_is_attempt_ck" text CHECK ("fu_is_attempt_ck" IN ('No', 'Yes'))
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "f_user_des_qualification" (
    "fud_quali_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "fud_quali_masteruser" bigint NOT NULL,
    "fud_qualifiaction_name" integer,
    "fud_council_board" varchar(200),
    "fud_state_of_passing" integer,
    "fud_full_marks" double precision,
    "fud_marks_obtained" double precision,
    "fud_percent_of_marks" double precision,
    "fud_fullmark_ck" double precision,
    "fud_obtainmark_ck" double precision,
    "fud_percentmark_ck" double precision,
    "fud_mark_modifyby" bigint,
    "fud_mark_modifydate" timestamp without time zone,
    "fud_is_attempt" text CHECK ("fud_is_attempt" IN ('No', 'Yes')),
    "fud_attempt_no" integer,
    "fud_marksheet_issuedate" date,
    "fud_quali_docs" varchar(250),
    "fud_quali_createdate" timestamp without time zone NOT NULL,
    "fud_quali_status" smallint DEFAULT 1 NOT NULL CHECK ("fud_quali_status" BETWEEN -128 AND 127),
    "fud_is_attempt_ck" text CHECK ("fud_is_attempt_ck" IN ('No', 'Yes')),
    "fud_attempt_no_ck" integer
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "f_user_ess_experience" (
    "fues_exp_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "fues_exp_masteruser" bigint NOT NULL,
    "fues_exp_workname" bigint NOT NULL,
    "fues_exp_org_name" varchar(200) NOT NULL,
    "fues_exp_year" integer NOT NULL,
    "fues_exp_month" integer NOT NULL,
    "fues_exp_yr_ck" integer,
    "fues_exp_mth_ck" integer,
    "fues_exp_modifydate" timestamp without time zone,
    "fues_exp_modifyby" bigint,
    "fues_exp_marksheet_doc" varchar(250) NOT NULL,
    "fues_exp_createdate" timestamp without time zone NOT NULL,
    "fues_exp_serial" integer NOT NULL,
    "fues_exp_approval" text CHECK ("fues_exp_approval" IN ('Approved', 'Rejected')),
    "fues_exp_status" smallint DEFAULT 1 NOT NULL CHECK ("fues_exp_status" BETWEEN -128 AND 127)
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "f_user_experience" (
    "fu_exp_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "fu_exp_masteruser" bigint NOT NULL,
    "fu_exp_workname" bigint NOT NULL,
    "fu_exp_org_name" varchar(200) NOT NULL,
    "fu_exp_year" integer NOT NULL,
    "fu_exp_month" integer NOT NULL,
    "fu_exp_yr_ck" integer,
    "fu_exp_mth_ck" integer,
    "fu_exp_modifydate" timestamp without time zone,
    "fu_exp_modifyby" bigint,
    "fu_exp_marksheet_doc" varchar(250) NOT NULL,
    "fu_exp_createdate" timestamp without time zone NOT NULL,
    "fu_exp_approval" text CHECK ("fu_exp_approval" IN ('Approved', 'Rejected')),
    "fu_exp_status" smallint DEFAULT 1 NOT NULL CHECK ("fu_exp_status" BETWEEN -128 AND 127),
    "re_doc_upload_stat" integer DEFAULT 1 NOT NULL
);
SQL);
        DB::statement('CREATE VIEW f_user_views AS SELECT f.*, d.* FROM frontend_users f JOIN f_user_details d ON d.fu_master = f.f_uid');
        (require database_path('migrations/2026_11_06_114611_create_advertisement_resubmission_audit_tables.php'))->up();
    }

    public function down(): void
    {
        (require database_path('migrations/2026_11_06_114611_create_advertisement_resubmission_audit_tables.php'))->down();
        DB::statement('DROP VIEW IF EXISTS f_user_views');
        Schema::dropIfExists('f_user_experience');
        Schema::dropIfExists('f_user_ess_experience');
        Schema::dropIfExists('f_user_des_qualification');
        Schema::dropIfExists('f_user_qualification');
        Schema::dropIfExists('f_user_extraage');
        Schema::dropIfExists('checking_tab');
        Schema::dropIfExists('candidate_result_tab');
        Schema::dropIfExists('f_user_details');
        Schema::dropIfExists('frontend_users');
        Schema::dropIfExists('f_resubmit_log');
        Schema::dropIfExists('advertisement_resubmit_tab');
    }
};
