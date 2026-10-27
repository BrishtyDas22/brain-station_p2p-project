<?php

namespace Tests\Feature\Ledger;

use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Support\MySqlTriggerSupport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The `wallets` table — where the only mutable balance in the system lives — and the
 * constraints that make the wallet↔account mapping true at the data layer.
 *
 * Covers OpenSpec tasks 2.2, 2.3, 2.4, 2.5, 2.6 and 3.1, 3.2, 3.3.
 *
 * Every assertion below is a **database** error, not a failed application check.
 */
class WalletSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MySQL error numbers. Measured against MySQL 8.0.46, not assumed.
     */
    private const ER_DUP_ENTRY = 1062;

    private const ER_CHECK_CONSTRAINT_VIOLATED = 3819;

    private const ER_ROW_IS_REFERENCED_2 = 1451;

    private const ER_NO_REFERENCED_ROW_2 = 1452;

    private const ER_WARN_DATA_OUT_OF_RANGE = 1264;

    /**
     * The DDL as MySQL reports it, for the schema-shape assertions.
     */
    private function showCreateTable(string $table): string
    {
        $row = DB::selectOne('SHOW CREATE TABLE `'.$table.'`');

        return (string) $row->{'Create Table'};
    }

    /**
     * The columns of a table, keyed by lower-case name.
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
     * Task 2.6 — a wallet is created together with its ledger account, in one
     * transaction. There is no code path that creates a wallet without an account.
     */
    public function test_a_wallet_is_created_with_its_account_in_a_single_transaction(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()->forOwner($user)->create();

        $this->assertTrue($wallet->exists);
        $this->assertSame($user->id, $wallet->owner_user_id);
        $this->assertNotNull($wallet->account_id);

        $this->assertDatabaseHas('ledger_accounts', [
            'id' => $wallet->account_id,
            'owner_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('wallets', [
            'id' => $wallet->id,
            'account_id' => $wallet->account_id,
            'owner_user_id' => $user->id,
        ]);
    }

    /**
     * Task 2.6 — a wallet cannot be created without a valid account. The foreign key
     * refuses the write.
     */
    public function test_a_wallet_cannot_be_created_without_a_valid_account(): void
    {
        $user = User::factory()->create();

        try {
            DB::insert("INSERT INTO `wallets` (`owner_user_id`, `account_id`, `currency`, `balance_minor`, `reserved_minor`, `created_at`, `updated_at`)
                VALUES ({$user->id}, 999999, 'BDT', 0, 0, NOW(6), NOW(6))");

            $this->fail('Expected the database to refuse a wallet with no valid account.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_NO_REFERENCED_ROW_2, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('fk_wallets_account', $e->getMessage());
        }
    }

    /**
     * Task 2.2 — `uq_wallets_account` refuses a second wallet on the same account.
     */
    public function test_a_second_wallet_cannot_claim_the_same_ledger_account(): void
    {
        $account = LedgerAccount::factory()->create();

        Wallet::factory()->forOwner($account->owner_user_id)->create(['account_id' => $account->id]);

        try {
            Wallet::factory()->create(['account_id' => $account->id]);

            $this->fail('Expected a duplicate-key error from uq_wallets_account.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_DUP_ENTRY, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('uq_wallets_account', $e->getMessage());
        }
    }

    /**
     * Task 2.2 — `uq_wallets_owner_currency` refuses a second wallet for the same user
     * and currency.
     */
    public function test_a_user_holds_at_most_one_wallet_per_currency(): void
    {
        $user = User::factory()->create();

        Wallet::factory()->forOwner($user)->create();

        try {
            Wallet::factory()->create(['owner_user_id' => $user->id]);

            $this->fail('Expected a duplicate-key error from uq_wallets_owner_currency.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_DUP_ENTRY, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('uq_wallets_owner_currency', $e->getMessage());
        }
    }

    /**
     * Task 2.3 — a reserved balance above the posted balance is rejected by the
     * database, not by application code.
     */
    public function test_a_reserved_balance_above_the_posted_balance_is_rejected(): void
    {
        $wallet = Wallet::factory()->withBalanceMinor(100)->create();

        try {
            DB::update('UPDATE `wallets` SET `reserved_minor` = 200 WHERE `id` = ?', [$wallet->id]);

            $this->fail('Expected the database to reject a reserved balance above the posted balance.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_CHECK_CONSTRAINT_VIOLATED, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('ck_wallets_reserved_within_balance', $e->getMessage());
        }

        $this->assertSame(0, $wallet->fresh()->reserved_minor);
    }

    /**
     * Task 2.3 — a reserved balance exactly equal to the posted balance is accepted.
     */
    public function test_a_reserved_balance_equal_to_the_posted_balance_is_accepted(): void
    {
        $wallet = Wallet::factory()->withBalanceMinor(100)->create();

        DB::update('UPDATE `wallets` SET `reserved_minor` = 100 WHERE `id` = ?', [$wallet->id]);

        $this->assertSame(100, $wallet->fresh()->reserved_minor);
        $this->assertSame(0, $wallet->fresh()->availableMinor());
    }

    /**
     * Task 2.4 — the single-currency CHECK rejects a currency the system cannot honour.
     */
    public function test_a_currency_other_than_bdt_is_rejected(): void
    {
        $wallet = Wallet::factory()->create();

        try {
            DB::update('UPDATE `wallets` SET `currency` = ? WHERE `id` = ?', ['USD', $wallet->id]);

            $this->fail('Expected the database to reject the USD currency.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_CHECK_CONSTRAINT_VIOLATED, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('ck_wallets_currency', $e->getMessage());
        }
    }

    /**
     * Task 2.5 — deleting an account that has a wallet is refused. The failure is
     * loud; nothing is silently orphaned or re-pointed.
     */
    public function test_deleting_an_account_that_has_a_wallet_is_rejected(): void
    {
        $wallet = Wallet::factory()->create();

        try {
            DB::delete('DELETE FROM `ledger_accounts` WHERE `id` = ?', [$wallet->account_id]);

            $this->fail('Expected the database to refuse deleting an account with a wallet.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_ROW_IS_REFERENCED_2, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('fk_wallets_account', $e->getMessage());
        }

        $this->assertDatabaseHas('ledger_accounts', ['id' => $wallet->account_id]);
    }

    /**
     * Task 2.5 — re-pointing a wallet at a different ledger account is refused. The
     * mapping is immutable.
     *
     * The foreign key alone would permit this: an explicit UPDATE to a different
     * *valid* account satisfies referential integrity. The BEFORE UPDATE trigger
     * `trg_wallets_account_id_immutable` is what makes the binding a data-layer
     * guarantee, raising SQLSTATE 45000.
     */
    public function test_repointing_a_wallet_at_a_different_account_is_rejected(): void
    {
        $this->skipUnlessTriggersAreCreatable();

        $wallet = Wallet::factory()->create();
        $otherAccount = LedgerAccount::factory()->create();

        try {
            DB::update('UPDATE `wallets` SET `account_id` = ? WHERE `id` = ?', [$otherAccount->id, $wallet->id]);

            $this->fail('Expected the database to refuse re-pointing a wallet.');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->getCode());
            $this->assertStringContainsString('wallets.account_id is immutable', $e->getMessage());
        }

        $this->assertSame($wallet->account_id, $wallet->fresh()->account_id);
    }

    /**
     * Task 2.5 — the immutability trigger is scoped to `account_id` alone. Balances are
     * mutable by design: they are the projection the ledger reconciles against.
     */
    public function test_the_immutability_trigger_does_not_block_balance_updates(): void
    {
        $this->skipUnlessTriggersAreCreatable();

        $wallet = Wallet::factory()->withBalanceMinor(100)->create();

        DB::update('UPDATE `wallets` SET `balance_minor` = 250, `reserved_minor` = 50 WHERE `id` = ?', [$wallet->id]);

        $fresh = $wallet->fresh();

        $this->assertSame(250, $fresh->balance_minor);
        $this->assertSame(50, $fresh->reserved_minor);
    }

    /**
     * Skips the calling test when the server forbids trigger creation.
     */
    private function skipUnlessTriggersAreCreatable(): void
    {
        if (! MySqlTriggerSupport::triggersAreCreatable()) {
            $this->markTestSkipped(MySqlTriggerSupport::unavailableReason());
        }
    }

    /**
     * Task 2.5 — the declared DDL carries no ON DELETE or ON UPDATE referential action
     * on any foreign key. A cascade could silently re-point a wallet whose
     * `account_id` sits inside the hashed body of every ledger entry.
     */
    public function test_no_foreign_key_carries_a_referential_action(): void
    {
        foreach (['wallets', 'ledger_accounts'] as $table) {
            $ddl = $this->showCreateTable($table);

            $this->assertStringNotContainsString('ON DELETE', $ddl, "{$table} has an ON DELETE action.");
            $this->assertStringNotContainsString('ON UPDATE', $ddl, "{$table} has an ON UPDATE action.");
        }

        $walletsDdl = $this->showCreateTable('wallets');

        $this->assertStringContainsString('CONSTRAINT `fk_wallets_owner`', $walletsDdl);
        $this->assertStringContainsString('CONSTRAINT `fk_wallets_account`', $walletsDdl);
    }

    /**
     * Task 3.1 — a write setting `balance_minor` negative is rejected by the database
     * and the stored balance is unchanged.
     */
    public function test_a_negative_posted_balance_is_rejected_and_the_stored_balance_is_unchanged(): void
    {
        $wallet = Wallet::factory()->withBalanceMinor(50000)->create();

        try {
            DB::update('UPDATE `wallets` SET `balance_minor` = -1 WHERE `id` = ?', [$wallet->id]);

            $this->fail('Expected the database to reject a negative posted balance.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_WARN_DATA_OUT_OF_RANGE, $e->errorInfo[1] ?? null);
        }

        $this->assertSame(50000, $wallet->fresh()->balance_minor);
    }

    /**
     * Task 3.2 — a write setting `reserved_minor` negative is rejected by the database
     * and the stored value is unchanged.
     */
    public function test_a_negative_reserved_balance_is_rejected_and_the_stored_value_is_unchanged(): void
    {
        $wallet = Wallet::factory()->withBalanceMinor(50000)->create();

        try {
            DB::update('UPDATE `wallets` SET `reserved_minor` = -1 WHERE `id` = ?', [$wallet->id]);

            $this->fail('Expected the database to reject a negative reserved balance.');
        } catch (QueryException $e) {
            $this->assertSame(self::ER_WARN_DATA_OUT_OF_RANGE, $e->errorInfo[1] ?? null);
        }

        $this->assertSame(0, $wallet->fresh()->reserved_minor);
    }

    /**
     * Task 3.3 — all three money columns are `bigint unsigned`, and no DECIMAL, FLOAT or
     * DOUBLE column exists anywhere in either table.
     */
    public function test_every_money_column_is_an_unsigned_integer(): void
    {
        $moneyColumns = [
            'wallets' => ['balance_minor', 'reserved_minor'],
            'ledger_accounts' => ['next_sequence_number'],
        ];

        foreach ($moneyColumns as $table => $names) {
            $columns = $this->tableColumns($table);

            foreach ($names as $name) {
                $this->assertSame('bigint', $columns[$name]->data_type, "{$table}.{$name} is not a bigint.");
                $this->assertStringContainsString('unsigned', $columns[$name]->column_type, "{$table}.{$name} is not unsigned.");
            }

            foreach ($columns as $name => $column) {
                $this->assertNotContains(
                    $column->data_type,
                    ['decimal', 'float', 'double', 'numeric', 'real'],
                    "{$table}.{$name} uses a non-integer type."
                );
            }
        }
    }

    /**
     * Task 3.3 — the timestamps are `datetime(6)`, so a hashed timestamp can be
     * re-read byte-identically by the chain recovery sweeper.
     */
    public function test_timestamps_use_microsecond_precision(): void
    {
        $columns = $this->tableColumns('wallets');

        foreach (['created_at', 'updated_at'] as $name) {
            $this->assertSame('datetime', $columns[$name]->data_type);
            $this->assertStringContainsString('(6)', $columns[$name]->column_type);
        }
    }
}
