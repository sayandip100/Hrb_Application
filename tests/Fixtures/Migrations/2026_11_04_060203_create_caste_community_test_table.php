<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caste_details_tab', function (Blueprint $table): void {
            $table->bigIncrements('csdetail_id');
            $table->integer('csdetail_master');
            $table->string('csdetail_name', 450);
            $table->dateTime('csdetail_createdate');
            $table->bigInteger('csdetail_createby');
            $table->dateTime('csdetail_modifydate')->nullable();
            $table->bigInteger('csdetail_modifyby')->nullable();
            $table->smallInteger('csdetail_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caste_details_tab');
    }
};
