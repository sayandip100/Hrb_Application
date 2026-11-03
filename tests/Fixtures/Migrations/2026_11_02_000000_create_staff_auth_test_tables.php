<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_user_type', function (Blueprint $table): void {
            $table->increments('mu_id');
            $table->string('mu_name');
            $table->dateTime('mu_createdate');
            $table->integer('mu_createby');
            $table->smallInteger('mu_status');
        });

        Schema::create('user_info', function (Blueprint $table): void {
            $table->bigIncrements('u_id');
            $table->integer('u_type');
            $table->string('mobile');
            $table->string('firstname');
            $table->string('lastname');
            $table->string('email')->nullable();
            $table->smallInteger('user_status');
            $table->string('otp_verify')->nullable();
            $table->smallInteger('otp_count')->default(0);
            $table->dateTime('otp_sendtime')->nullable();
            $table->date('entry_startdate')->nullable();
            $table->date('entry_enddate')->nullable();
            $table->time('entry_starttime')->nullable();
            $table->time('entry_endtime')->nullable();
            $table->string('user_mac_address')->nullable();
            $table->dateTime('user_createdate');
            $table->dateTime('modify_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_info');
        Schema::dropIfExists('master_user_type');
    }
};
