<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `reversals` — the one-shot reversal request record.
 *
 * A reversal is a REQUEST plus its decision. The actual money movement is a
 * compensating ledger entry, which Epic 3 owns; this table records the
 * decision and points at the compensating entry once one exists.
 *
 * `UNIQUE (transaction_id)` is the duplicate-reversal guard: a second
 * reversal request against the same transaction is refused by the database,
 * not by a check in application code that could race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reversals', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('transactions');

            $table->unsignedBigInteger('requested_by');
            $table->foreign('requested_by')->references('id')->on('users');

            $table->unsignedBigInteger('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('users');

            $table->string('status', 16)->default('REQUESTED');
            $table->text('reason');

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('BDT');

            // The compensating entry, once the reversal has been executed.
            $table->string('compensating_entry_id', 26)->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            // The one-shot guard.
            $table->unique('transaction_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reversals');
    }
};
