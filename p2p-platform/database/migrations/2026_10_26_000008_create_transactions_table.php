<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `transactions` — the transfer record and its state machine.
 *
 * `transactions.status` is a REBUILDABLE PROJECTION, not the authority. The
 * authority is the pair of ledger entries; the status can be reconstructed
 * from them at any time, and where the two disagree the LEDGER WINS
 * (epic-ledger-core R13).
 *
 * The `idempotency_key` is UNIQUE. It is what makes an at-least-once submit
 * safe: the same key submitted twice returns the first transaction rather
 * than creating a second one.
 *
 * No foreign key from `ledger_entries` back to this table carries a
 * referential action — the cascade prohibition extends here too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();

            // Human-facing reference, e.g. `P2P-8F3K-2201`.
            $table->string('reference', 32)->unique();

            $table->unsignedBigInteger('sender_account_id');
            $table->foreign('sender_account_id')->references('id')->on('ledger_accounts');

            $table->unsignedBigInteger('recipient_account_id')->nullable();
            $table->foreign('recipient_account_id')->references('id')->on('ledger_accounts');

            // The counterparty as presented to the user. Denormalised on
            // purpose: the display of a historic transfer must not change
            // when the counterparty later renames their account.
            $table->string('counterparty_name');
            $table->string('counterparty_account', 64);
            $table->string('method', 24);

            // Money. Integer Poisha only — 1 BDT = 100 Poisha. Never a float.
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->char('currency', 3)->default('BDT');

            $table->string('direction', 10);
            $table->string('status', 24)->default('CHAIN_PENDING');
            $table->string('risk_level', 10)->default('LOW');
            $table->text('note')->nullable();

            $table->string('idempotency_key', 64)->unique();

            $table->timestamp('chain_closed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversal_of_transaction_id')->nullable();
            $table->foreign('reversal_of_transaction_id')->references('id')->on('transactions');

            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
            $table->index(['sender_account_id', 'created_at']);
            $table->index(['recipient_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
