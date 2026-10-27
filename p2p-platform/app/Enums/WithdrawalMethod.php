<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The simulated payout instrument for a "Withdraw Money" request.
 *
 * The PRD's borrower persona "Withdraws approved funds to third-party accounts
 * (Bank / Mobile Financial Services)", so a card is deliberately NOT accepted here —
 * the only card in this platform is the one used to *add* money.
 *
 * **No provider is ever contacted.** These are simulated instruments: the request is
 * settled by the server, exactly like the "Add Money" gateway, and no real account,
 * PIN, OTP or credential is ever collected or stored.
 */
enum WithdrawalMethod: string
{
    case BKASH = 'BKASH';
    case NAGAD = 'NAGAD';
    case BANK_TRANSFER = 'BANK_TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::BKASH => 'bKash',
            self::NAGAD => 'Nagad',
            self::BANK_TRANSFER => 'Bank Transfer',
        };
    }

    /**
     * A one-line description of the destination this method needs.
     */
    public function description(): string
    {
        return match ($this) {
            self::BKASH => 'Send to a bKash account number.',
            self::NAGAD => 'Send to a Nagad account number.',
            self::BANK_TRANSFER => 'Send to a bank account.',
        };
    }

    /**
     * The kind of input this method's form needs.
     */
    public function inputKind(): string
    {
        return match ($this) {
            self::BKASH, self::NAGAD => 'MOBILE',
            self::BANK_TRANSFER => 'BANK',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
