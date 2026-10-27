<?php

namespace Database\Factories;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerSystemRole;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    protected $model = LedgerAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'account_type' => LedgerAccountType::USER,
            'system_role' => null,
            'currency' => 'BDT',
            'next_sequence_number' => 1,
        ];
    }

    /**
     * A USER account owned by the given user.
     */
    public function forOwner(User|int $user): self
    {
        return $this->state(fn (array $attributes): array => [
            'owner_user_id' => $user instanceof User ? $user->id : $user,
            'account_type' => LedgerAccountType::USER,
            'system_role' => null,
        ]);
    }

    /**
     * A SYSTEM account with no owning user.
     *
     * This state exists so the schema's accommodation of H-1 (🟠 Proposed, not approved)
     * is testable. It does NOT create a funding path: H-1 remains unapproved and no
     * production account of this type exists.
     */
    public function system(LedgerSystemRole $role = LedgerSystemRole::FUNDING): self
    {
        return $this->state(fn (array $attributes): array => [
            'owner_user_id' => null,
            'account_type' => LedgerAccountType::SYSTEM,
            'system_role' => $role,
        ]);
    }
}
