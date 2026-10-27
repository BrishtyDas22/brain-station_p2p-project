<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the user asked the simulated gateway to do.
 *
 * The client sends an **intent**, never an outcome. The server maps the intent to a
 * terminal status and performs the transition. No request field is ever read as a status,
 * an amount, a balance, a credit or a reference.
 */
enum FundingIntent: string
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
    public function targetStatus(): FundingStatus
    {
        return match ($this) {
            self::CONFIRM => FundingStatus::SUCCEEDED,
            self::FAIL => FundingStatus::FAILED,
            self::CANCEL => FundingStatus::CANCELLED,
        };
    }

    /**
     * The reason recorded when the intent is `FAIL`.
     */
    public function failureReason(): ?string
    {
        return match ($this) {
            self::FAIL => 'The payment was declined by the payment provider.',
            self::CONFIRM, self::CANCEL => null,
        };
    }
}
