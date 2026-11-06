<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('district_master', function (Blueprint $table): void {
            $table->increments('district_id');
            $table->integer('state_id');
            $table->string('district_name', 100);
            $table->string('district_sortname', 20)->nullable();
            $table->string('district_code', 20);
            $table->dateTime('dist_createdate');
            $table->integer('dist_createby');
            $table->dateTime('dist_modifydate')->nullable();
            $table->integer('dist_modifyby')->nullable();
            $table->smallInteger('district_status')->default(1);
        });
        Schema::create('subdivision_tab', function (Blueprint $table): void {
            $table->increments('subdiv_id');
            $table->string('subdiv_district', 10);
            $table->string('subdiv_name', 150);
            $table->string('subdiv_code', 20)->nullable();
            $table->dateTime('subdiv_createdate');
            $table->integer('subdiv_createby');
            $table->dateTime('subdiv_modifydate')->nullable();
            $table->integer('subdiv_modifyby')->nullable();
            $table->smallInteger('subdiv_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subdivision_tab');
        Schema::dropIfExists('district_master');
    }
};
