<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisement_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('state', 20)->default('draft')->index();
            $table->jsonb('payload');
            $table->unsignedInteger('version')->default(1);
            $table->string('document_path', 250)->nullable();
            $table->unsignedBigInteger('submitted_advertisement_id')->nullable()->unique();
            $table->bigInteger('created_by');
            $table->bigInteger('updated_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->foreign('created_by')->references('u_id')->on('user_info')->restrictOnDelete();
            $table->foreign('updated_by')->references('u_id')->on('user_info')->restrictOnDelete();
            $table->foreign('submitted_advertisement_id')->references('adv_id')->on('advertisement_master')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisement_drafts');
    }
};
