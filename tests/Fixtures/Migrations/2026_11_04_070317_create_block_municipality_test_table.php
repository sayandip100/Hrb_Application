<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_master', function (Blueprint $table): void {
            $table->increments('block_id');
            $table->integer('district_id');
            $table->integer('subd_id')->nullable();
            $table->string('block_name', 100);
            $table->string('block_code', 20)->nullable();
            $table->string('block_type', 20)->nullable();
            $table->dateTime('block_createdate');
            $table->integer('block_createby');
            $table->dateTime('block_modifydate')->nullable();
            $table->integer('block_modifyby')->nullable();
            $table->smallInteger('block_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_master');
    }
};
