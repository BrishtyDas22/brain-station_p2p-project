<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of a KYC submission.
 *
 * The lifecycle is:
 *
 *   NOT_SUBMITTED → PENDING → IN_REVIEW → APPROVED
 *                                  ↘ REJECTED
 *
 * A REJECTED submission may be re-submitted, which returns it to PENDING with
 * a fresh row's worth of identity data.
 */
enum KycStatus: string
{
    case NOT_SUBMITTED = 'NOT_SUBMITTED';
    case PENDING = 'PENDING';
    case IN_REVIEW = 'IN_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::NOT_SUBMITTED => 'Not submitted',
            self::PENDING => 'Pending review',
            self::IN_REVIEW => 'In review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }

    /**
     * Whether a submission in this state may be advanced to the given state.
     *
     * The transition table is the authorisation boundary for KYC. It is
     * expressed once, here, so no controller can invent its own rules.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::NOT_SUBMITTED => in_array($next, [self::PENDING], true),
            self::PENDING => in_array($next, [self::IN_REVIEW, self::REJECTED], true),
            self::IN_REVIEW => in_array($next, [self::APPROVED, self::REJECTED], true),
            self::REJECTED => in_array($next, [self::PENDING], true),
            self::APPROVED => false,
        };
    }
}
