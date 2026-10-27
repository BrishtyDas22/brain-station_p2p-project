<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the user asked the simulated payout gateway to do.
 *
 * The shape mirrors `FundingIntent`, and it obeys the same rule: **the client sends an
 * intent, never an outcome.** The server maps the intent to a terminal status and
 * performs the transition.
 *
 * A withdrawal has its own intent enum rather than reusing `FundingIntent`, because a
 * payout out of the wallet is a different thing from a payment into it, and because the
 * two lifecycles have different status enums (`WithdrawalStatus` vs `FundingStatus`).
 * Sharing one enum would force a type confusion between the two.
 */
enum WithdrawalIntent: string
{
    case CONFIRM = 'CONFIRM';
    case FAIL = 'FAIL';
    case CANCEL = 'CANCEL';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The terminal state this intent asks for.
     */
    public function targetStatus(): WithdrawalStatus
    {
        return match ($this) {
            self::CONFIRM => WithdrawalStatus::SUCCEEDED,
            self::FAIL => WithdrawalStatus::FAILED,
            self::CANCEL => WithdrawalStatus::CANCELLED,
        };
    }

    /**
     * The reason recorded when the intent is `FAIL`.
     */
    public function failureReason(): ?string
    {
        return match ($this) {
            self::FAIL => 'The payout was declined by the payment provider.',
            self::CONFIRM, self::CANCEL => null,
        };
    }
}
