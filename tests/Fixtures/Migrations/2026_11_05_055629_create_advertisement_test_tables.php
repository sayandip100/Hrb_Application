<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        (require database_path('migrations/2026_11_05_055608_create_advertisement_drafts_table.php'))->up();
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_master" (
    "adv_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "adv_auto_genno" varchar(30) NOT NULL,
    "adv_no" varchar(650) NOT NULL,
    "adv_recruit_master" integer NOT NULL,
    "adv_start_time" timestamp without time zone NOT NULL,
    "adv_end_time" timestamp without time zone NOT NULL,
    "adv_type" text DEFAULT 'New' NOT NULL CHECK ("adv_type" IN ('New', 'Old')),
    "adv_old_starttime" timestamp without time zone,
    "adv_old_endtime" timestamp without time zone,
    "adv_app_temp_job" text,
    "adv_scale_pay" text NOT NULL,
    "adv_total_recruit" integer NOT NULL,
    "adv_category_no" integer NOT NULL,
    "adv_min_age_limit" date NOT NULL,
    "adv_age_limit" date NOT NULL,
    "adv_age_writeup" text,
    "adv_age_updown" smallint DEFAULT 0 NOT NULL CHECK ("adv_age_updown" BETWEEN -128 AND 127),
    "adv_fees" double precision DEFAULT 0.00 NOT NULL,
    "adv_payment_mode" varchar(100),
    "adv_gender_set" varchar(200),
    "adv_marital_set" varchar(200),
    "adv_pwd_percent" integer DEFAULT 0 NOT NULL,
    "adv_has_exampted" text DEFAULT 'No' NOT NULL CHECK ("adv_has_exampted" IN ('Yes', 'No')),
    "adv_has_exservice" text DEFAULT 'No' NOT NULL CHECK ("adv_has_exservice" IN ('Yes', 'No')),
    "adv_has_ews" text DEFAULT 'No' NOT NULL CHECK ("adv_has_ews" IN ('Yes', 'No')),
    "adv_has_experience" text NOT NULL CHECK ("adv_has_experience" IN ('Yes', 'No')),
    "adv_reg_certificate" text CHECK ("adv_reg_certificate" IN ('Yes', 'No')),
    "adv_qualification_modify" text DEFAULT 'Yes' NOT NULL CHECK ("adv_qualification_modify" IN ('Yes', 'No')),
    "adv_marks_writeup" text,
    "adv_qualification_no" integer NOT NULL,
    "adv_experience_no" integer NOT NULL,
    "adv_essen_qualification" text,
    "adv_desir_qualification" text,
    "adv_miscellenius" text,
    "adv_disability" text,
    "adv_lang_writeup" text,
    "adv_source_doc" varchar(250) NOT NULL,
    "adv_dictation_set" text DEFAULT 'Yes' NOT NULL CHECK ("adv_dictation_set" IN ('Yes', 'No')),
    "adv_createdate" timestamp without time zone NOT NULL,
    "adv_createby" integer NOT NULL,
    "adv_modifydate" timestamp without time zone,
    "adv_modify_by" integer,
    "adv_status" smallint DEFAULT 1 NOT NULL CHECK ("adv_status" BETWEEN -128 AND 127),
    "adv_activated" smallint DEFAULT 0 NOT NULL CHECK ("adv_activated" BETWEEN -128 AND 127),
    "draft_stat" integer,
    "is_cbt" integer
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_marks" (
    "amark_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "amark_adv_master" varchar(30) NOT NULL,
    "amark_academic" double precision DEFAULT 0.00 NOT NULL,
    "amark_experience" double precision DEFAULT 0.00 NOT NULL,
    "amark_interview" double precision DEFAULT 0.00 NOT NULL,
    "amark_written" double precision DEFAULT 0.00 NOT NULL,
    "amrk_createdate" timestamp without time zone NOT NULL
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_categoty" (
    "acat_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "acat_adv_master" varchar(30) NOT NULL,
    "acat_name" integer NOT NULL,
    "acat_gender_set" varchar(200) NOT NULL,
    "acat_marital_set" varchar(200) NOT NULL,
    "acat_ur" integer DEFAULT 0 NOT NULL,
    "acat_sc" integer DEFAULT 0 NOT NULL,
    "acat_st" integer DEFAULT 0 NOT NULL,
    "acat_obc" integer DEFAULT 0 NOT NULL,
    "acat_obc_a" integer DEFAULT 0 NOT NULL,
    "acat_obc_b" integer DEFAULT 0 NOT NULL,
    "acat_pwd" integer DEFAULT 0 NOT NULL,
    "acat_exc" integer DEFAULT 0 NOT NULL,
    "acat_exs" integer DEFAULT 0 NOT NULL,
    "acat_ews" integer DEFAULT 0 NOT NULL,
    "acat_ews_ec" integer DEFAULT 0 NOT NULL,
    "acat_ews_g_c" integer DEFAULT 0 NOT NULL,
    "acat_ews_g_d" integer DEFAULT 0 NOT NULL,
    "acat_ur_ec" integer DEFAULT 0 NOT NULL,
    "acat_ur_g_c" integer DEFAULT 0 NOT NULL,
    "acat_ur_g_d" integer DEFAULT 0 NOT NULL,
    "acat_ur_sp" integer DEFAULT 0 NOT NULL,
    "acat_sc_ec" integer DEFAULT 0 NOT NULL,
    "acat_sc_g_c" integer DEFAULT 0 NOT NULL,
    "acat_sc_g_d" integer DEFAULT 0 NOT NULL,
    "acat_st_ec" integer DEFAULT 0 NOT NULL,
    "acat_st_g_d" integer DEFAULT 0 NOT NULL,
    "acat_obc_a_ec" integer DEFAULT 0 NOT NULL,
    "acat_obc_a_g_d" integer DEFAULT 0 NOT NULL,
    "acat_obc_b_ec" integer DEFAULT 0 NOT NULL,
    "acat_obc_b_g_d" integer DEFAULT 0 NOT NULL,
    "acat_scpwd" integer DEFAULT 0 NOT NULL,
    "acat_total" integer DEFAULT 0 NOT NULL,
    "reason_change" varchar(255),
    "acat_createdate" timestamp without time zone NOT NULL
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_qualification" (
    "aquali_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "aquali_adv_master" varchar(30) NOT NULL,
    "aquali_exam" integer NOT NULL,
    "aquali_examtype" text NOT NULL CHECK ("aquali_examtype" IN ('Essential', 'Desirable')),
    "aquali_marks" double precision NOT NULL,
    "aquali_relation" text NOT NULL CHECK ("aquali_relation" IN ('AND', 'OR', 'END')),
    "aquali_category" text NOT NULL CHECK ("aquali_category" IN ('Full', 'Percent', 'Slab')),
    "aquali_attempt" text NOT NULL CHECK ("aquali_attempt" IN ('No', 'Full', 'Percent', 'Slab')),
    "aquali_fullpercent" double precision,
    "aquali_finalexam" text DEFAULT 'No' NOT NULL CHECK ("aquali_finalexam" IN ('Yes', 'No')),
    "aquali_fexam_order" smallint DEFAULT 0 NOT NULL CHECK ("aquali_fexam_order" BETWEEN -128 AND 127),
    "aquali_pursuing_chk" text DEFAULT 'No' NOT NULL CHECK ("aquali_pursuing_chk" IN ('Yes', 'No')),
    "aquali_createdate" timestamp without time zone NOT NULL,
    "aquali_status" smallint DEFAULT 1 NOT NULL CHECK ("aquali_status" BETWEEN -128 AND 127)
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_quali_details" (
    "aq_detail_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "aq_qualification_ms" bigint NOT NULL,
    "aq_detail_score_lvl" integer NOT NULL,
    "aq_detail_score_mark" double precision NOT NULL,
    "aq_detail_crdate" timestamp without time zone NOT NULL
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_deduct_details" (
    "aq_deduct_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "aq_deduction_ms" bigint NOT NULL,
    "aq_deduct_lvl" integer NOT NULL,
    "aq_deduct_mark" double precision NOT NULL,
    "aq_deduct_crdate" timestamp without time zone NOT NULL
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_experience" (
    "aexpr_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "aexpr_adv_master" varchar(30) NOT NULL,
    "aexpr_name" bigint NOT NULL,
    "aexpr_type" text NOT NULL CHECK ("aexpr_type" IN ('Essential', 'Desirable')),
    "aexpr_marks" double precision NOT NULL,
    "aexpr_relation" text NOT NULL CHECK ("aexpr_relation" IN ('AND', 'OR', 'END')),
    "aexpr_min_month" integer NOT NULL,
    "aexpr_category" text NOT NULL CHECK ("aexpr_category" IN ('Full', 'Slab')),
    "aexpr_createdate" timestamp without time zone NOT NULL,
    "aexpr_status" smallint DEFAULT 1 NOT NULL CHECK ("aexpr_status" BETWEEN -128 AND 127)
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_exp_details" (
    "ae_detail_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "ae_experience_ms" bigint NOT NULL,
    "ae_range_words" varchar(30) NOT NULL,
    "ae_detail_month" integer NOT NULL,
    "ae_detail_mark" double precision NOT NULL,
    "ae_detail_cr_date" timestamp without time zone NOT NULL
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "advertisement_age_set" (
    "advage_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "advage_adv_master" varchar(30) NOT NULL,
    "advage_section" integer NOT NULL,
    "advage_type" text NOT NULL CHECK ("advage_type" IN ('AND', 'OR', 'END')),
    "advage_up" integer NOT NULL,
    "advage_feetype" text NOT NULL CHECK ("advage_feetype" IN ('Full', 'Part', 'No', 'NA')),
    "advage_partfee" double precision,
    "advage_year" varchar(10),
    "advage_createdate" timestamp without time zone NOT NULL
);
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisement_drafts');
        Schema::dropIfExists('advertisement_age_set');
        Schema::dropIfExists('advertisement_exp_details');
        Schema::dropIfExists('advertisement_experience');
        Schema::dropIfExists('advertisement_deduct_details');
        Schema::dropIfExists('advertisement_quali_details');
        Schema::dropIfExists('advertisement_qualification');
        Schema::dropIfExists('advertisement_categoty');
        Schema::dropIfExists('advertisement_marks');
        Schema::dropIfExists('advertisement_master');
    }
};
