<?php

namespace Database\Factories;

use App\Enums\LedgerDirection;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entry_id' => (string) Str::ulid(),
            'transaction_id' => 1,
            'account_id' => LedgerAccount::factory(),
            'direction' => LedgerDirection::DEBIT,
            'amount_minor' => 100,
            'currency' => 'BDT',
            'account_sequence_number' => 1,
            'balance_after' => null,
            'prev_hash' => str_repeat('0', 64),
            'entry_hash' => hash('sha256', (string) Str::ulid()),
            'created_at' => now(),
        ];
    }

    /**
     * An entry posting to the given account at the given sequence position.
     */
    public function forAccount(LedgerAccount|int $account, int $sequenceNumber): self
    {
        $accountId = $account instanceof LedgerAccount ? $account->id : $account;

        return $this->state(fn (array $attributes): array => [
            'account_id' => $accountId,
            'account_sequence_number' => $sequenceNumber,
        ]);
    }

    /**
     * An entry on the other side of the double entry.
     */
    public function direction(LedgerDirection $direction): self
    {
        return $this->state(fn (array $attributes): array => [
            'direction' => $direction,
        ]);
    }

    /**
     * An entry of the given magnitude in integer Poisha.
     */
    public function withAmountMinor(int $amountMinor): self
    {
        return $this->state(fn (array $attributes): array => [
            'amount_minor' => $amountMinor,
        ]);
    }

    /**
     * An entry carrying a non-null `balance_after`.
     *
     * `balance_after` is non-authoritative and is excluded from the chained body
     * (SPEC §5.2). It is populated here so the model layer can prove it survives a
     * round-trip without becoming part of the hash contract.
     */
    public function withBalanceAfter(?int $balanceAfter): self
    {
        return $this->state(fn (array $attributes): array => [
            'balance_after' => $balanceAfter,
        ]);
    }
}
