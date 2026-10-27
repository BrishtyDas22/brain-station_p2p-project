<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PREPARED BUT DELIBERATELY NOT APPLIED.
 *
 * **H-6 / D1 is 🟠 Proposed (not approved).** This migration is the *shape* of the
 * answer if the user later rejects H-6 and `ledger_entries` is given a `status` column
 * per SPEC §3.1. Applying it would decide H-6 by implementation, which is exactly what
 * "not approved" forbids.
 *
 * The `ledger_entries` table itself therefore ships **without** a `status` column.
 *
 * **Why absence is the cheaper error to reverse.** The spine's own reasoning: a later
 * migration can add a nullable column, but adding a column that was supposed to be
 * inside the hashed body changes every historical hash. Absence costs one `migrate`;
 * presence, once entries exist, costs a data rewrite.
 *
 * **Why this file exists at all.** Because H-6 is unapproved, a reader of the schema
 * needs to see both shapes and know which one is live. Having the reversal already
 * written makes it a single migration step rather than a redesign.
 *
 * **What is NOT decided here.** The hashed-body field list. SPEC §5.1 lists `status`
 * inside the chained body, and that list cannot be finalised while **M-2 (canonical
 * form) is ⚪ BLOCKED**. Nothing in this project computes a hash yet.
 *
 * **If this migration is ever applied**, the following must be revisited:
 *  - the hashed-body field list (M-2) — `status` would enter the hash;
 *  - the append-only story (story 3) — a mutable column on an append-only table
 *    contradicts SPEC §4.2 and §14.1, and a `status` column that the closer updates
 *    would require an `UPDATE` against `ledger_entries`;
 *  - the sweeper predicate (Epic 3) — SPEC §9.4 selects `status = 'CHAIN_PENDING'`;
 *    under the no-column shape it selects on the *absence* of a `chain_close_records`
 *    row instead.
 *
 * See OpenSpec change `add-ledger-entry-table` — design.md §"Deviation 1" and tasks.md
 * task 2.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            // The value set is the specification's. SPEC §3.1 names the column; SPEC §7.3
            // uses 'CHAIN_PENDING'. The remaining values are the ones SPEC §9.4 and the
            // sweeper's predicate imply. This list is itself provisional — it is
            // recorded here, not ratified.
            $table->enum('status', ['CHAIN_PENDING', 'CHAIN_CLOSED'])
                ->nullable()
                ->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
