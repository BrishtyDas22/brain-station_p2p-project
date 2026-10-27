<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Add Money" funding attempts.
 *
 * This table records a funding attempt and its simulated gateway outcome. It does **not**
 * hold a balance: the money lives in the main `wallets` table and is backed by
 * `ledger_entries`, exactly as every other movement of money in this system.
 *
 * The migration is purely additive. It creates one table, adds no trigger and drops
 * nothing. The `demo_payments` and `demo_balances` tables from the retired demo subsystem
 * are deliberately LEFT IN PLACE — they hold historical records that are preserved for
 * audit. Retiring them is a separate, explicitly-approved step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_fundings', function (Blueprint $table): void {
            $table->id();

            // The funding reference. Unique, server-generated, never accepted from a client.
            $table->string('reference', 32);

            // The paying user. The FK has NO referential action, matching the house rule.
            $table->unsignedBigInteger('owner_user_id');

            // The simulated gateway instrument.
            $table->enum('method', ['BKASH', 'NAGAD', 'BANK_TRANSFER', 'CARD']);

            // PENDING is the ONLY status a funding attempt is born in.
            $table->enum('status', ['PENDING', 'SUCCEEDED', 'FAILED', 'CANCELLED'])->default('PENDING');

            // Integer Poisha. Never a float, never a decimal.
            $table->unsignedBigInteger('amount_minor');

            $table->char('currency', 3)->default('BDT');

            // The reason a FAILED attempt carries.
            $table->string('failure_reason', 120)->nullable();

            // The minimal, storable form of the method's inputs. Never a real credential.
            $table->json('details')->nullable();

            // The ledger transaction the balanced entry pair belongs to. Null until the
            // funding succeeds, because a failed or cancelled attempt posts nothing.
            $table->unsignedBigInteger('transaction_id')->nullable();

            // The idempotency key. UNIQUE, so a replayed request cannot create a second
            // funding attempt.
            $table->string('idempotency_key', 64);

            $table->dateTime('settled_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique('reference', 'uq_wallet_fundings_reference');
            $table->unique('idempotency_key', 'uq_wallet_fundings_idempotency_key');
            $table->index('owner_user_id', 'ix_wallet_fundings_owner');
            $table->index('transaction_id', 'ix_wallet_fundings_transaction');

            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users');

            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions');
        });

        // Laravel's schema builder cannot express a CHECK constraint, so the currency
        // restriction is added as raw DDL.
        DB::statement(
            "ALTER TABLE `wallet_fundings`
              ADD CONSTRAINT `ck_wallet_fundings_currency` CHECK (`currency` = 'BDT')"
        );

        DB::statement(
            'ALTER TABLE `wallet_fundings`
              ADD CONSTRAINT `ck_wallet_fundings_amount` CHECK (`amount_minor` > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_fundings');
    }
};
