<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a money column to an integer number of Poisha.
 *
 * Money in this system is always integer minor units — 1 BDT = 100 Poisha. Laravel's
 * built-in `int` cast would silently truncate `'12.34'` to `12` and quietly round a
 * value that was never representable in the money path. This cast refuses instead.
 *
 * Accepted: an `int`, or a string that is an exact integer (`'1200'`, `'-5'`).
 * Rejected: floats, decimal strings, booleans, arrays, objects — anything that would
 * require truncation or rounding to be stored.
 *
 * `null` is accepted and passed through unchanged, so the cast may be applied to a
 * **nullable** money column. `ledger_entries.balance_after` is such a column: a null
 * balance is a meaningful, distinct value — "not computed" — and it must never be
 * silently coerced to `0`. The `get()` side returns `null` for a null column, so the
 * round trip is lossless in both directions.
 *
 * The storage column is `BIGINT UNSIGNED`, so a negative value is additionally
 * unrepresentable at the data layer (the column type is the constraint).
 *
 * @implements CastsAttributes<int|null, mixed>
 */
final class IntegerPoisha implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        // A null money value is passed through untouched. A nullable money column
        // carries a real "not computed" state, and coercing it to 0 would assert a
        // balance that was never established.
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf(
            '%s::$%s must be an integer number of Poisha. %s given.',
            $model::class,
            $key,
            get_debug_type($value),
        ));
    }
}
