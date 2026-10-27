<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger-side account — the account half of the 1:1 wallet↔account mapping.
 *
 * Normative source: architecture-p2p-settlement.md §`ledger_accounts`, and OpenSpec change
 * `add-ledger-schema-foundation` design.md (D-1, D-2, D-3, D-6, D-7, D-8).
 *
 * Notes that a later reader must not undo:
 *
 *  - There is deliberately **no balance column** on this table. C-2 forbids a second
 *    independently mutable balance field on the account; the balance lives on `wallets`.
 *  - `next_sequence_number` is a **per-account sequence allocator**, NOT a balance. It is
 *    reconciled by sequence continuity, not by balance reconstruction.
 *  - `owner_user_id` is nullable **only** to accommodate H-1 (🟠 Proposed, not approved).
 *    Multiple SYSTEM accounts are therefore permitted — MySQL allows repeated NULLs in a
 *    unique index — while each user still gets at most one account.
 *  - The foreign key carries **no** ON DELETE / ON UPDATE referential action. A cascade
 *    could silently re-point a wallet whose `account_id` sits inside the hashed body of
 *    every ledger entry. Deletion must fail loudly.
 *  - H-1 is 🟠 Proposed (not approved). This schema accommodates it; it does not adopt it.
 *    No SYSTEM account is created and no funding path exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();

            // Nullable only to accommodate H-1 (SYSTEM accounts). See the class docblock.
            $table->unsignedBigInteger('owner_user_id')->nullable();

            $table->enum('account_type', ['USER', 'SYSTEM'])->default('USER');
            $table->enum('system_role', ['FUNDING', 'TREASURY'])->nullable();

            // ISO-4217 shaped so a future multi-currency version needs no type change.
            $table->char('currency', 3)->default('BDT');

            // A per-account sequence allocator, NOT a balance.
            $table->unsignedBigInteger('next_sequence_number')
                ->default(1)
                ->comment('Per-account sequence allocator, not a balance. Allocated atomically per account_id.');

            // DATETIME(6), not TIMESTAMP: hashed timestamps must re-read byte-identically.
            $table->dateTime('created_at', 6);

            // One account per user. The nullable column is what lets multiple SYSTEM
            // accounts exist while each user still gets at most one account.
            $table->unique('owner_user_id', 'uq_ledger_accounts_owner');
            $table->index('system_role', 'ix_ledger_accounts_system_role');

            // No ON DELETE / ON UPDATE referential action — deliberately omitted.
            $table->foreign('owner_user_id', 'fk_ledger_accounts_owner')->references('id')->on('users');
        });

        // Laravel's schema builder cannot express CHECK constraints, so they are added
        // as raw DDL. MySQL 8.0.16+ enforces CHECK natively.
        DB::statement(<<<'SQL'
            ALTER TABLE `ledger_accounts`
                ADD CONSTRAINT `ck_ledger_accounts_type` CHECK (
                    (account_type = 'USER'   AND owner_user_id IS NOT NULL AND system_role IS NULL) OR
                    (account_type = 'SYSTEM' AND owner_user_id IS NULL     AND system_role IS NOT NULL)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `ledger_accounts`
                ADD CONSTRAINT `ck_ledger_accounts_currency` CHECK (currency = 'BDT')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
