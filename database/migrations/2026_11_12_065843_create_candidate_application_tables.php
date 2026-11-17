<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('candidate_uid')->unique();
            $table->foreign('candidate_uid')->references('f_uid')->on('frontend_users')->restrictOnDelete();
            $table->jsonb('payload');
            $table->unsignedInteger('version')->default(1);
            $table->string('state', 30)->default('draft');
            $table->decimal('fee', 12, 2)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('candidate_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('candidate_uid');
            $table->foreign('candidate_uid')->references('f_uid')->on('frontend_users')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->string('path', 250);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
            $table->index(['candidate_uid', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_documents');
        Schema::dropIfExists('candidate_applications');
    }
};
