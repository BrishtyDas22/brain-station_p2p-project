<?php

namespace App\Enums;

/**
 * Which side of the double entry a ledger entry is.
 *
 * SPEC §4.1: every transfer produces exactly two entries of equal magnitude and
 * opposite direction, and `SUM(DEBIT) = SUM(CREDIT) = 0` for every `transaction_id`.
 *
 * The backing values are exactly the values the `ledger_entries.direction` column
 * accepts (`ENUM('DEBIT','CREDIT')`). They are not free-form strings: the database
 * rejects anything else.
 */
enum LedgerDirection: string
{
    case DEBIT = 'DEBIT';

    case CREDIT = 'CREDIT';

    /**
     * The opposite side of the entry — the other half of the double entry.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::DEBIT => self::CREDIT,
            self::CREDIT => self::DEBIT,
        };
    }
}
