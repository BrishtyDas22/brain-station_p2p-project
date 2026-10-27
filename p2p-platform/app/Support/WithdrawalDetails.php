<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\WithdrawalMethod;

/**
 * The minimal, storable form of a withdrawal destination.
 *
 * This is the mirror image of `FundingDetails`, and it obeys the same rule: **no real
 * credential is ever collected or stored.** A mobile number is reduced to its last four
 * digits, and a bank account to its last four. A PIN, an OTP, a password, a CVV or a
 * full card number is refused outright rather than silently dropped, so a client that
 * sends one gets an error instead of a false sense of safety.
 */
final class WithdrawalDetails
{
    /**
     * The keys a withdrawal request may carry.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'mobile_number',
        'bank',
        'account_number',
        'account_holder',
    ];

    /**
     * The keys a withdrawal request must never carry.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'pin',
        'otp',
        'password',
        'cvv',
        'card_number',
        'card_last_four',
        'card_holder',
        'expiry',
    ];

    /**
     * Reduce a withdrawal request's inputs to their minimal storable form.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function for(WithdrawalMethod $method, array $input): array
    {
        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw new \InvalidArgumentException(sprintf(
                    'A withdrawal request must not include "%s". This platform never collects a PIN, an OTP, a password or card details.',
                    $forbidden,
                ));
            }
        }

        $filtered = array_intersect_key($input, array_flip(self::ALLOWED_KEYS));

        return match ($method) {
            WithdrawalMethod::BKASH, WithdrawalMethod::NAGAD => [
                'mobile_number' => self::maskMobile((string) ($filtered['mobile_number'] ?? '')),
            ],
            WithdrawalMethod::BANK_TRANSFER => [
                'bank' => trim((string) ($filtered['bank'] ?? '')),
                'account_number' => self::maskAccount((string) ($filtered['account_number'] ?? '')),
                'account_holder' => trim((string) ($filtered['account_holder'] ?? '')),
            ],
        };
    }

    /**
     * The banks a bank transfer may target.
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
     * Keep only the last four digits of a mobile number.
     */
    private static function maskMobile(string $mobileNumber): string
    {
        $digits = preg_replace('/\D+/', '', $mobileNumber) ?? '';

        if (strlen($digits) < 4) {
            return '';
        }

        return '••••'.substr($digits, -4);
    }

    /**
     * Keep only the last four digits of a bank account number.
     */
    private static function maskAccount(string $accountNumber): string
    {
        $digits = preg_replace('/\D+/', '', $accountNumber) ?? '';

        if (strlen($digits) < 4) {
            return '';
        }

        return '••••'.substr($digits, -4);
    }
}
