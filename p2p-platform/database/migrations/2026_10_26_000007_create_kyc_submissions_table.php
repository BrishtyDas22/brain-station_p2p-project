<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `kyc_submissions`.
 *
 * One row per user. The submission is the identity record; its status is
 * advanced by an authorised administrator and every status change is written
 * to `audit_records` (Epic 5) by the service layer.
 *
 * The table carries NO foreign key with a referential action, matching the
 * project's cascade prohibition: a cascade from `users` would silently destroy
 * compliance history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_submissions', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');

            $table->string('status', 20)->default('PENDING');

            // Identity details as submitted.
            $table->string('full_name');
            $table->string('document_type', 40);
            $table->string('document_number');
            $table->date('date_of_birth');
            $table->string('address_line');
            $table->string('city', 80);
            $table->string('country', 2)->default('BD');
            $table->string('mobile_number', 20);

            // Review outcome.
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();

            $table->timestamps();

            $table->unique('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_submissions');
    }
};
