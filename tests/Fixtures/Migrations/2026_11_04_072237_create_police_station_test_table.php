<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('police_station_tab', function (Blueprint $table): void {
            $table->bigIncrements('ps_id');
            $table->string('ps_dist_master', 10);
            $table->string('ps_name', 200);
            $table->string('ps_code', 20)->nullable();
            $table->dateTime('ps_createdate');
            $table->integer('ps_createby');
            $table->dateTime('ps_modifydate')->nullable();
            $table->integer('ps_modifyby')->nullable();
            $table->smallInteger('ps_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('police_station_tab');
    }
};
