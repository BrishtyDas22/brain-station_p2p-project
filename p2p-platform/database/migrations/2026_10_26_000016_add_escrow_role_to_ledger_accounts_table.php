<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the `ESCROW` role to `ledger_accounts.system_role`.
 *
 * ## Why this migration exists
 *
 * The PRD's escrow mechanism (§"Escrow Holding Mechanism") requires a dedicated account
 * that holds lender capital **in custody** between the moment a lender commits funds and
 * the moment the loan is disbursed to the borrower. That account is not the same thing as
 * the sandbox `FUNDING` account:
 *
 *  - `FUNDING` is an **external source** of money. It is debited, never credited, and its
 *    `balance_after` records the cumulative amount it has sourced.
 *  - `ESCROW` is **custody** of real user money. It is credited when a lender commits
 *    capital and debited when that capital is disbursed to a borrower or returned to a
 *    lender. Every entry touching it is backed by a real user wallet debit or credit —
 *    no money appears from an unexplained opening balance.
 *
 * ## Why extending the enum is safe and non-destructive
 *
 * MySQL's `ENUM` is stored as a small integer, and **appending** a new member does not
 * renumber the existing ones. `FUNDING` (1) and `TREASURY` (2) keep their values, so
 * every existing row — including the live `FUNDING` account in the dev database — is
 * untouched by this migration. Only new rows can take the new value.
 *
 * The `ck_ledger_accounts_type` CHECK constraint already reads `system_role IS NOT NULL`
 * for SYSTEM accounts, so it accepts `ESCROW` without modification.
 *
 * **H-1 (SYSTEM accounts) is 🟠 Proposed and not approved.** This migration does not
 * approve it. It widens the vocabulary of an enum that already exists so the PRD's
 * escrow mechanism can be implemented; the sandbox funding policy was separately
 * authorised and documented in the OpenSpec change `add-sandbox-wallet-funding`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MODIFY, not CHANGE: the column keeps its position and its NULL/default
        // behaviour, and the existing enum members keep their integer values.
        DB::statement(
            "ALTER TABLE `ledger_accounts`
              MODIFY COLUMN `system_role` ENUM('FUNDING','TREASURY','ESCROW') NULL DEFAULT NULL"
        );
    }

    public function down(): void
    {
        // A row already using ESCROW cannot be represented by the narrower enum, so the
        // rollback is refused by MySQL rather than silently destroying the role. That
        // refusal is the correct behaviour: it is the database saying the data depends
        // on this migration.
        DB::statement(
            "ALTER TABLE `ledger_accounts`
              MODIFY COLUMN `system_role` ENUM('FUNDING','TREASURY') NULL DEFAULT NULL"
        );
    }
};
