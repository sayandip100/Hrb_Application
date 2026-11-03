<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualification_master', function (Blueprint $table): void {
            $table->increments('qm_id');
            $table->string('qm_name', 850);
            $table->integer('qm_r_master')->nullable();
            $table->string('qm_details', 250)->nullable();
            $table->dateTime('qm_createdate');
            $table->integer('qm_createby');
            $table->smallInteger('qm_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_master');
    }
};
