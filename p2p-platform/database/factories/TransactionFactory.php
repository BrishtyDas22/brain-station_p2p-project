<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LedgerDirection;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'TXN-'.Str::upper(Str::random(12)),
            'sender_account_id' => LedgerAccount::factory(),
            'recipient_account_id' => LedgerAccount::factory(),
            'counterparty_name' => fake()->name(),
            'counterparty_account' => (string) fake()->numberBetween(10000000, 99999999),
            'method' => fake()->randomElement(['BANK', 'MOBILE_WALLET', 'CARD']),
            'amount_minor' => fake()->numberBetween(100, 500_000),
            'fee_minor' => 0,
            'currency' => 'BDT',
            'direction' => LedgerDirection::DEBIT,
            'status' => TransactionStatus::CHAIN_PENDING,
            'risk_level' => RiskLevel::LOW,
            'note' => null,
            'idempotency_key' => Str::uuid()->toString(),
            'chain_closed_at' => null,
            'reversed_at' => null,
            'reversal_of_transaction_id' => null,
        ];
    }

    /**
     * A transfer whose chain has closed.
     */
    public function posted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TransactionStatus::POSTED,
            'chain_closed_at' => now(),
        ]);
    }

    /**
     * A transfer that is under review.
     */
    public function underReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TransactionStatus::UNDER_REVIEW,
            'risk_level' => RiskLevel::MEDIUM,
        ]);
    }

    /**
     * A transfer that has been reversed.
     */
    public function reversed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TransactionStatus::REVERSED,
            'reversed_at' => now(),
        ]);
    }

    /**
     * A transfer that failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TransactionStatus::FAILED,
        ]);
    }
}
