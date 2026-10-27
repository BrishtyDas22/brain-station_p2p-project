<?php

use App\Support\MySqlTriggerSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only enforcement on `ledger_entries` — the trigger half of SPEC §4.2.
 *
 * Normative source: `../P2P_SPECIFICATION.md` §4.2, §14.1; architecture-p2p-settlement.md
 * §"The append-only guarantee is enforced by three database-level mechanisms" (A5);
 * OpenSpec change `add-ledger-entry-immutability-triggers` design.md.
 *
 * Notes that a later reader must not undo:
 *
 *  - **There is no `status` column.** H-6 / D1 is 🟠 Proposed (not approved). This change
 *    does not touch it, and the prepared-but-unapplied migration in
 *    `database/migrations/pending/` is unaffected.
 *  - **The trigger bodies are unconditional.** They reject EVERY `UPDATE` and EVERY `DELETE`
 *    without comparing old and new values. A trigger that permitted a "no-op" update
 *    (`SET x = x`) would have to enumerate the columns to compare, and a column added
 *    later would be silently unprotected. The unconditional form cannot be bypassed and
 *    does not need to be maintained as the table evolves.
 *  - **`MESSAGE_TEXT` is part of the operator experience.** An operator who tries to fix a
 *    ledger row by editing it is told the correct action: append a compensating entry
 *    (SPEC §4.2). A bare "operation not permitted" would send them looking for a
 *    `--force` flag.
 *  - **This is mechanism (2) of three.** The spine's mechanism (1) — the runtime role
 *    holding `INSERT`/`SELECT` but NOT `UPDATE`/`DELETE` — is **G4**, an infrastructure
 *    task, and is **NOT implemented here**. The triggers cover the privileged-session case
 *    that a privilege grant cannot, but the privilege revocation is still outstanding and
 *    no owner is recorded for it. The append-only guarantee is **not yet fully held**.
 *  - **Mechanism (3) — no cascades.** `ledger_entries`' only foreign key,
 *    `fk_ledger_entries_account`, declares no referential action, so MySQL's `RESTRICT`
 *    default applies. Two foreign keys are still to come and **must** carry the same
 *    obligation: `fk_ledger_entries_transaction → transactions(id)` (Epic 3) and
 *    `chain_close_records.entry_id → ledger_entries.entry_id` (story 5). A cascade from
 *    either would silently destroy the ledger history this migration exists to protect.
 *
 * **Fail-closed.** These triggers enforce a financial-integrity invariant, so their
 * installation is MANDATORY. If the server refuses to create them — or reports success
 * without creating them — the migration aborts with a `LedgerTriggerException` rather
 * than leaving a `ledger_entries` table whose append-only guarantee is not enforced.
 *
 * MySQL DDL is not transactional, so the table would survive a failure here. The table is
 * therefore dropped on the failure path: an aborted migration must leave NO
 * `ledger_entries` table, not a half-built one carrying only one of the two triggers. A
 * schema with an UPDATE trigger and no DELETE trigger looks correct in `SHOW CREATE
 * TABLE` while enforcing half the guarantee — which is worse than no schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            MySqlTriggerSupport::install(
                'trg_ledger_entries_no_update',
                'ledger_entries',
                <<<'SQL'
                    CREATE TRIGGER `trg_ledger_entries_no_update`
                    BEFORE UPDATE ON `ledger_entries`
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'ledger_entries is append-only. Rows are never updated. Correct by appending a compensating entry.';
                    END
                SQL
            );

            MySqlTriggerSupport::install(
                'trg_ledger_entries_no_delete',
                'ledger_entries',
                <<<'SQL'
                    CREATE TRIGGER `trg_ledger_entries_no_delete`
                    BEFORE DELETE ON `ledger_entries`
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'ledger_entries is append-only. Rows are never deleted. Correct by appending a compensating entry.';
                    END
                SQL
            );
        } catch (Throwable $e) {
            // MySQL DDL is not transactional: a failure above leaves whatever was
            // already installed attached to the table. The table is dropped so the
            // schema is either fully enforced or absent — never half-enforced.
            //
            // Dropping the table also drops its triggers, so no orphaned trigger is
            // left behind.
            Schema::dropIfExists('ledger_entries');

            throw $e;
        }
    }

    public function down(): void
    {
        // The triggers are dropped with the table — MySQL removes a table's triggers
        // automatically — so there is nothing to uninstall separately.
        //
        // **This rollback DESTROYS `ledger_entries` and its data.** That is deliberate:
        // a table that survives a rollback without its append-only triggers would be a
        // schema that looks correct while enforcing nothing. The previous migration
        // (`2026_10_26_000003`) recreates the table, without the triggers, and the
        // migration that follows re-adds them.
        //
        // In Phase 1 no entry is ever written, so nothing is lost.
        Schema::dropIfExists('ledger_entries');
    }
};
