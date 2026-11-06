<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisement_resubmission_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('resubmission_id')->primary();
            $table->foreign('resubmission_id', 'resub_batch_window_fk')->references('adv_resub_id')->on('advertisement_resubmit_tab')->restrictOnDelete();
            $table->unsignedInteger('eligible_candidates');
            $table->unsignedInteger('processed_candidates')->nullable();
            $table->unsignedInteger('deleted_checks')->nullable();
            $table->dateTime('created_at');
        });
        Schema::create('advertisement_resubmission_candidates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resubmission_id');
            $table->foreign('resubmission_id', 'resub_candidate_batch_fk')->references('resubmission_id')->on('advertisement_resubmission_batches')->restrictOnDelete();
            $table->string('application_no', 30);
            $table->unsignedBigInteger('candidate_uid');
            $table->string('email', 200)->nullable();
            $table->jsonb('snapshot');
            $table->string('notification_status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('created_at');
            $table->unique(['resubmission_id', 'application_no'], 'resub_window_application_unique');
            $table->index(['notification_status', 'id'], 'resub_notification_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisement_resubmission_candidates');
        Schema::dropIfExists('advertisement_resubmission_batches');
    }
};
