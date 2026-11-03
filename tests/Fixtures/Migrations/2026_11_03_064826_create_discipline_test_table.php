<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_master', function (Blueprint $table): void {
            $table->bigIncrements('catm_id');
            $table->string('catm_name', 150);
            $table->integer('catm_master')->nullable();
            $table->string('catm_details', 250)->nullable();
            $table->dateTime('catm_createdate');
            $table->integer('catm_createby');
            $table->smallInteger('catm_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_master');
    }
};
