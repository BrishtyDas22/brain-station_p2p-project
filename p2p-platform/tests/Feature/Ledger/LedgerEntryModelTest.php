<?php

namespace Tests\Feature\Ledger;

use App\Casts\IntegerPoisha;
use App\Enums\LedgerDirection;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer contract of `ledgerEntry`.
 *
 * Covers OpenSpec change `add-ledger-entry-table`, task group 3 (the model).
 */
class LedgerEntryModelTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Task 3.1 — the direction enum
    // ---------------------------------------------------------------------

    #[Test]
    public function the_direction_enum_has_exactly_the_two_sides_the_column_accepts(): void
    {
        $cases = LedgerDirection::cases();

        $this->assertCount(2, $cases);
        $this->assertSame('DEBIT', LedgerDirection::DEBIT->value);
        $this->assertSame('CREDIT', LedgerDirection::CREDIT->value);
    }

    #[Test]
    public function the_direction_enum_reports_its_opposite_side(): void
    {
        $this->assertSame(LedgerDirection::CREDIT, LedgerDirection::DEBIT->opposite());
        $this->assertSame(LedgerDirection::DEBIT, LedgerDirection::CREDIT->opposite());
    }

    // ---------------------------------------------------------------------
    // Task 3.2 — the casts
    // ---------------------------------------------------------------------

    #[Test]
    public function the_direction_reads_back_as_an_enum(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->create([
            'direction' => LedgerDirection::CREDIT,
        ]);

        $fresh = $entry->fresh();

        $this->assertInstanceOf(LedgerDirection::class, $fresh->direction);
        $this->assertSame(LedgerDirection::CREDIT, $fresh->direction);
    }

    #[Test]
    public function the_money_columns_read_back_as_integers(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()
            ->forAccount($account, 1)
            ->withAmountMinor(1234)
            ->withBalanceAfter(4321)
            ->create();

        $fresh = $entry->fresh();

        $this->assertIsInt($fresh->amount_minor);
        $this->assertSame(1234, $fresh->amount_minor);
        $this->assertIsInt($fresh->balance_after);
        $this->assertSame(4321, $fresh->balance_after);
    }

    #[Test]
    public function the_money_columns_are_cast_through_the_integer_poisha_cast(): void
    {
        $casts = (new LedgerEntry)->getCasts();

        $this->assertSame(IntegerPoisha::class, $casts['amount_minor']);
        $this->assertSame(IntegerPoisha::class, $casts['balance_after']);
    }

    // ---------------------------------------------------------------------
    // Task 3.3 — the model is guarded
    // ---------------------------------------------------------------------

    #[Test]
    public function every_business_column_is_guarded_except_the_primary_key(): void
    {
        $entry = new LedgerEntry;

        $this->assertTrue($entry->isFillable('id'));

        foreach (['entry_id', 'transaction_id', 'account_id', 'direction', 'amount_minor', 'currency', 'account_sequence_number', 'balance_after', 'prev_hash', 'entry_hash', 'created_at'] as $column) {
            $this->assertFalse($entry->isFillable($column), "{$column} must be guarded.");
        }
    }

    #[Test]
    public function the_primary_key_is_the_string_entry_id(): void
    {
        $entry = new LedgerEntry;

        $this->assertSame('entry_id', $entry->getKeyName());
        $this->assertFalse($entry->getIncrementing());
        $this->assertSame('string', $entry->getKeyType());
    }

    #[Test]
    public function the_model_does_not_use_timestamps(): void
    {
        $this->assertFalse((new LedgerEntry)->usesTimestamps());
    }

    // ---------------------------------------------------------------------
    // Task 3.4 — the relation
    // ---------------------------------------------------------------------

    #[Test]
    public function the_account_relation_resolves_to_the_account_the_entry_names(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->create();

        $resolved = $entry->fresh()->account;

        $this->assertInstanceOf(LedgerAccount::class, $resolved);
        $this->assertSame($account->id, $resolved->id);
    }

    // ---------------------------------------------------------------------
    // Task 3.5 — the money path never truncates
    // ---------------------------------------------------------------------

    #[Test]
    public function assigning_a_decimal_string_to_amount_minor_is_rejected(): void
    {
        $entry = new LedgerEntry;

        try {
            $entry->amount_minor = '12.34';
            $this->fail('Assigning a decimal string to a money attribute must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('amount_minor', $exception->getMessage());
        }

        $this->assertNull(
            $entry->amount_minor,
            'The attribute must hold no truncated value after the rejection.'
        );
    }

    #[Test]
    public function assigning_a_float_to_a_money_attribute_is_rejected(): void
    {
        $entry = new LedgerEntry;

        $this->expectException(InvalidArgumentException::class);

        $entry->amount_minor = 12.34;
    }

    #[Test]
    public function assigning_a_non_numeric_string_to_a_money_attribute_is_rejected(): void
    {
        $entry = new LedgerEntry;

        $this->expectException(InvalidArgumentException::class);

        $entry->balance_after = 'twelve';
    }

    #[Test]
    public function assigning_an_exact_integer_to_a_money_attribute_is_accepted(): void
    {
        $entry = new LedgerEntry;

        $entry->amount_minor = 1234;

        $this->assertSame(1234, $entry->amount_minor);
    }

    #[Test]
    public function assigning_an_exact_integer_string_to_a_money_attribute_is_accepted(): void
    {
        $entry = new LedgerEntry;

        $entry->amount_minor = '1234';

        $this->assertSame(1234, $entry->amount_minor);
    }

    // ---------------------------------------------------------------------
    // Task 3.6 — a null balance stays null
    // ---------------------------------------------------------------------

    #[Test]
    public function a_null_balance_after_read_from_the_database_stays_null(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->withBalanceAfter(null)->create();

        $fresh = $entry->fresh();

        $this->assertNull($fresh->balance_after, 'A null balance must never become a zero balance.');
    }

    #[Test]
    public function a_zero_balance_after_is_distinct_from_a_null_one(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->withBalanceAfter(0)->create();

        $fresh = $entry->fresh();

        $this->assertNotNull($fresh->balance_after);
        $this->assertSame(0, $fresh->balance_after);
    }

    // ---------------------------------------------------------------------
    // The money path on the write side
    // ---------------------------------------------------------------------

    #[Test]
    public function an_exact_integer_amount_round_trips_unchanged(): void
    {
        $account = LedgerAccount::factory()->create();

        $entry = LedgerEntry::factory()->forAccount($account, 1)->withAmountMinor(9999)->create();

        $stored = LedgerEntry::query()
            ->where('entry_id', $entry->entry_id)
            ->value('amount_minor');

        $this->assertSame('9999', (string) $stored);
    }
}
