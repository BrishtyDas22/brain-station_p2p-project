<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a loan request.
 *
 * The states are the PRD's own names where the PRD names them:
 *
 *   MARKETPLACE_LISTED — the listing is live and lenders may commit capital. This is the
 *                        PRD's "MARKETPLACE_LISTED" (User Story 3.1).
 *   DISBURSED          — the listing reached 100% funding and the principal was released
 *                        to the borrower's spendable wallet. This is the PRD's
 *                        "DISBURSED" (User Story 3.2).
 *   REPAYING           — the disbursed loan is being repaid on its installment schedule.
 *   REPAID             — every installment has been collected.
 *   DEFAULTED          — the borrower has failed to pay; the PRD's §4.1 default path.
 *   CANCELLED          — the listing was withdrawn before it was funded.
 *
 * The transition table is expressed once, here, so no controller can invent its own
 * rules — the same pattern the KYC status enum uses.
 */
enum LoanStatus: string
{
    case MARKETPLACE_LISTED = 'MARKETPLACE_LISTED';
    case DISBURSED = 'DISBURSED';
    case REPAYING = 'REPAYING';
    case REPAID = 'REPAID';
    case DEFAULTED = 'DEFAULTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::MARKETPLACE_LISTED => 'Open for funding',
            self::DISBURSED => 'Funded — awaiting first repayment',
            self::REPAYING => 'Repaying',
            self::REPAID => 'Repaid',
            self::DEFAULTED => 'Defaulted',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Whether a listing in this state accepts new lender commitments.
     */
    public function acceptsFunding(): bool
    {
        return $this === self::MARKETPLACE_LISTED;
    }

    /**
     * Whether the transition to the given state is legal.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::MARKETPLACE_LISTED => in_array(
                $next,
                [self::DISBURSED, self::CANCELLED],
                true
            ),
            self::DISBURSED => in_array(
                $next,
                [self::REPAYING, self::REPAID, self::DEFAULTED],
                true
            ),
            self::REPAYING => in_array(
                $next,
                [self::REPAID, self::DEFAULTED],
                true
            ),
            self::REPAID => false,
            self::DEFAULTED => false,
            self::CANCELLED => false,
        };
    }
}
