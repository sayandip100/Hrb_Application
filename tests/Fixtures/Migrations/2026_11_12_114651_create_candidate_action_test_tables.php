<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE "checker_log" (
    "chklog_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "chklog_app_no" varchar(30) NOT NULL,
    "chklog_type" varchar(50) NOT NULL,
    "chklog_type_id" bigint,
    "chklog_user" bigint NOT NULL,
    "chklog_approval" varchar(30) NOT NULL,
    "chklog_msg" text NOT NULL,
    "chklog_createdate" timestamp without time zone NOT NULL,
    "apprv_doc_quali" varchar(255),
    "admin_apprv_date" timestamp without time zone,
    "admin_apprv_by" integer,
    "main_reason" varchar(455)
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "merit_list_tab" (
    "mr_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "mr_adv_master" varchar(30) NOT NULL,
    "mr_cand_app_no" varchar(30) NOT NULL,
    "mr_category" bigint NOT NULL,
    "mr_listing" smallint NOT NULL CHECK ("mr_listing" BETWEEN -128 AND 127),
    "mr_paneled" smallint DEFAULT 0 NOT NULL CHECK ("mr_paneled" BETWEEN -128 AND 127),
    "mr_panel_no" integer DEFAULT 1 NOT NULL,
    "mr_panelled_date" timestamp without time zone,
    "mr_createdate" timestamp without time zone NOT NULL,
    "mr_createby" bigint NOT NULL,
    "mr_status" smallint DEFAULT 1 NOT NULL CHECK ("mr_status" BETWEEN -128 AND 127)
);
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE "qe_swip_log" (
    "qes_id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "qes_type" varchar(20) NOT NULL,
    "qes_prev_set" bigint NOT NULL,
    "qes_new_set" bigint NOT NULL,
    "qes_createdate" timestamp without time zone NOT NULL,
    "qes_createby" bigint NOT NULL,
    "qes_status" smallint DEFAULT 1 NOT NULL CHECK ("qes_status" BETWEEN -128 AND 127)
);
SQL);
        (require database_path('migrations/2026_11_12_114650_create_candidate_admin_events_table.php'))->up();
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_admin_events');
        Schema::dropIfExists('qe_swip_log');
        Schema::dropIfExists('merit_list_tab');
        Schema::dropIfExists('checker_log');
    }
};
