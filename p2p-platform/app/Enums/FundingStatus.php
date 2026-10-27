<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of a funding attempt.
 *
 * A funding attempt is born `PENDING` and reaches exactly one terminal state. The terminal
 * state is decided by the **server**, from the intent the client expressed.
 */
enum FundingStatus: string
{
    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The name shown to the user.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Awaiting confirmation',
            self::SUCCEEDED => 'Payment Successful',
            self::FAILED => 'Payment Failed',
            self::CANCELLED => 'Payment Cancelled',
        };
    }

    /**
     * Whether the state is final. A terminal state never changes.
     */
    public function isTerminal(): bool
    {
        return $this !== self::PENDING;
    }

    /**
     * Whether a transition into this state is legal.
     *
     * The only legal move is out of `PENDING`. Nothing moves out of a terminal state.
     */
    public function canTransitionTo(self $target): bool
    {
        return $this === self::PENDING && $target->isTerminal();
    }
}
