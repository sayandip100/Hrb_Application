<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caste_tab', function (Blueprint $table): void {
            $table->increments('caste_id');
            $table->string('caste_name', 21844);
            $table->smallInteger('caste_cat')->nullable();
            $table->integer('caste_parent')->nullable();
            $table->integer('caste_attach')->nullable();
            $table->dateTime('caste_createdate');
            $table->integer('caste_createby');
            $table->smallInteger('caste_status')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caste_tab');
    }
};
