<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four payment methods available on the "Add Money" screen.
 *
 * **None of these contacts a real gateway.** There is no bKash API, no Nagad API, no bank
 * transfer API and no card network behind any of them. Each is a **simulated gateway** whose
 * outcome the server decides.
 *
 * The labels read as the real-world instruments because the journey is a payment
 * application. The simulation is disclosed once, in the page chrome, as a sandbox
 * indicator — not repeated on every screen.
 */
enum FundingMethod: string
{
    case BKASH = 'BKASH';
    case NAGAD = 'NAGAD';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case CARD = 'CARD';

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
            self::BKASH => 'bKash',
            self::NAGAD => 'Nagad',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::CARD => 'Debit / Credit Card',
        };
    }

    /**
     * A one-line description of what the payment screen asks for.
     */
    public function description(): string
    {
        return match ($this) {
            self::BKASH => 'Pay from your bKash wallet using your mobile number.',
            self::NAGAD => 'Pay from your Nagad wallet using your mobile number.',
            self::BANK_TRANSFER => 'Transfer from your bank account to the funding account.',
            self::CARD => 'Pay with your debit or credit card.',
        };
    }

    /**
     * The kind of input this method collects, which drives the screen's fields.
     */
    public function inputKind(): string
    {
        return match ($this) {
            self::BKASH, self::NAGAD => 'MOBILE',
            self::BANK_TRANSFER => 'BANK',
            self::CARD => 'CARD',
        };
    }
}
