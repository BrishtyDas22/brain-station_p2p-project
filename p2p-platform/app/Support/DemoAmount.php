<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Parses a Taka amount into integer Poisha, without a float ever being involved.
 *
 * Money in this system is always integer minor units — 1 BDT = 100 Poisha (A2). The browser
 * sends a decimal **string** and the server parses it here, so that "exactly the approved
 * amount" is provable rather than approximate.
 *
 * The naive `((float) $taka) * 100` is wrong: `5000.7 * 100` is `500070.00000000006`, and
 * rounding that to an integer is a coin flip. This parser reads the whole part and the
 * fraction as **strings** and composes the Poisha value arithmetically, so `5000.7` is
 * exactly `500070` and `5000.70` is exactly `500070` too.
 *
 * A fraction with more than two decimal places is refused: it is not a representable amount
 * of money, and silently truncating it would be inventing value.
 */
final class DemoAmount
{
    /**
     * Parse a Taka string into integer Poisha.
     *
     * @throws InvalidArgumentException when the string is not a positive, representable amount
     */
    public static function toPoisha(string $taka): int
    {
        $trimmed = trim($taka);

        if ($trimmed === '') {
            throw new InvalidArgumentException('An amount is required.');
        }

        // A leading sign is refused outright rather than handled: a negative demo
        // amount is nonsense, and a leading "+" is a sign of a hand-typed value
        // that should be re-typed.
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,3}))?$/', $trimmed, $matches)) {
            throw new InvalidArgumentException(
                'The amount must be a positive number in Taka, with at most two decimal places.'
            );
        }

        $whole = $matches[1];
        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > 2) {
            throw new InvalidArgumentException(
                'The amount may have at most two decimal places.'
            );
        }

        // Pad the fraction to exactly two digits: "7" -> "70", "" -> "00".
        $fraction = str_pad($fraction, 2, '0');

        $poisha = ((int) $whole) * 100 + (int) $fraction;

        if ($poisha <= 0) {
            throw new InvalidArgumentException('The amount must be greater than zero.');
        }

        return $poisha;
    }

    /**
     * Whether a Taka string parses to a positive, representable amount.
     */
    public static function isValid(string $taka): bool
    {
        try {
            self::toPoisha($taka);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
