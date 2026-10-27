<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the `PAYOUT` role to `ledger_accounts.system_role`.
 *
 * ## Why this migration exists
 *
 * The PRD's "Withdraw Money" scenario requires the borrower's wallet to be able to pay
 * money OUT to a third-party destination (bKash / Nagad / Bank). That payout needs a
 * counterparty account in the ledger — the paired CREDIT that balances the wallet's
 * DEBIT — and that counterparty is not the same thing as any account that already exists:
 *
 *  - `FUNDING` is an external **source**. Money enters the platform through it. It is
 *    debited, never credited, and its `balance_after` is the cumulative amount sourced.
 *  - `ESCROW` is **custody** of real user money that is still inside the platform.
 *  - `PAYOUT` is an external **sink**. Money leaves the platform through it. It is
 *    credited, never debited, and its `balance_after` is the cumulative amount paid out.
 *
 * `FUNDING` and `PAYOUT` are the two halves of "the outside world's wallet": what comes
 * in, and what goes out. Without `PAYOUT`, a withdrawal would have to credit the
 * `FUNDING` account, which would mean recording a payout as if the platform had received
 * money — the exact opposite of what happened.
 *
 * ## Why extending the enum is safe and non-destructive
 *
 * MySQL's `ENUM` is stored as a small integer, and **appending** a new member does not
 * renumber the existing ones. `FUNDING` (1), `TREASURY` (2) and `ESCROW` (3) keep their
 * values, so every existing row — including the live `FUNDING` and `ESCROW` accounts in
 * the dev database — is untouched by this migration. Only new rows can take the new value.
 *
 * The `ck_ledger_accounts_type` CHECK constraint already reads `system_role IS NOT NULL`
 * for SYSTEM accounts, so it accepts `PAYOUT` without modification.
 *
 * **H-1 (SYSTEM accounts) is 🟠 Proposed and not approved.** This migration does not
 * approve it. The `PAYOUT` account is a sandbox sink for a simulated payout: no real
 * money leaves the platform, and no provider API is contacted. It exists because the PRD
 * requires the balance to stay intact when a simulated payout happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MODIFY, not CHANGE: the column keeps its position and its NULL/default
        // behaviour, and the existing enum members keep their integer values.
        DB::statement(
            "ALTER TABLE `ledger_accounts`
              MODIFY COLUMN `system_role` ENUM('FUNDING','TREASURY','ESCROW','PAYOUT') NULL DEFAULT NULL"
        );
    }

    public function down(): void
    {
        // A row already using PAYOUT cannot be represented by the narrower enum, so the
        // rollback is refused by MySQL rather than silently destroying the role. That
        // refusal is the correct behaviour: it is the database saying the data depends
        // on this migration.
        DB::statement(
            "ALTER TABLE `ledger_accounts`
              MODIFY COLUMN `system_role` ENUM('FUNDING','TREASURY','ESCROW') NULL DEFAULT NULL"
        );
    }
};
