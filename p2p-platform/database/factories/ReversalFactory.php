<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Reversal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reversal>
 */
class ReversalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'requested_by' => User::factory(),
            'decided_by' => null,
            'status' => 'REQUESTED',
            'reason' => fake()->sentence(),
            'amount_minor' => fake()->numberBetween(100, 500_000),
            'currency' => 'BDT',
            'compensating_entry_id' => null,
            'decided_at' => null,
            'executed_at' => null,
        ];
    }

    /**
     * A reversal that has been approved but not yet executed.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'APPROVED',
            'decided_by' => User::factory()->admin(),
            'decided_at' => now(),
        ]);
    }

    /**
     * A reversal that has been rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'REJECTED',
            'decided_by' => User::factory()->admin(),
            'decided_at' => now(),
        ]);
    }

    /**
     * A reversal that has been executed.
     */
    public function executed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'EXECUTED',
            'decided_by' => User::factory()->admin(),
            'decided_at' => now(),
            'executed_at' => now(),
        ]);
    }
}
