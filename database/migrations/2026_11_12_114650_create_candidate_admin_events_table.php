<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_admin_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('candidate_uid');
            $table->foreign('candidate_uid')->references('f_uid')->on('frontend_users')->restrictOnDelete();
            $table->unsignedBigInteger('staff_id');
            $table->string('action', 40);
            $table->text('reason');
            $table->jsonb('before');
            $table->jsonb('after');
            $table->dateTime('created_at');
            $table->index(['candidate_uid', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_admin_events');
    }
};
