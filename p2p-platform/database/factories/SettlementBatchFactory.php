<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SettlementBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SettlementBatch>
 */
class SettlementBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->numberBetween(100_00, 500_000);

        $fee = (int) round($total * 0.01);

        return [
            'reference' => 'STL-'.Str::upper(Str::random(10)),
            'network' => fake()->randomElement(['BEFTN', 'NPS', 'IPS']),
            'status' => 'PENDING',
            'entry_count' => fake()->numberBetween(1, 20),
            'total_minor' => $total,
            'fee_minor' => $fee,
            'net_minor' => $total - $fee,
            'currency' => 'BDT',
            'opened_at' => now(),
            'settled_at' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * A batch that has been settled.
     */
    public function settled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'SETTLED',
            'settled_at' => now(),
        ]);
    }

    /**
     * A batch that failed to settle.
     */
    public function failed(?string $reason = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'FAILED',
            'failure_reason' => $reason ?? 'The upstream network rejected the batch.',
        ]);
    }
}
