<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FundingMethod;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates the method-specific inputs of a funding attempt and reduces them to their
 * minimal, storable form.
 *
 * ## No real payment credential is ever accepted or stored
 *
 * This class is the enforcement point for that promise:
 *
 *  - There is **no** field for a PIN, a one-time password, a bank password, an account
 *    password or a real CVV. The card security code is validated against the fictional
 *    test card's value only, so a real CVV is refused rather than stored.
 *  - The card number is validated against the fictional test card's digits only. A
 *    real-looking card number is refused with a message that states fictional test data
 *    is required.
 *  - The mobile number and the account number are validated as *shapes* — digits and
 *    spaces — and stored purely so the receipt can show what was entered.
 *
 * The details that are **stored** are deliberately minimal: the last four digits of the
 * fictional test card, never the security code, never the expiry, never a PIN.
 */
final class FundingDetails
{
    /**
     * The banks offered on the bank-transfer screen.
     *
     * @return list<string>
     */
    public static function banks(): array
    {
        return [
            'Bank Asia',
            'BRAC Bank',
            'Dutch-Bangla Bank',
            'Standard Chartered',
            'City Bank',
        ];
    }

    /**
     * Validate the inputs for a method and return the minimal, storable details.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function for(FundingMethod $method, array $input): array
    {
        return match ($method) {
            FundingMethod::BKASH, FundingMethod::NAGAD => self::mobileDetails($input),
            FundingMethod::BANK_TRANSFER => self::bankDetails($input),
            FundingMethod::CARD => self::cardDetails($input),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private static function mobileDetails(array $input): array
    {
        $number = (string) ($input['mobile_number'] ?? '');

        if (! preg_match('/^[\d\s\-]{6,20}$/', trim($number))) {
            throw ValidationException::withMessages([
                'mobile_number' => 'Enter the mobile number to continue.',
            ]);
        }

        return [
            'mobile_number' => trim($number),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private static function bankDetails(array $input): array
    {
        $bank = (string) ($input['bank'] ?? '');
        $account = (string) ($input['account_number'] ?? '');

        if (! in_array($bank, self::banks(), true)) {
            throw ValidationException::withMessages([
                'bank' => 'Choose your bank.',
            ]);
        }

        if (! preg_match('/^[\d\-]{4,32}$/', trim($account))) {
            throw ValidationException::withMessages([
                'account_number' => 'Enter your account number to continue.',
            ]);
        }

        return [
            'bank' => $bank,
            'account_number' => trim($account),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private static function cardDetails(array $input): array
    {
        $expected = (string) config('p2p-funding.test_card.digits');
        $expectedExpiry = (string) config('p2p-funding.test_card.expiry');
        $expectedCvv = (string) config('p2p-funding.test_card.cvv');

        $number = self::digits((string) ($input['card_number'] ?? ''));
        $expiry = trim((string) ($input['card_expiry'] ?? ''));
        $cvv = trim((string) ($input['card_cvv'] ?? ''));
        $holder = trim((string) ($input['card_holder'] ?? ''));

        if ($number !== $expected) {
            throw ValidationException::withMessages([
                'card_number' => sprintf(
                    'Use the test card number %s.',
                    (string) config('p2p-funding.test_card.number'),
                ),
            ]);
        }

        if ($expiry !== $expectedExpiry) {
            throw ValidationException::withMessages([
                'card_expiry' => sprintf(
                    'Use the test expiry %s.',
                    $expectedExpiry,
                ),
            ]);
        }

        if ($cvv !== $expectedCvv) {
            throw ValidationException::withMessages([
                'card_cvv' => 'Use the test security code.',
            ]);
        }

        if ($holder === '') {
            throw ValidationException::withMessages([
                'card_holder' => 'Enter the name on the card.',
            ]);
        }

        // Only the last four digits are stored. The security code and the expiry are
        // never kept.
        return [
            'card_last_four' => Str::substr($number, -4),
            'card_holder' => $holder,
        ];
    }

    /**
     * Strip the spaces and dashes a user may type into a card number.
     */
    private static function digits(string $value): string
    {
        return (string) preg_replace('/[^0-9]/', '', $value);
    }
}
