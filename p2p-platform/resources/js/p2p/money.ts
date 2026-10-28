/**
 * Money formatting for the P2P platform.
 *
 * The backend ledger stores money as an integer count of Poisha (1 BDT = 100
 * Poisha). The frontend mirrors that exactly: every amount crossing the
 * Inertia boundary is a `Minor` integer, and formatting divides by 100 only at
 * the presentation edge. No floating-point money is ever stored.
 */

import type { Minor } from '@/p2p/types';

const POISHA_PER_TAKA = 100;

/**
 * Format Poisha as a BDT amount, e.g. `৳1,250.75`.
 *
 * Integer division and remainder only — no binary floating-point value is
 * ever produced for the taka part.
 */
export function formatMoney(
    minor: Minor,
    options?: { showSymbol?: boolean },
): string {
    const showSymbol = options?.showSymbol ?? true;

    const negative = minor < 0;
    const absolute = Math.abs(minor);

    const taka = Math.floor(absolute / POISHA_PER_TAKA);
    const poisha = absolute % POISHA_PER_TAKA;

    const grouped = new Intl.NumberFormat('en-US', {
        useGrouping: true,
        maximumFractionDigits: 0,
    }).format(taka);

    const body = `${grouped}.${String(poisha).padStart(2, '0')}`;

    return `${negative ? '-' : ''}${showSymbol ? '৳' : ''}${body}`;
}

/**
 * Format a signed amount for a ledger-style debit/credit display.
 */
export function formatSignedMoney(
    minor: Minor,
    direction: 'DEBIT' | 'CREDIT',
): string {
    const sign = direction === 'DEBIT' ? '−' : '+';

    return `${sign}${formatMoney(Math.abs(minor))}`;
}

/**
 * Compact money for dashboard tiles, e.g. `৳12.4K`.
 */
export function formatCompactMoney(minor: Minor): string {
    const taka = minor / POISHA_PER_TAKA;
    const absolute = Math.abs(taka);
    const sign = taka < 0 ? '-' : '';

    if (absolute >= 1_000_000) {
        return `${sign}৳${(absolute / 1_000_000).toFixed(2)}M`;
    }

    if (absolute >= 1_000) {
        return `${sign}৳${(absolute / 1_000).toFixed(1)}K`;
    }

    return formatMoney(minor);
}

/**
 * Convert a typed BDT amount (e.g. `1250.75`) into Poisha.
 *
 * Parsed from the decimal string so user input never becomes a float before
 * being stored. Returns null when the input is not a valid amount.
 */
export function takaToPoisha(input: string): Minor | null {
    const trimmed = input.trim().replace(/,/g, '');

    if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) {
        return null;
    }

    const [whole, fraction = ''] = trimmed.split('.');
    const poisha = fraction.padEnd(2, '0');

    return Number(whole) * POISHA_PER_TAKA + Number(poisha);
}

/**
 * Render Poisha back into a plain decimal string for an input field.
 */
export function poishaToTakaInput(minor: Minor): string {
    const taka = Math.floor(minor / POISHA_PER_TAKA);
    const poisha = minor % POISHA_PER_TAKA;

    return `${taka}.${String(poisha).padStart(2, '0')}`;
}
