<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_master_tab', function (Blueprint $table): void {
            $table->increments('rm_id');
            $table->string('rm_name', 650);
            $table->string('rm_details', 650)->nullable();
            $table->dateTime('rm_createdate');
            $table->integer('rm_createby');
            $table->smallInteger('rm_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_master_tab');
    }
};
