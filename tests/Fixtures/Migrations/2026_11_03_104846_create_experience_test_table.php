<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_master_tab', function (Blueprint $table): void {
            $table->bigIncrements('expset_id');
            $table->string('expset_name', 21844);
            $table->string('expset_details', 250)->nullable();
            $table->dateTime('expset_createdate');
            $table->bigInteger('expset_createby');
            $table->dateTime('expset_modifydate')->nullable();
            $table->bigInteger('expset_modifyby')->nullable();
            $table->smallInteger('expset_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_master_tab');
    }
};
