<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a "Withdraw Money" attempt.
 *
 * The shape mirrors `FundingStatus` exactly, because it is the same kind of thing: an
 * attempt at an external payout whose outcome the server owns.
 */
enum WithdrawalStatus: string
{
    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Awaiting confirmation',
            self::SUCCEEDED => 'Payment Successful',
            self::FAILED => 'Payment Failed',
            self::CANCELLED => 'Payment Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::PENDING;
    }

    /**
     * Whether this status may transition to the given one.
     *
     * Only a PENDING attempt may move, and only to a terminal state. That is what
     * makes a replayed settlement a no-op.
     */
    public function canTransitionTo(self $next): bool
    {
        return $this === self::PENDING && $next->isTerminal();
    }
}
