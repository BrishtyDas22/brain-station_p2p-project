<?php

namespace Tests\Feature\Ledger;

use App\Enums\LedgerDirection;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The data-layer contract of `ledger_entries`.
 *
 * Covers OpenSpec change `add-ledger-entry-table`, task group 1 (the table) and task
 * group 2 (the two documented omissions).
 *
 * The money columns are `BIGINT UNSIGNED` integer Poisha and the constraints are
 * enforced by the database, not by this application. Every rejection asserted here is
 * observed as a server error, never as a PHP-level check.
 */
class LedgerEntrySchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The raw DDL of the table, straight from the server.
     */
    private function createTableSql(): string
    {
        $row = DB::selectOne('SHOW CREATE TABLE `ledger_entries`');

        return (string) $row->{'Create Table'};
    }

    /**
     * @return list<string>
     */
    private function columnNames(): array
    {
        return array_map(
            static fn (object $column): string => (string) $column->Field,
            DB::select('SHOW COLUMNS FROM `ledger_entries`'),
        );
    }

    /**
     * The names of the table's indexes.
     *
     * @return list<string>
     */
    private function indexNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (object $index): string => (string) $index->Key_name,
            DB::select('SHOW INDEX FROM `ledger_entries`'),
        )));
    }

    /**
     * @return list<string>
     */
    private function checkConstraintNames(): array
    {
        return array_map(
            static fn (object $check): string => (string) $check->CONSTRAINT_NAME,
            DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'
            ),
        );
    }

    // ---------------------------------------------------------------------
    // Task 1.1 — the table and its columns
    // ---------------------------------------------------------------------

    #[Test]
    public function the_table_exists_with_exactly_the_eleven_specified_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ledger_entries'));

        $expected = [
            'entry_id',
            'transaction_id',
            'account_id',
            'direction',
            'amount_minor',
            'currency',
            'account_sequence_number',
            'balance_after',
            'prev_hash',
            'entry_hash',
            'created_at',
        ];

        $actual = $this->columnNames();

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, 'The table must carry exactly the eleven specified columns.');
    }

    #[Test]
    public function each_column_carries_the_declared_type_nullability_and_default(): void
    {
        $columns = [];

        foreach (DB::select('SHOW COLUMNS FROM `ledger_entries`') as $column) {
            $columns[(string) $column->Field] = [
                'type' => strtolower((string) $column->Type),
                'null' => strtoupper((string) $column->Null) === 'YES',
                'default' => $column->Default,
                'key' => (string) $column->Key,
            ];
        }

        // entry_id: CHAR(26) NOT NULL, primary key.
        $this->assertSame('char(26)', $columns['entry_id']['type']);
        $this->assertFalse($columns['entry_id']['null']);
        $this->assertSame('PRI', $columns['entry_id']['key']);

        // The unsigned integer columns.
        foreach (['transaction_id', 'account_id', 'amount_minor', 'account_sequence_number'] as $column) {
            $this->assertSame('bigint unsigned', $columns[$column]['type'], "{$column} must be BIGINT UNSIGNED.");
            $this->assertFalse($columns[$column]['null'], "{$column} must be NOT NULL.");
        }

        // direction: the two-sided enum, NOT NULL.
        $this->assertSame("enum('debit','credit')", $columns['direction']['type']);
        $this->assertFalse($columns['direction']['null']);

        // currency: CHAR(3) NOT NULL with the BDT default.
        $this->assertSame('char(3)', $columns['currency']['type']);
        $this->assertFalse($columns['currency']['null']);
        $this->assertSame('BDT', $columns['currency']['default']);

        // balance_after: BIGINT UNSIGNED and nullable.
        $this->assertSame('bigint unsigned', $columns['balance_after']['type']);
        $this->assertTrue($columns['balance_after']['null']);
        $this->assertNull($columns['balance_after']['default']);

        // The two hashes.
        $this->assertSame('char(64)', $columns['prev_hash']['type']);
        $this->assertFalse($columns['prev_hash']['null']);
        $this->assertSame('char(64)', $columns['entry_hash']['type']);
        $this->assertFalse($columns['entry_hash']['null']);

        // created_at keeps microsecond precision — it is inside the hashed body.
        $this->assertSame('datetime(6)', $columns['created_at']['type']);
        $this->assertFalse($columns['created_at']['null']);
    }

    #[Test]
    public function the_primary_key_is_the_entry_id_alone(): void
    {
        $row = DB::selectOne('SHOW CREATE TABLE `ledger_entries`');

        $this->assertMatchesRegularExpression(
            '/PRIMARY KEY \(`entry_id`\)/',
            (string) $row->{'Create Table'}
        );
    }

    #[Test]
    public function the_created_at_timestamp_keeps_its_microsecond_precision(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->create([
            'created_at' => '2026-10-26 12:34:56.123456',
        ]);

        $stored = DB::table('ledger_entries')
            ->where('entry_id', $entry->entry_id)
            ->value('created_at');

        $this->assertSame('2026-10-26 12:34:56.123456', $stored);
    }

    #[Test]
    public function the_table_is_innodb_and_utf8mb4(): void
    {
        $sql = $this->createTableSql();

        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('utf8mb4', $sql);
    }

    #[Test]
    public function no_money_column_is_a_floating_point_or_decimal_type(): void
    {
        $sql = $this->createTableSql();

        foreach (['decimal', 'float', 'double'] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression(
                '/`[^`]*`\s+'.$forbidden.'\b/i',
                $sql,
                "The table must carry no {$forbidden} column."
            );
        }

        // The two money columns are unsigned integers.
        $this->assertStringContainsString('`amount_minor` bigint unsigned', $sql);
        $this->assertStringContainsString('`balance_after` bigint unsigned', $sql);
    }

    // ---------------------------------------------------------------------
    // Task 1.2 — the per-account sequence number is unique per account
    // ---------------------------------------------------------------------

    #[Test]
    public function a_duplicate_sequence_number_for_the_same_account_is_rejected(): void
    {
        $account = LedgerAccount::factory()->create();

        LedgerEntry::factory()->forAccount($account, 12)->create();

        $this->expectException(QueryException::class);

        LedgerEntry::factory()->forAccount($account, 12)->create();
    }

    #[Test]
    public function the_same_sequence_number_on_two_different_accounts_is_accepted(): void
    {
        $first = LedgerAccount::factory()->create();
        $second = LedgerAccount::factory()->create();

        LedgerEntry::factory()->forAccount($first, 12)->create();
        LedgerEntry::factory()->forAccount($second, 12)->create();

        $this->assertSame(2, LedgerEntry::query()->where('account_sequence_number', 12)->count());
    }

    #[Test]
    public function the_unique_index_over_account_and_sequence_exists(): void
    {
        $this->assertContains('uq_ledger_entries_account_sequence', $this->indexNames());
    }

    // ---------------------------------------------------------------------
    // Task 1.3 — the entry hash is unique
    // ---------------------------------------------------------------------

    #[Test]
    public function a_duplicate_entry_hash_is_rejected(): void
    {
        $account = LedgerAccount::factory()->create();

        LedgerEntry::factory()->forAccount($account, 1)->create(['entry_hash' => str_repeat('a', 64)]);

        $this->expectException(QueryException::class);

        LedgerEntry::factory()->forAccount($account, 2)->create(['entry_hash' => str_repeat('a', 64)]);
    }

    #[Test]
    public function the_unique_index_over_entry_hash_exists(): void
    {
        $this->assertContains('uq_ledger_entries_entry_hash', $this->indexNames());
    }

    // ---------------------------------------------------------------------
    // Task 1.4 / 1.5 — the two reconciliation scans
    // ---------------------------------------------------------------------

    #[Test]
    public function the_index_over_transaction_and_direction_exists(): void
    {
        $this->assertContains('ix_ledger_entries_transaction', $this->indexNames());
    }

    #[Test]
    public function the_index_over_account_and_created_at_exists(): void
    {
        $this->assertContains('ix_ledger_entries_account_created', $this->indexNames());
    }

    // ---------------------------------------------------------------------
    // Task 1.6 / 1.7 / 1.8 — the CHECK constraints
    // ---------------------------------------------------------------------

    #[Test]
    public function a_zero_amount_is_rejected(): void
    {
        $account = LedgerAccount::factory()->create();

        $this->expectException(QueryException::class);

        LedgerEntry::factory()->forAccount($account, 1)->withAmountMinor(0)->create();
    }

    #[Test]
    public function a_negative_amount_is_rejected(): void
    {
        $account = LedgerAccount::factory()->create();

        $this->expectException(QueryException::class);

        LedgerEntry::factory()->forAccount($account, 1)->withAmountMinor(-1)->create();
    }

    #[Test]
    public function a_currency_other_than_bdt_is_rejected(): void
    {
        $account = LedgerAccount::factory()->create();

        $this->expectException(QueryException::class);

        LedgerEntry::factory()->forAccount($account, 1)->create(['currency' => 'USD']);
    }

    #[Test]
    public function bdt_is_accepted(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->create(['currency' => 'BDT']);

        $this->assertSame('BDT', $entry->fresh()->currency);
    }

    #[Test]
    public function a_negative_balance_after_is_rejected(): void
    {
        $account = LedgerAccount::factory()->create();

        $this->expectException(QueryException::class);

        LedgerEntry::factory()->forAccount($account, 1)->withBalanceAfter(-1)->create();
    }

    #[Test]
    public function a_null_balance_after_is_accepted(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->withBalanceAfter(null)->create();

        $this->assertNull($entry->fresh()->balance_after);
    }

    #[Test]
    public function the_three_check_constraints_are_named_and_present(): void
    {
        $names = $this->checkConstraintNames();

        $this->assertContains('ck_ledger_entries_amount', $names);
        $this->assertContains('ck_ledger_entries_currency', $names);
        $this->assertContains('ck_ledger_entries_balance', $names);
    }

    // ---------------------------------------------------------------------
    // Task 1.9 — the foreign key to a real ledger account
    // ---------------------------------------------------------------------

    #[Test]
    public function an_entry_naming_a_non_existent_account_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        LedgerEntry::factory()->forAccount(999_999, 1)->create();
    }

    #[Test]
    public function deleting_an_account_that_has_entries_is_rejected(): void
    {
        $account = LedgerAccount::factory()->create();

        LedgerEntry::factory()->forAccount($account, 1)->create();

        $this->expectException(QueryException::class);

        DB::table('ledger_accounts')->where('id', $account->id)->delete();
    }

    #[Test]
    public function no_foreign_key_on_the_table_carries_a_referential_action(): void
    {
        $sql = $this->createTableSql();

        $this->assertDoesNotMatchRegularExpression(
            '/ON\s+DELETE/i',
            $sql,
            'No foreign key may declare ON DELETE.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/ON\s+UPDATE/i',
            $sql,
            'No foreign key may declare ON UPDATE.'
        );

        // The foreign key that *is* present, named explicitly.
        $this->assertStringContainsString(
            'CONSTRAINT `fk_ledger_entries_account` FOREIGN KEY (`account_id`) REFERENCES `ledger_accounts` (`id`)',
            $sql
        );
    }

    // ---------------------------------------------------------------------
    // Task 2.1 — the status migration exists but is NOT applied
    // ---------------------------------------------------------------------

    #[Test]
    public function the_table_carries_no_status_column(): void
    {
        $this->assertNotContains('status', $this->columnNames());
    }

    #[Test]
    public function the_status_migration_file_exists_but_is_not_applied(): void
    {
        $pending = database_path('migrations/pending/2026_10_26_000004_add_status_to_ledger_entries_table.php');

        $this->assertFileExists($pending, 'The reversible status migration must be prepared on disk.');

        $applied = DB::table('migrations')->pluck('migration')->all();

        $this->assertNotContains(
            '2026_10_26_000004_add_status_to_ledger_entries_table',
            $applied,
            'The status migration must NOT be applied — H-6 / D1 is still Proposed.'
        );
    }

    #[Test]
    public function the_status_migration_is_not_reachable_by_the_migration_runner(): void
    {
        // Laravel's migrator globs `$path/*_*.php` — non-recursively. A file inside a
        // subdirectory is therefore invisible to `migrate`. This is the mechanism that
        // keeps H-6 / D1 undecided without deleting the reversible artifact.
        $migrations = app('migrator')->getMigrationFiles(database_path('migrations'));

        $names = array_map(
            static fn (string $path): string => basename($path),
            $migrations,
        );

        $this->assertNotContains(
            '2026_10_26_000004_add_status_to_ledger_entries_table.php',
            $names,
            'The pending status migration must not be picked up by the migration runner.'
        );

        $this->assertContains('2026_10_26_000003_create_ledger_entries_table.php', $names);
    }

    // ---------------------------------------------------------------------
    // A direction sanity check — the enum column accepts only the two sides
    // ---------------------------------------------------------------------

    #[Test]
    public function a_direction_outside_the_two_sides_is_rejected_by_the_model_layer(): void
    {
        // The `LedgerDirection` enum cast is stricter than the database: an
        // unrecognised direction never reaches the wire. That is the better failure
        // mode — it names the offending value instead of a MySQL data-truncation
        // error — so the model layer is where this is caught.
        $account = LedgerAccount::factory()->create();

        $this->expectException(\ValueError::class);

        LedgerEntry::factory()->forAccount($account, 1)->create(['direction' => 'TRANSFER']);
    }

    #[Test]
    public function a_direction_outside_the_two_sides_is_rejected_by_the_database(): void
    {
        // The database constraint is asserted independently of the model, so the
        // guarantee does not rest on the enum cast alone.
        $account = LedgerAccount::factory()->create();

        $this->expectException(QueryException::class);

        DB::insert(
            'INSERT INTO `ledger_entries` (entry_id, transaction_id, account_id, direction, amount_minor, '
            .'currency, account_sequence_number, balance_after, prev_hash, entry_hash, created_at) '
            .'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                '01J0000000000000000000000A',
                1,
                $account->id,
                'TRANSFER',
                100,
                'BDT',
                1,
                null,
                str_repeat('0', 64),
                hash('sha256', 'probe'),
                '2026-10-26 12:00:00.000000',
            ],
        );
    }

    #[Test]
    public function both_directions_are_accepted(): void
    {
        $account = LedgerAccount::factory()->create();

        LedgerEntry::factory()->forAccount($account, 1)->direction(LedgerDirection::DEBIT)->create();
        LedgerEntry::factory()->forAccount($account, 2)->direction(LedgerDirection::CREDIT)->create();

        $this->assertSame(2, LedgerEntry::query()->count());
    }
}
