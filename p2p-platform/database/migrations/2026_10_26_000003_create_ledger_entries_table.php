<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only double-entry ledger — the table the money is actually derived from.
 *
 * Normative source: `../P2P_SPECIFICATION.md` §3.1, §4.1, §4.2, §4.4, §5.1, §5.2;
 * architecture-p2p-settlement.md §`ledger_entries`; OpenSpec change
 * `add-ledger-entry-table` design.md.
 *
 * Notes that a later reader must not undo:
 *
 *  - `amount_minor` and `balance_after` are `BIGINT UNSIGNED` integer Poisha
 *    (1 BDT = 100 Poisha). `ck_ledger_entries_amount` requires a **strictly positive**
 *    amount: a zero-amount entry is not a degenerate transfer, it is an accounting
 *    error, and the database rejects it.
 *  - `uq_ledger_entries_account_sequence` over `(account_id, account_sequence_number)`
 *    is the data-layer half of the gapless-per-account guarantee (SPEC §4.4). Two
 *    entries for one account can never share a sequence number. The *gap* itself is
 *    prevented by the allocator that increments the counter inside the posting
 *    transaction — that is story 4, not this one.
 *  - `uq_ledger_entries_entry_hash` makes a duplicate hash a hard error. In practice
 *    it means a collision or a duplicated insert; both are bugs worth failing loudly
 *    on rather than storing two rows attesting to the same content.
 *  - `balance_after` is nullable and is **not** part of the chained body (SPEC §5.2):
 *    it is not independently verifiable, because nobody can recompute it under
 *    concurrency. `ck_ledger_entries_balance` only forbids a negative value. The
 *    entries are the authority; `wallets.balance_minor` is the projection.
 *  - The foreign key to `ledger_accounts` carries **no** ON DELETE / ON UPDATE
 *    referential action, deliberately. The ledger is append-only: cascading a delete
 *    from a parent would silently destroy ledger history, which is the one thing this
 *    table exists to prevent.
 *  - `created_at` is `DATETIME(6)` — microsecond precision, because it is inside the
 *    hashed body and must not be truncated between write and verify.
 *
 * **Two documented omissions.** Neither is a design choice; both are recorded here and
 * in the change's tasks so they are not mistaken for decisions:
 *
 *  1. **No `status` column.** H-6 / D1 is 🟠 Proposed (not approved). The column is
 *     carried by a separate, reversible migration
 *     (`2026_10_26_000004_add_status_to_ledger_entries_table`) that is deliberately
 *     **not applied**. The absence of a column is the cheaper error to reverse: a
 *     later migration can add a nullable column, whereas adding a column that was
 *     supposed to be inside the hashed body changes every historical hash.
 *  2. **No foreign key to `transactions(id)`.** That table belongs to Epic 3 and does
 *     not exist, and MySQL cannot create a foreign key to a non-existent table. The
 *     `transaction_id` column and its index are created exactly as specified, so Epic 3
 *     adds the constraint as a pure constraint addition with no data movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            // A ULID, not an auto-increment (G1 / A-019 ⚙️ PENDING). The id sits inside
            // the hashed body, and an auto-increment id would leak transfer volume and
            // timing to anyone who can read the table.
            //
            // The column carries the table's default collation, which is
            // case-INsensitive — see the model's docblock for the hazard and the
            // conventional fix. The architecture spine does not fix a collation, so
            // this does not silently choose one.
            $table->char('entry_id', 26)->primary();

            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('account_id');

            $table->enum('direction', ['DEBIT', 'CREDIT']);

            // Money: integer Poisha, unsigned. The column type is the negative-amount
            // guard; `ck_ledger_entries_amount` additionally forbids zero.
            $table->unsignedBigInteger('amount_minor');

            // ISO-4217 shaped so a future multi-currency version needs no type change.
            $table->char('currency', 3)->default('BDT');

            // The account's gapless sequence position (SPEC §4.4).
            $table->unsignedBigInteger('account_sequence_number');

            // Non-authoritative, excluded from the chained body (SPEC §5.2).
            $table->unsignedBigInteger('balance_after')->nullable();

            // Lowercase hex SHA-256, 32 bytes.
            $table->char('prev_hash', 64);
            $table->char('entry_hash', 64);

            // DATETIME(6), not TIMESTAMP: hashed timestamps must re-read byte-identically.
            $table->dateTime('created_at', 6);

            // The gapless-per-account guarantee: a duplicate sequence number for one
            // account is impossible at the data layer.
            $table->unique(
                ['account_id', 'account_sequence_number'],
                'uq_ledger_entries_account_sequence'
            );

            // A duplicate hash is a hard error, not a tolerated state.
            $table->unique('entry_hash', 'uq_ledger_entries_entry_hash');

            // The double-entry scan (Invariant 1).
            $table->index(['transaction_id', 'direction'], 'ix_ledger_entries_transaction');

            // The per-account timeline and the by-year reconciliation scan (Invariant 3).
            $table->index(['account_id', 'created_at'], 'ix_ledger_entries_account_created');

            // No ON DELETE / ON UPDATE referential action — deliberately omitted.
            // The `transactions` foreign key is deferred to Epic 3; see the class
            // docblock.
            $table->foreign('account_id', 'fk_ledger_entries_account')
                ->references('id')
                ->on('ledger_accounts');
        });

        // Laravel's schema builder cannot express CHECK constraints, so they are added
        // as raw DDL. MySQL 8.0.16+ enforces CHECK natively.
        DB::statement(<<<'SQL'
            ALTER TABLE `ledger_entries`
                ADD CONSTRAINT `ck_ledger_entries_amount` CHECK (amount_minor > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `ledger_entries`
                ADD CONSTRAINT `ck_ledger_entries_currency` CHECK (currency = 'BDT')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `ledger_entries`
                ADD CONSTRAINT `ck_ledger_entries_balance`
                CHECK (balance_after IS NULL OR balance_after >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
