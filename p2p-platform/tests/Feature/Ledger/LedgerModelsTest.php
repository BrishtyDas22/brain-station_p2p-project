<?php

namespace Tests\Feature\Ledger;

use App\Casts\IntegerPoisha;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerSystemRole;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The Eloquent layer over the money substrate.
 *
 * Covers OpenSpec tasks 4.1, 4.2, 4.3 and 4.4.
 *
 * The database is the authority for the financial invariants; the model's job is to
 * refuse to *represent* an invalid value at all, so that no code path can put a
 * truncated or rounded amount into the money path in the first place.
 */
class LedgerModelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Task 4.1 — the ledger account casts its enum columns and its sequence counter.
     */
    public function test_the_ledger_account_casts_its_columns(): void
    {
        $user = User::factory()->create();

        $account = LedgerAccount::factory()->forOwner($user)->create();

        $this->assertInstanceOf(LedgerAccountType::class, $account->account_type);
        $this->assertSame(LedgerAccountType::USER, $account->account_type);
        $this->assertNull($account->system_role);
        $this->assertIsInt($account->next_sequence_number);
        $this->assertSame(1, $account->next_sequence_number);
    }

    /**
     * Task 4.1 — a SYSTEM account's `system_role` casts to its enum value.
     */
    public function test_the_ledger_account_casts_the_system_role_of_a_system_account(): void
    {
        $account = LedgerAccount::factory()->system(LedgerSystemRole::FUNDING)->create();

        $this->assertSame(LedgerAccountType::SYSTEM, $account->account_type);
        $this->assertInstanceOf(LedgerSystemRole::class, $account->system_role);
        $this->assertSame(LedgerSystemRole::FUNDING, $account->system_role);
    }

    /**
     * Task 4.1 — the sequence counter rejects a non-integer value rather than
     * truncating it.
     */
    public function test_the_sequence_counter_rejects_a_non_integer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $account = LedgerAccount::factory()->create();
        $account->next_sequence_number = '12.34';
    }

    /**
     * Task 4.2 — the wallet casts its money columns to integers.
     */
    public function test_the_wallet_casts_its_money_columns(): void
    {
        $wallet = Wallet::factory()->withBalanceMinor(50000)->withReservedMinor(1200)->create();

        $this->assertIsInt($wallet->balance_minor);
        $this->assertIsInt($wallet->reserved_minor);
        $this->assertSame(50000, $wallet->balance_minor);
        $this->assertSame(1200, $wallet->reserved_minor);
        $this->assertSame(48800, $wallet->availableMinor());
    }

    /**
     * Task 4.3 — assigning a non-integer value to `balance_minor` is rejected rather
     * than silently truncated or rounded.
     *
     * Laravel's built-in `int` cast would turn '12.34' into 12 — a silent loss of
     * money. The `IntegerPoisha` cast refuses instead.
     */
    public function test_assigning_a_non_integer_to_the_posted_balance_is_rejected(): void
    {
        $wallet = Wallet::factory()->withBalanceMinor(50000)->create();

        try {
            $wallet->balance_minor = '12.34';

            $this->fail('Expected a non-integer balance assignment to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('balance_minor', $e->getMessage());
            $this->assertStringContainsString('integer number of Poisha', $e->getMessage());
        }

        $this->assertSame(50000, $wallet->balance_minor);
    }

    /**
     * Task 4.3 — the same rejection applies to `reserved_minor`.
     */
    public function test_assigning_a_non_integer_to_the_reserved_balance_is_rejected(): void
    {
        $wallet = Wallet::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $wallet->reserved_minor = 12.34;
    }

    /**
     * Task 4.3 — a float is rejected, not rounded.
     */
    public function test_assigning_a_float_to_the_posted_balance_is_rejected(): void
    {
        $wallet = Wallet::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $wallet->balance_minor = 12.34;
    }

    /**
     * Task 4.3 — an exact integer string is accepted, because it is representable.
     */
    public function test_assigning_an_exact_integer_string_is_accepted(): void
    {
        $wallet = Wallet::factory()->withBalanceMinor(50000)->create();

        $wallet->balance_minor = '1234';

        $this->assertSame(1234, $wallet->balance_minor);
    }

    /**
     * Task 4.1 / 4.2 — every business column is guarded: only the auto-increment id
     * may be mass-assigned.
     */
    public function test_only_the_id_is_mass_assignable(): void
    {
        $wallet = Wallet::factory()->create();

        $this->assertTrue($wallet->isFillable('id'));
        $this->assertFalse($wallet->isFillable('balance_minor'));
        $this->assertFalse($wallet->isFillable('reserved_minor'));
        $this->assertFalse($wallet->isFillable('owner_user_id'));
        $this->assertFalse($wallet->isFillable('account_id'));

        $account = LedgerAccount::factory()->create();

        $this->assertTrue($account->isFillable('id'));
        $this->assertFalse($account->isFillable('owner_user_id'));
        $this->assertFalse($account->isFillable('account_type'));
        $this->assertFalse($account->isFillable('next_sequence_number'));
    }

    /**
     * Task 4.2 — a mass-assigned balance does not reach the model.
     */
    public function test_a_mass_assigned_balance_is_not_applied(): void
    {
        $wallet = Wallet::factory()->withBalanceMinor(50000)->create();

        $wallet->fill(['balance_minor' => 999999]);

        $this->assertSame(50000, $wallet->balance_minor);
    }

    /**
     * Task 4.2 — the wallet↔account relation resolves.
     */
    public function test_the_wallet_resolves_its_account(): void
    {
        $wallet = Wallet::factory()->create();

        $account = $wallet->account;

        $this->assertInstanceOf(LedgerAccount::class, $account);
        $this->assertSame($wallet->account_id, $account->id);
    }

    /**
     * Task 4.2 — the account↔wallet relation resolves.
     */
    public function test_the_account_resolves_its_wallet(): void
    {
        $wallet = Wallet::factory()->create();

        $account = LedgerAccount::findOrFail($wallet->account_id);

        $this->assertInstanceOf(Wallet::class, $account->wallet);
        $this->assertSame($wallet->id, $account->wallet->id);
    }

    /**
     * Task 4.2 — the owner relation resolves.
     */
    public function test_the_wallet_resolves_its_owner(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()->forOwner($user)->create();

        $this->assertInstanceOf(User::class, $wallet->owner);
        $this->assertSame($user->id, $wallet->owner->id);
    }

    /**
     * Task 4.4 — the owner reads their own wallet balance.
     */
    public function test_the_owner_reads_their_own_wallet(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()->forOwner($user)->withBalanceMinor(50000)->create();

        $found = Wallet::forOwner($user->id)->find($wallet->id);

        $this->assertNotNull($found);
        $this->assertSame(50000, $found->balance_minor);
    }

    /**
     * Task 4.4 — a peer querying for another user's wallet receives no row.
     */
    public function test_a_peer_querying_for_another_users_wallet_receives_no_row(): void
    {
        $owner = User::factory()->create();
        $peer = User::factory()->create();

        $wallet = Wallet::factory()->forOwner($owner)->withBalanceMinor(50000)->create();

        $found = Wallet::forOwner($peer->id)->find($wallet->id);

        $this->assertNull($found);
        $this->assertSame(0, Wallet::forOwner($peer->id)->count());
        $this->assertSame(1, Wallet::forOwner($owner->id)->count());
    }

    /**
     * Task 4.4 — the scope does not disclose the balance value itself.
     */
    public function test_the_owner_scope_does_not_disclose_a_peer_balance(): void
    {
        $owner = User::factory()->create();
        $peer = User::factory()->create();

        Wallet::factory()->forOwner($owner)->withBalanceMinor(50000)->create();

        $results = Wallet::forOwner($peer->id)->get();

        $this->assertCount(0, $results);
        $this->assertStringNotContainsString('50000', json_encode($results->toArray()));
    }

    /**
     * Task 4.1 — the money cast is applied to the columns that carry money.
     */
    public function test_the_money_columns_use_the_integer_poisha_cast(): void
    {
        $wallet = new Wallet;
        $account = new LedgerAccount;

        $walletCasts = $wallet->getCasts();
        $accountCasts = $account->getCasts();

        $this->assertSame(IntegerPoisha::class, $walletCasts['balance_minor']);
        $this->assertSame(IntegerPoisha::class, $walletCasts['reserved_minor']);
        $this->assertSame(IntegerPoisha::class, $accountCasts['next_sequence_number']);
        $this->assertSame(LedgerAccountType::class, $accountCasts['account_type']);
        $this->assertSame(LedgerSystemRole::class, $accountCasts['system_role']);
    }
}
