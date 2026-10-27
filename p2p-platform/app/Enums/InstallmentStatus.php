<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of one repayment installment.
 *
 * The PRD (Epic 4, User Story 4.1) names three of these directly:
 *
 *   PAID         — "updates the installment status to 'PAID'".
 *   GRACE_PERIOD — "the loan enters a 3-day status of 'GRACE_PERIOD'" after a failed
 *                  collection attempt.
 *   DEFAULTED    — the default path, reached when the grace period expires unpaid.
 *
 * PENDING is the only status an installment is born in.
 */
enum InstallmentStatus: string
{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case GRACE_PERIOD = 'GRACE_PERIOD';
    case DEFAULTED = 'DEFAULTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Upcoming',
            self::PAID => 'Paid',
            self::GRACE_PERIOD => 'Grace period',
            self::DEFAULTED => 'Defaulted',
        };
    }

    /**
     * Whether this installment has already been collected.
     */
    public function isSettled(): bool
    {
        return $this === self::PAID;
    }
}
