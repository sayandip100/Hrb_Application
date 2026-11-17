<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('state_master')) {
            Schema::create('state_master', function (Blueprint $table): void {
                $table->increments('state_id');
                $table->string('state_name');
                $table->smallInteger('state_status')->default(1);
            });
        }
        (require database_path('migrations/2026_11_12_065843_create_candidate_application_tables.php'))->up();
    }

    public function down(): void
    {
        (require database_path('migrations/2026_11_12_065843_create_candidate_application_tables.php'))->down();
    }
};
