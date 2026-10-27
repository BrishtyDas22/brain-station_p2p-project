<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `audit_outbox` — Epic 1 story 7 (R15).
 *
 * The outbox carries audit INTENT atomically with money movement: a row is
 * written in the SAME transaction as the ledger entries, so an event can
 * never be moved without its audit intent being recorded, and vice versa.
 *
 * It carries SCALAR IDENTIFIERS ONLY and NO FOREIGN KEY TO ANY HOT-PATH
 * TABLE. This is deliberate and load-bearing:
 *
 *   A foreign key to `transactions`, `wallets`, `ledger_accounts` or
 *   `ledger_entries` would make the outbox insert participate in the money
 *   path's lock ordering. That is precisely how a lock-order inversion — and
 *   therefore a deadlock — gets introduced between the hot path and the audit
 *   path.
 *
 *   The identifiers are therefore plain columns. Referential integrity is
 *   maintained by the drain worker (Epic 5), which runs off the hot path and
 *   may resolve the identifiers as late as it likes.
 *
 * The drain worker and the `audit_log` chain are Epic 5 and are NOT built by
 * this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_outbox', function (Blueprint $table): void {
            $table->id();

            // Scalar identifiers only. No FK to any hot-path table.
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('event_type', 64);
            $table->string('actor', 64);
            $table->json('payload')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('drained_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->index('drained_at');
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_outbox');
    }
};
