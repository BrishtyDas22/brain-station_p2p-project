<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Dispute;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'opened_by' => User::factory(),
            'assigned_to' => null,
            'subject' => fake()->sentence(4),
            'category' => fake()->randomElement(['PAYMENT', 'AMOUNT', 'DELAY', 'OTHER']),
            'status' => 'OPEN',
            'priority' => 'MEDIUM',
            'description' => fake()->paragraph(),
            'resolved_at' => null,
        ];
    }

    /**
     * A dispute that has been resolved.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'RESOLVED',
            'assigned_to' => User::factory()->admin(),
            'resolved_at' => now(),
        ]);
    }

    /**
     * A dispute that has been escalated.
     */
    public function escalated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'ESCALATED',
            'priority' => 'HIGH',
            'assigned_to' => User::factory()->admin(),
        ]);
    }
}
