<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of a transfer.
 *
 * `transactions.status` is a REBUILDABLE PROJECTION. The ledger entries are
 * the authority; where the two disagree, the LEDGER WINS.
 */
enum TransactionStatus: string
{
    /** Accepted, ledger entries written, awaiting the chain closer. */
    case CHAIN_PENDING = 'CHAIN_PENDING';

    /** The chain-close record exists — the transfer is final on the ledger. */
    case CHAIN_CLOSED = 'CHAIN_CLOSED';

    /** The ledger entry is posted but the chain-close write is outstanding. */
    case POSTED = 'POSTED';

    /** Held for human review — a risk rule or a dispute. */
    case UNDER_REVIEW = 'UNDER_REVIEW';

    /** A compensating entry has been appended. */
    case REVERSED = 'REVERSED';

    /** Never reached the ledger. No entries were written. */
    case FAILED = 'FAILED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::CHAIN_PENDING => 'Pending',
            self::CHAIN_CLOSED => 'Settled',
            self::POSTED => 'Posted',
            self::UNDER_REVIEW => 'Under review',
            self::REVERSED => 'Reversed',
            self::FAILED => 'Failed',
        };
    }

    /**
     * Whether the transfer has reached its final, immutable state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::CHAIN_CLOSED, self::REVERSED, self::FAILED], true);
    }
}
