<?php

namespace Tests\Feature\Ledger;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerSystemRole;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The `ledger_accounts` table and the constraints that make the account half of the
 * 1:1 wallet↔account mapping true at the data layer.
 *
 * Covers OpenSpec tasks 1.2, 1.3, 1.4, 1.5, 1.6 and 3.3.
 *
 * Every assertion below is a **database** error, not a failed application check. That is
 * what SPEC §6.2 requires: an operator script or a migration must not be able to write a
 * row that the service layer would have refused.
 */
class LedgerAccountSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MySQL error numbers. Measured against MySQL 8.0.46, not assumed.
     */
    private const ER_DUP_ENTRY = 1062;

    private const ER_CHECK_CONSTRAINT_VIOLATED = 3819;

    /**
     * The DDL as MySQL reports it, for the schema-shape assertions.
     */
    private function showCreateTable(string $table): string
    {
        $row = DB::selectOne('SHOW CREATE TABLE `'.$table.'`');

        return (string) $row->{'Create Table'};
    }

    /**
     * Task 1.6 — a SYSTEM account may exist with a null owner, and the model creates it
     * successfully.
     *
     * This is the schema's accommodation of H-1 (🟠 Proposed, not approved). It proves
     * the shape is capable; it does NOT adopt H-1 — no SYSTEM account is created by the
     * application, and no funding path exists.
     */
    public function test_a_system_account_may_exist_with_a_null_owner(): void
    {
        $account = LedgerAccount::factory()->system(LedgerSystemRole::TREASURY)->create();

        $this->assertNull($account->owner_user_id);
        $this->assertSame(LedgerAccountType::SYSTEM, $account->account_type);
        $this->assertSame(LedgerSystemRole::TREASURY, $account->system_role);
        $this->assertTrue($account->isSystem());

        $this->assertDatabaseHas('ledger_accounts', [
            'id' => $account->id,
            'owner_user_id' => null,
            'account_type' => 'SYSTEM',
            'system_role' => 'TREASURY',
        ]);
    }

    /**
     * Task 1.6 — a USER account requires an owning user.
     */
    public function test_a_user_account_requires_an_owning_user(): void
    {
        $user = User::factory()->create();

        $account = LedgerAccount::factory()->forOwner($user)->create();

        $this->assertSame($user->id, $account->owner_user_id);
        $this->assertSame(LedgerAccountType::USER, $account->account_type);
        $this->assertNull($account->system_role);
    }

    /**
     * Task 1.2 — `uq_ledger_accounts_owner` rejects a second account for the same user.
     */
    public function test_a_user_cannot_own_two_ledger_accounts(): void
    {
        $user = User::factory()->create();

        LedgerAccount::factory()->forOwner($user)->create();

        $this->expectException(QueryException::class);

        LedgerAccount::factory()->forOwner($user)->create();
    }

    /**
     * Task 1.2 — the unique index is what raises the error, and it is a duplicate-key
     * error specifically.
     */
    public function test_a_second_account_for_the_same_user_is_a_duplicate_key_error(): void
    {
        $user = User::factory()->create();

        LedgerAccount::factory()->forOwner($user)->create();

        try {
            LedgerAccount::factory()->forOwner($user)->create();

            $this->fail('Expected a duplicate-key error from uq_ledger_accounts_owner.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_DUP_ENTRY, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('uq_ledger_accounts_owner', $e->getMessage());
        }
    }

    /**
     * Task 1.2 — the nullable-column mechanism: multiple SYSTEM accounts are permitted,
     * because MySQL allows repeated NULLs in a unique index, while each user still gets
     * at most one account.
     */
    public function test_multiple_system_accounts_are_permitted(): void
    {
        $first = LedgerAccount::factory()->system(LedgerSystemRole::FUNDING)->create();
        $second = LedgerAccount::factory()->system(LedgerSystemRole::TREASURY)->create();

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('ledger_accounts', 2);
    }

    /**
     * Task 1.3 — a USER account with no owner is rejected by the database.
     */
    public function test_a_user_account_without_an_owner_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::insert("INSERT INTO `ledger_accounts` (`owner_user_id`, `account_type`, `currency`, `next_sequence_number`, `created_at`)
            VALUES (NULL, 'USER', 'BDT', 1, NOW(6))");
    }

    /**
     * Task 1.3 — a SYSTEM account with an owner is rejected by the database.
     */
    public function test_a_system_account_with_an_owner_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::insert("INSERT INTO `ledger_accounts` (`owner_user_id`, `account_type`, `system_role`, `currency`, `next_sequence_number`, `created_at`)
            VALUES ({$user->id}, 'SYSTEM', 'FUNDING', 'BDT', 1, NOW(6))");
    }

    /**
     * Task 1.3 — both mismatched forms raise a CHECK-constraint violation specifically.
     */
    public function test_both_mismatched_type_and_owner_forms_are_check_violations(): void
    {
        $user = User::factory()->create();

        foreach ([
            'USER without an owner' => "INSERT INTO `ledger_accounts` (`owner_user_id`, `account_type`, `currency`, `next_sequence_number`, `created_at`)
                VALUES (NULL, 'USER', 'BDT', 1, NOW(6))",
            'SYSTEM with an owner' => "INSERT INTO `ledger_accounts` (`owner_user_id`, `account_type`, `system_role`, `currency`, `next_sequence_number`, `created_at`)
                VALUES ({$user->id}, 'SYSTEM', 'FUNDING', 'BDT', 1, NOW(6))",
            'USER with a system role' => "INSERT INTO `ledger_accounts` (`owner_user_id`, `account_type`, `system_role`, `currency`, `next_sequence_number`, `created_at`)
                VALUES ({$user->id}, 'USER', 'FUNDING', 'BDT', 1, NOW(6))",
        ] as $label => $sql) {
            try {
                DB::insert($sql);

                $this->fail("Expected the database to reject: {$label}.");
            } catch (QueryException $e) {
                $this->assertSame(
                    self::ER_CHECK_CONSTRAINT_VIOLATED,
                    $e->errorInfo[1] ?? null,
                    "Wrong error for: {$label}."
                );
                $this->assertStringContainsString('ck_ledger_accounts_type', $e->getMessage());
            }
        }
    }

    /**
     * Task 1.4 — the single-currency CHECK rejects a currency the system cannot honour.
     */
    public function test_a_currency_other_than_bdt_is_rejected(): void
    {
        $user = User::factory()->create();

        try {
            DB::insert("INSERT INTO `ledger_accounts` (`owner_user_id`, `account_type`, `currency`, `next_sequence_number`, `created_at`)
                VALUES ({$user->id}, 'USER', 'USD', 1, NOW(6))");

            $this->fail('Expected the database to reject the USD currency.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_CHECK_CONSTRAINT_VIOLATED, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('ck_ledger_accounts_currency', $e->getMessage());
        }
    }

    /**
     * Task 1.5 — the column comment records that `next_sequence_number` is a sequence
     * allocator, not a balance. The comment is the storage-layer reinforcement of that
     * fact, so a later reader does not mistake it for a second mutable balance.
     */
    public function test_the_sequence_counter_column_is_commented_as_an_allocator_not_a_balance(): void
    {
        $ddl = $this->showCreateTable('ledger_accounts');

        $this->assertStringContainsString('next_sequence_number', $ddl);
        $this->assertStringContainsString('COMMENT', $ddl);
        $this->assertMatchesRegularExpression(
            '/`next_sequence_number`[^,]*COMMENT \'[^\']*sequence allocator[^\']*not a balance[^\']*\'/',
            $ddl
        );
    }

    /**
     * The columns of a table, keyed by lower-case name.
     *
     * `information_schema` reports its column labels in upper case, so they are
     * aliased in SQL rather than renamed afterwards.
     *
     * @return array<string, object>
     */
    private function tableColumns(string $table): array
    {
        $rows = DB::select(
            'SELECT column_name AS name, data_type AS data_type, column_type AS column_type
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        $columns = [];

        foreach ($rows as $row) {
            $row->name = strtolower($row->name);
            $row->data_type = strtolower($row->data_type);
            $columns[$row->name] = $row;
        }

        return $columns;
    }

    /**
     * Task 3.3 — `ledger_accounts` carries no balance column at all. The balance lives
     * only on `wallets`; a second independently mutable balance field on the account is
     * forbidden.
     */
    public function test_the_ledger_account_carries_no_balance_column(): void
    {
        $names = array_keys($this->tableColumns('ledger_accounts'));

        $this->assertContains('next_sequence_number', $names);
        $this->assertNotContains('balance_minor', $names);
        $this->assertNotContains('reserved_minor', $names);
        $this->assertNotContains('balance', $names);
    }

    /**
     * Task 3.3 — the sequence counter is `bigint unsigned`, and no DECIMAL, FLOAT or
     * DOUBLE column exists anywhere in the table.
     */
    public function test_no_decimal_float_or_double_column_exists(): void
    {
        $columns = $this->tableColumns('ledger_accounts');

        foreach ($columns as $name => $column) {
            $this->assertNotContains(
                $column->data_type,
                ['decimal', 'float', 'double', 'numeric', 'real'],
                "Column {$name} uses a non-integer type."
            );
        }

        $sequence = $columns['next_sequence_number'];

        $this->assertSame('bigint', $sequence->data_type);
        $this->assertStringContainsString('unsigned', $sequence->column_type);
    }

    /**
     * Task 1.2 / 2.5 — the foreign key to `users` carries no referential action, so a
     * delete is refused rather than silently cascading.
     */
    public function test_the_owner_foreign_key_carries_no_referential_action(): void
    {
        $ddl = $this->showCreateTable('ledger_accounts');

        $this->assertStringContainsString('CONSTRAINT `fk_ledger_accounts_owner`', $ddl);
        $this->assertStringNotContainsString('ON DELETE', $ddl);
        $this->assertStringNotContainsString('ON UPDATE', $ddl);
    }
}
