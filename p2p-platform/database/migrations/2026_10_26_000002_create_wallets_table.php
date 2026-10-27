<?php

use App\Support\MySqlTriggerSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The money-holding table — the only mutable balance in the system.
 *
 * Normative source: architecture-p2p-settlement.md §`wallets`, and OpenSpec change
 * `add-ledger-schema-foundation` design.md (D-1, D-2, D-4, D-6, D-7, D-8, D-9).
 *
 * Notes that a later reader must not undo:
 *
 *  - `balance_minor` and `reserved_minor` are `BIGINT UNSIGNED` integer Poisha
 *    (1 BDT = 100 Poisha). A negative balance is physically unrepresentable, not merely
 *    forbidden by application logic — the column type is the constraint, so no redundant
 *    `CHECK (balance_minor >= 0)` is needed.
 *  - `ck_wallets_reserved_within_balance` keeps `reserved_minor <= balance_minor` at all
 *    times. Both columns live on one row, so the invariant is expressible as a CHECK,
 *    and it is re-evaluated on every UPDATE. Consequence for the transfer path: the
 *    payer's balance debit and its reserved decrement must be written so that no
 *    intermediate statement violates the constraint.
 *  - `account_id` is NOT NULL and its binding is immutable. Both foreign keys carry
 *    **no** ON DELETE / ON UPDATE referential action, so deleting an account that has a
 *    wallet fails loudly instead of silently orphaning or re-pointing rows whose
 *    `account_id` sits inside the hashed body of every ledger entry. A BEFORE UPDATE
 *    trigger additionally refuses an explicit re-point, which the foreign key alone
 *    would permit.
 *  - `wallet.id` and `account_id` are NOT assumed to have identical numeric values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('account_id');

            // ISO-4217 shaped so a future multi-currency version needs no type change.
            $table->char('currency', 3)->default('BDT');

            // Money: integer Poisha, unsigned. The column type is the negative-balance guard.
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->unsignedBigInteger('reserved_minor')->default(0);

            // DATETIME(6), not TIMESTAMP: hashed timestamps must re-read byte-identically.
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            // The hard 1:1 enforcement — one account backs at most one wallet.
            $table->unique('account_id', 'uq_wallets_account');
            $table->unique(['owner_user_id', 'currency'], 'uq_wallets_owner_currency');
            $table->index('owner_user_id', 'ix_wallets_owner');

            // No ON DELETE / ON UPDATE referential action — deliberately omitted.
            $table->foreign('owner_user_id', 'fk_wallets_owner')->references('id')->on('users');
            $table->foreign('account_id', 'fk_wallets_account')->references('id')->on('ledger_accounts');
        });

        // Laravel's schema builder cannot express CHECK constraints, so they are added
        // as raw DDL. MySQL 8.0.16+ enforces CHECK natively.
        DB::statement(<<<'SQL'
            ALTER TABLE `wallets`
                ADD CONSTRAINT `ck_wallets_reserved_within_balance` CHECK (reserved_minor <= balance_minor)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `wallets`
                ADD CONSTRAINT `ck_wallets_currency` CHECK (currency = 'BDT')
        SQL);

        // The wallet↔account binding is immutable. `account_id` sits inside the hashed
        // body of every ledger entry, so re-pointing a wallet would change the meaning
        // of historical hashes.
        //
        // The foreign key alone does NOT prevent this: an explicit UPDATE to a
        // different *valid* account satisfies referential integrity and would
        // otherwise succeed. A BEFORE UPDATE trigger makes the immutability a
        // data-layer guarantee rather than a convention — the write is rejected by
        // the database and the original binding is preserved.
        //
        // This trigger is scoped to `account_id` alone. Balances are mutable by
        // design — they are the projection the ledger reconciles against.
        //
        // **Fail-closed.** This trigger enforces a financial-integrity invariant, so
        // its installation is MANDATORY. If the server refuses to create it, the
        // migration aborts with a LedgerTriggerException rather than leaving a
        // `wallets` table whose immutability guarantee is not enforced. A schema
        // that looks correct while enforcing nothing is worse than no schema.
        //
        // MySQL DDL is not transactional, so the `wallets` table created above
        // would survive a failure here. The table is therefore dropped on the
        // failure path: an aborted migration must leave NO `wallets` table, not a
        // half-built one missing its immutability guarantee.
        try {
            MySqlTriggerSupport::install(
                'trg_wallets_account_id_immutable',
                'wallets',
                <<<'SQL'
                    CREATE TRIGGER `trg_wallets_account_id_immutable`
                    BEFORE UPDATE ON `wallets`
                    FOR EACH ROW
                    BEGIN
                        IF NEW.account_id <> OLD.account_id THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'wallets.account_id is immutable';
                        END IF;
                    END
                SQL
            );
        } catch (Throwable $e) {
            Schema::dropIfExists('wallets');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
