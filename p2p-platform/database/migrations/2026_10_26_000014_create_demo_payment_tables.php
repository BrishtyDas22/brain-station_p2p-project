<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The DEMO payment subsystem's two tables.
 *
 * Normative source: OpenSpec change `add-demo-payment-subsystem` design.md (D-1, D-4, D-5).
 *
 * ## Why these tables exist at all, and why they are separate
 *
 * The authoritative wallet balance (`wallets.balance_minor`) is a **projection of ledger
 * entries**. It may only become non-zero through a balanced, auditable pair of ledger
 * entries, which requires a SYSTEM ledger account — decision **H-1**, which is
 * 🟠 *Proposed (not approved)*. Decision **C-4** records the consequence: funding cannot be
 * modelled honestly yet.
 *
 * A demo balance is therefore NOT stored in `wallets`. It lives here, in its own table, with
 * its own money, and:
 *
 *  - it writes **no** `ledger_entries`;
 *  - it creates **no** SYSTEM ledger account and **no** funding entry pair;
 *  - it never touches `wallets.balance_minor` or `wallets.reserved_minor`;
 *  - it is read by no transfer, settlement, withdrawal or reversal code path.
 *
 * Because nothing outside this subsystem reads it, a demo balance can never become spendable
 * money. The isolation is the whole point.
 *
 * ## Notes that a later reader must not undo
 *
 *  - This migration is **purely additive**. It contains two `CREATE TABLE` statements and
 *    touches nothing else: no ALTER on `wallets`, `ledger_accounts` or `ledger_entries`, and
 *    no change to the three ledger triggers. The append-only guarantee on the ledger is
 *    untouched.
 *  - Every money column is `BIGINT UNSIGNED` integer Poisha (1 BDT = 100 Poisha). A negative
 *    demo balance is physically unrepresentable, not merely forbidden by application logic.
 *  - `uq_demo_balances_owner` gives one demo balance per user — the data-layer guarantee
 *    behind idempotent crediting.
 *  - `uq_demo_payments_reference` gives every demo payment a unique reference. The receipt and
 *    the history are keyed on it.
 *  - The foreign keys carry **no** ON DELETE / ON UPDATE referential action, matching the
 *    schema-wide rule already enforced for `wallets` and `ledger_accounts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_balances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('owner_user_id');

            // ISO-4217 shaped, BDT only — the same shape the real wallet uses.
            $table->char('currency', 3)->default('BDT');

            // Money: integer Poisha, unsigned. The column type is the negative-balance guard.
            $table->unsignedBigInteger('balance_minor')->default(0);

            // DATETIME(6), not TIMESTAMP, matching the rest of the schema.
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            // One demo balance per user.
            $table->unique('owner_user_id', 'uq_demo_balances_owner');

            // No ON DELETE / ON UPDATE referential action — deliberately omitted.
            $table->foreign('owner_user_id', 'fk_demo_balances_owner')->references('id')->on('users');
        });

        // Laravel's schema builder cannot express CHECK constraints; MySQL 8.0.16+ enforces
        // them natively.
        DB::statement(<<<'SQL'
            ALTER TABLE `demo_balances`
                ADD CONSTRAINT `ck_demo_balances_currency` CHECK (currency = 'BDT')
        SQL);

        Schema::create('demo_payments', function (Blueprint $table) {
            $table->id();

            // The unique demo payment reference. Generated server-side, never accepted from
            // a client. It appears on the receipt and in the history.
            $table->string('reference', 32);

            $table->unsignedBigInteger('owner_user_id');

            // The four simulated methods. No real gateway is ever contacted.
            $table->enum('method', ['BKASH', 'NAGAD', 'BANK_TRANSFER', 'CARD']);

            // The server-owned state machine. `PENDING` is the only state a payment is
            // created in; the server moves it to exactly one terminal state.
            $table->enum('status', ['PENDING', 'SUCCEEDED', 'FAILED', 'CANCELLED'])->default('PENDING');

            // Money: integer Poisha, unsigned.
            $table->unsignedBigInteger('amount_minor');

            $table->char('currency', 3)->default('BDT');

            // A short, fixed, non-sensitive reason recorded by the server. There is no
            // gateway, so there is no gateway response to store.
            $table->string('failure_reason', 120)->nullable();

            // The method-specific demo inputs, for the receipt's "what you entered"
            // line: the demo mobile number, the chosen demo bank and account, or the
            // fictional test card's last four digits.
            //
            // NEVER a real PIN, one-time password, CVV, bank credential or account
            // password: the request validation refuses those before they reach here,
            // and the card path stores only the fictional test card's last four digits.
            $table->json('details')->nullable();

            // Set only when the payment reaches a terminal state.
            $table->dateTime('settled_at', 6)->nullable();

            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique('reference', 'uq_demo_payments_reference');
            $table->index('owner_user_id', 'ix_demo_payments_owner');

            // No ON DELETE / ON UPDATE referential action — deliberately omitted.
            $table->foreign('owner_user_id', 'fk_demo_payments_owner')->references('id')->on('users');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `demo_payments`
                ADD CONSTRAINT `ck_demo_payments_currency` CHECK (currency = 'BDT')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_payments');
        Schema::dropIfExists('demo_balances');
    }
};
