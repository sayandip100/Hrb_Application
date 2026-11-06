<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caste_issuing_auth_tab', function (Blueprint $table): void {
            $table->bigIncrements('cia_id');
            $table->string('cia_name', 250);
            $table->dateTime('cia_createdate');
            $table->bigInteger('cia_createby');
            $table->dateTime('cia_modifydate')->nullable();
            $table->bigInteger('cia_modifyby')->nullable();
            $table->smallInteger('cia_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caste_issuing_auth_tab');
    }
};
