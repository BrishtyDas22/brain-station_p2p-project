<?php

namespace Database\Factories;

use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'account_id' => LedgerAccount::factory(),
            'currency' => 'BDT',
            'balance_minor' => 0,
            'reserved_minor' => 0,
        ];
    }

    /**
     * A wallet owned by the given user, backed by that user's own ledger account.
     *
     * The pair is created in one transaction by Laravel's factory, so there is no code
     * path that creates a wallet without an account.
     */
    public function forOwner(User|int $user): self
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->state(fn (array $attributes): array => [
            'owner_user_id' => $userId,
            'account_id' => LedgerAccount::factory()->forOwner($userId),
        ]);
    }

    /**
     * A wallet with a posted balance in integer Poisha.
     */
    public function withBalanceMinor(int $balanceMinor): self
    {
        return $this->state(fn (array $attributes): array => [
            'balance_minor' => $balanceMinor,
        ]);
    }

    /**
     * A wallet with a reserved balance in integer Poisha.
     *
     * The reserved balance may not exceed the posted balance —
     * `ck_wallets_reserved_within_balance` enforces that at the data layer.
     */
    public function withReservedMinor(int $reservedMinor): self
    {
        return $this->state(fn (array $attributes): array => [
            'reserved_minor' => $reservedMinor,
        ]);
    }
}
