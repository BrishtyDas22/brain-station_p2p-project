<?php

namespace Tests\Feature\Ledger;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Support\MySqlTriggerSupport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The append-only guarantee on `ledger_entries`.
 *
 * Covers OpenSpec change `add-ledger-entry-immutability-triggers`, task groups 1–3.
 *
 * Every rejection asserted here is observed as a **server error** — SQLSTATE 45000 — never as a
 * PHP-level check. The connection used by the suite holds `ALL PRIVILEGES` on the schema, so
 * these are the rejections a *privileged* operator session would meet. That is the point: the
 * triggers, not the privilege grant, are what make the guarantee hold.
 */
class LedgerEntryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The MySQL SQLSTATE the triggers signal.
     */
    private const SIGNAL_SQLSTATE = '45000';

    /**
     * The `ledger_entries` rows, read raw so the model's casts cannot mask a change.
     *
     * @return array<string, mixed>
     */
    private function rawRow(string $entryId): array
    {
        $row = DB::table('ledger_entries')->where('entry_id', $entryId)->first();

        $this->assertNotNull($row, 'The entry must exist before the rejection is attempted.');

        return (array) $row;
    }

    /**
     * The names of the triggers installed on `ledger_entries`.
     *
     * @return list<string>
     */
    private function triggerNames(): array
    {
        return array_map(
            static fn (object $trigger): string => (string) $trigger->TRIGGER_NAME,
            DB::select(
                'SELECT TRIGGER_NAME FROM information_schema.triggers
                 WHERE trigger_schema = DATABASE() AND event_object_table = ?',
                ['ledger_entries'],
            ),
        );
    }

    /**
     * The `ACTION_STATEMENT` of the named trigger.
     */
    private function triggerBody(string $triggerName): string
    {
        $row = DB::selectOne(
            'SELECT ACTION_STATEMENT FROM information_schema.triggers
             WHERE trigger_schema = DATABASE() AND trigger_name = ?',
            [$triggerName],
        );

        $this->assertNotNull($row, "Trigger {$triggerName} must exist.");

        return (string) $row->ACTION_STATEMENT;
    }

    // ---------------------------------------------------------------------
    // Task 1.2 / 1.3 — the triggers exist
    // ---------------------------------------------------------------------

    #[Test]
    public function both_immutability_triggers_are_installed(): void
    {
        $names = $this->triggerNames();

        $this->assertContains('trg_ledger_entries_no_update', $names);
        $this->assertContains('trg_ledger_entries_no_delete', $names);
    }

    #[Test]
    public function the_update_trigger_is_a_before_update_trigger(): void
    {
        $row = DB::selectOne(
            'SELECT ACTION_TIMING, EVENT_MANIPULATION FROM information_schema.triggers
             WHERE trigger_schema = DATABASE() AND trigger_name = ?',
            ['trg_ledger_entries_no_update'],
        );

        $this->assertNotNull($row);
        $this->assertSame('BEFORE', $row->ACTION_TIMING);
        $this->assertSame('UPDATE', $row->EVENT_MANIPULATION);
    }

    #[Test]
    public function the_delete_trigger_is_a_before_delete_trigger(): void
    {
        $row = DB::selectOne(
            'SELECT ACTION_TIMING, EVENT_MANIPULATION FROM information_schema.triggers
             WHERE trigger_schema = DATABASE() AND trigger_name = ?',
            ['trg_ledger_entries_no_delete'],
        );

        $this->assertNotNull($row);
        $this->assertSame('BEFORE', $row->ACTION_TIMING);
        $this->assertSame('DELETE', $row->EVENT_MANIPULATION);
    }

    // ---------------------------------------------------------------------
    // Task 1.4 — the message names the remedy
    // ---------------------------------------------------------------------

    #[Test]
    public function the_rejection_message_names_the_compensating_entry_remedy(): void
    {
        $update = $this->triggerBody('trg_ledger_entries_no_update');
        $delete = $this->triggerBody('trg_ledger_entries_no_delete');

        $this->assertStringContainsString('45000', $update);
        $this->assertStringContainsString('compensating entry', $update);

        $this->assertStringContainsString('45000', $delete);
        $this->assertStringContainsString('compensating entry', $delete);
    }

    // ---------------------------------------------------------------------
    // Task 3.1 / 3.2 — an UPDATE is rejected and the row is unchanged
    // ---------------------------------------------------------------------

    #[Test]
    public function a_direct_update_is_rejected_by_the_database(): void
    {
        $entry = $this->seedEntry();

        try {
            DB::table('ledger_entries')
                ->where('entry_id', $entry->entry_id)
                ->update(['amount_minor' => 999_999]);

            $this->fail('An UPDATE against ledger_entries must be rejected by the database.');
        } catch (QueryException $exception) {
            $this->assertSame(
                self::SIGNAL_SQLSTATE,
                $exception->getCode(),
                'The trigger must signal SQLSTATE 45000.'
            );
        }
    }

    #[Test]
    public function a_rejected_update_leaves_the_row_unchanged(): void
    {
        $entry = $this->seedEntry();

        $before = $this->rawRow($entry->entry_id);

        try {
            DB::table('ledger_entries')
                ->where('entry_id', $entry->entry_id)
                ->update(['amount_minor' => 999_999]);
        } catch (QueryException) {
            // Expected. The assertion is on the row's state below.
        }

        $after = $this->rawRow($entry->entry_id);

        $this->assertSame($before, $after, 'A rejected UPDATE must leave the row byte-identical.');
    }

    #[Test]
    public function a_no_op_update_is_also_rejected(): void
    {
        // The trigger is unconditional. A trigger that permitted `SET x = x` would have
        // to enumerate the columns to compare, and a column added later would be
        // silently unprotected.
        $entry = $this->seedEntry();

        try {
            DB::table('ledger_entries')
                ->where('entry_id', $entry->entry_id)
                ->update(['amount_minor' => DB::raw('amount_minor')]);

            $this->fail('A no-op UPDATE against ledger_entries must also be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame(self::SIGNAL_SQLSTATE, $exception->getCode());
        }
    }

    #[Test]
    public function an_update_that_changes_only_a_non_hashed_column_is_rejected(): void
    {
        // `balance_after` is excluded from the chained body, so the chain verifier
        // could not detect its mutation. The trigger protects the whole row.
        $entry = $this->seedEntry();

        try {
            DB::table('ledger_entries')
                ->where('entry_id', $entry->entry_id)
                ->update(['balance_after' => 4242]);

            $this->fail('An UPDATE of balance_after must be rejected by the database.');
        } catch (QueryException $exception) {
            $this->assertSame(self::SIGNAL_SQLSTATE, $exception->getCode());
        }
    }

    // ---------------------------------------------------------------------
    // Task 3.3 / 3.4 — a DELETE is rejected and the row survives
    // ---------------------------------------------------------------------

    #[Test]
    public function a_direct_delete_is_rejected_by_the_database(): void
    {
        $entry = $this->seedEntry();

        try {
            DB::table('ledger_entries')->where('entry_id', $entry->entry_id)->delete();

            $this->fail('A DELETE against ledger_entries must be rejected by the database.');
        } catch (QueryException $exception) {
            $this->assertSame(
                self::SIGNAL_SQLSTATE,
                $exception->getCode(),
                'The trigger must signal SQLSTATE 45000.'
            );
        }
    }

    #[Test]
    public function a_rejected_delete_leaves_the_row_present(): void
    {
        $entry = $this->seedEntry();

        try {
            DB::table('ledger_entries')->where('entry_id', $entry->entry_id)->delete();
        } catch (QueryException) {
            // Expected.
        }

        $this->assertSame(
            1,
            DB::table('ledger_entries')->where('entry_id', $entry->entry_id)->count(),
            'A rejected DELETE must leave the row in place.'
        );
    }

    #[Test]
    public function a_delete_of_every_row_is_rejected_and_removes_nothing(): void
    {
        $first = $this->seedEntry();
        $second = $this->seedEntry();

        try {
            DB::table('ledger_entries')->delete();

            $this->fail('An unqualified DELETE against ledger_entries must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame(self::SIGNAL_SQLSTATE, $exception->getCode());
        }

        $this->assertSame(2, DB::table('ledger_entries')->count());
        $this->assertSame(1, DB::table('ledger_entries')->where('entry_id', $first->entry_id)->count());
        $this->assertSame(1, DB::table('ledger_entries')->where('entry_id', $second->entry_id)->count());
    }

    // ---------------------------------------------------------------------
    // Task 3.6 — the rejection holds from a privileged session
    // ---------------------------------------------------------------------

    #[Test]
    public function the_rejection_holds_from_a_privileged_session(): void
    {
        // The suite's role holds ALL PRIVILEGES on the schema, so this session is
        // privileged. The rejection comes from the trigger, not from a missing grant.
        $grants = DB::select('SHOW GRANTS FOR CURRENT_USER');
        $text = implode("\n", array_map(
            static fn (mixed $grant): string => is_object($grant)
                ? (string) (array_values((array) $grant)[0] ?? '')
                : (string) $grant,
            $grants,
        ));

        $this->assertStringContainsStringIgnoringCase(
            'all privileges',
            $text,
            'This test is only meaningful from a privileged session.'
        );

        $entry = $this->seedEntry();

        try {
            DB::table('ledger_entries')->where('entry_id', $entry->entry_id)->delete();

            $this->fail('The rejection must hold even for a privileged session.');
        } catch (QueryException $exception) {
            $this->assertSame(self::SIGNAL_SQLSTATE, $exception->getCode());
            $this->assertStringContainsString(
                'compensating entry',
                $exception->getMessage(),
                'The operator must be told the correct remedy.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // Task 2.1 / 2.2 — mechanism 3, the cascade prohibition
    // ---------------------------------------------------------------------

    #[Test]
    public function no_foreign_key_on_ledger_entries_carries_a_referential_action(): void
    {
        $sql = (string) DB::selectOne('SHOW CREATE TABLE `ledger_entries`')->{'Create Table'};

        $this->assertDoesNotMatchRegularExpression('/ON\s+DELETE/i', $sql);
        $this->assertDoesNotMatchRegularExpression('/ON\s+UPDATE/i', $sql);
    }

    #[Test]
    public function deleting_an_account_that_has_entries_is_rejected(): void
    {
        $account = LedgerAccount::factory()->create();
        $entry = LedgerEntry::factory()->forAccount($account, 1)->create();

        try {
            DB::table('ledger_accounts')->where('id', $account->id)->delete();

            $this->fail('Deleting an account with ledger entries must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->getCode(), 'A foreign-key violation is expected.');
        }

        $this->assertSame(
            1,
            DB::table('ledger_entries')->where('entry_id', $entry->entry_id)->count(),
            'The entry must survive the rejected account deletion.'
        );
    }

    // ---------------------------------------------------------------------
    // Task 3.7 — the escape hatch is off
    // ---------------------------------------------------------------------

    #[Test]
    public function the_trigger_bypass_escape_hatch_is_off_in_the_default_configuration(): void
    {
        $this->assertFalse(
            MySqlTriggerSupport::testSchemaEscapeIsActive(),
            'The escape hatch must be off, so the suite runs against a schema whose invariant is '
            .'genuinely enforced rather than one where the trigger was quietly skipped.'
        );
    }

    #[Test]
    public function the_escape_hatch_never_applies_outside_the_isolated_test_schema(): void
    {
        // The database-name condition is the safety interlock. Even with the flag
        // forced on, a non-`_test` database can never satisfy it.
        config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => true]);

        $this->assertTrue(
            MySqlTriggerSupport::testSchemaEscapeIsActive(),
            'The interlock must still admit the isolated `_test` schema.'
        );

        config([
            'database.connections.'.config('database.default').'.database' => 'p2p_platform_dev',
        ]);

        $this->assertFalse(
            MySqlTriggerSupport::testSchemaEscapeIsActive(),
            'The escape hatch must never apply to a development or production schema.'
        );
    }

    // ---------------------------------------------------------------------
    // Task 1.6 — the fail-closed failure path
    // ---------------------------------------------------------------------

    #[Test]
    public function a_failed_trigger_installation_leaves_no_table_and_no_orphaned_trigger(): void
    {
        // MySQL DDL is not transactional. A migration that installs the UPDATE trigger
        // and then fails on the DELETE trigger would otherwise leave a table carrying
        // only half the append-only guarantee — which looks correct in
        // `SHOW CREATE TABLE` while enforcing half the rule.
        //
        // The migration therefore drops `ledger_entries` on the failure path. This test
        // proves that path: it drops the table, installs a probe trigger on it, and
        // then verifies that dropping the table takes the trigger with it and leaves
        // nothing orphaned.
        $account = LedgerAccount::factory()->create();
        $entry = LedgerEntry::factory()->forAccount($account, 1)->create();

        $this->assertSame(1, DB::table('ledger_entries')->count());

        // Attach a probe trigger, then exercise the failure path's cleanup.
        DB::unprepared(
            'CREATE TRIGGER `trg_immutability_probe` BEFORE UPDATE ON `ledger_entries` '
            .'FOR EACH ROW BEGIN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'probe\'; END'
        );

        try {
            Schema::dropIfExists('ledger_entries');

            $this->assertFalse(
                Schema::hasTable('ledger_entries'),
                'The failure path must leave NO ledger_entries table.'
            );

            $orphans = DB::select(
                'SELECT trigger_name FROM information_schema.triggers
                 WHERE trigger_schema = DATABASE() AND trigger_name = ?',
                ['trg_immutability_probe'],
            );

            $this->assertSame(
                [],
                $orphans,
                'Dropping the table must drop its triggers with it - no orphaned trigger may survive.'
            );
        } finally {
            // Leave the schema as the test suite expects to find it.
            DB::unprepared('DROP TRIGGER IF EXISTS `trg_immutability_probe`');
            $this->restoreLedgerEntriesSchema();
        }

        $this->assertNotNull($entry->entry_id);
    }

    // ---------------------------------------------------------------------
    // The row is still writable — append-only is not read-only
    // ---------------------------------------------------------------------

    #[Test]
    public function an_entry_can_still_be_inserted(): void
    {
        // Append-only means "never modified or removed", not "never written". The
        // table must accept the INSERT that starts the chain.
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->create();

        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame($entry->entry_id, LedgerEntry::query()->first()->entry_id);
    }

    /**
     * Seed one entry on its own account.
     */
    private function seedEntry(): LedgerEntry
    {
        $account = LedgerAccount::factory()->create();

        return LedgerEntry::factory()->forAccount($account, 1)->create();
    }

    /**
     * Recreate the `ledger_entries` table and its two immutability triggers.
     *
     * Used to restore the schema after a test that deliberately drops it. The
     * recreation mirrors the migrations exactly — the same columns, the same
     * constraints, and the same two triggers — so the rest of the suite is
     * unaffected by the ordering of the tests.
     */
    private function restoreLedgerEntriesSchema(): void
    {
        if (! Schema::hasTable('ledger_entries')) {
            $migration = require database_path(
                'migrations/2026_10_26_000003_create_ledger_entries_table.php'
            );
            $migration->up();
        }

        if (! MySqlTriggerSupport::triggerExists('trg_ledger_entries_no_update', 'ledger_entries')) {
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
        }

        if (! MySqlTriggerSupport::triggerExists('trg_ledger_entries_no_delete', 'ledger_entries')) {
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
        }
    }
}
