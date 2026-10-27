<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KycStatus;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KycSubmission>
 */
class KycSubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => KycStatus::PENDING,
            'full_name' => fake()->name(),
            'document_type' => 'National ID (NID)',
            'document_number' => (string) fake()->unique()->numerify('##########'),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years'),
            'address_line' => fake()->streetAddress(),
            'city' => 'Dhaka',
            'country' => 'BD',
            'mobile_number' => '+8801'.fake()->numerify('#########'),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'risk_score' => null,
        ];
    }

    /**
     * A submission that has been approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => KycStatus::APPROVED,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now(),
            'risk_score' => fake()->numberBetween(0, 30),
        ]);
    }

    /**
     * A submission that has been rejected, with the reason recorded.
     */
    public function rejected(?string $reason = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => KycStatus::REJECTED,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now(),
            'rejection_reason' => $reason ?? 'The document image was unreadable.',
        ]);
    }

    /**
     * A submission that has been picked up by a reviewer.
     */
    public function inReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => KycStatus::IN_REVIEW,
        ]);
    }
}
