/**
 * Page metadata for the P2P shell.
 *
 * The shell renders a title and description in the top bar. The title is
 * derived from the current route path by a lookup table, so it is always
 * correct without every page having to wire anything up. A page can still
 * override the description by passing one to the layout through the
 * `usePageDescription` hook.
 */

import { useEffect, useState } from 'react';

/**
 * The route-path → title map.
 *
 * The longest matching prefix wins, so `/admin/users` resolves to
 * "User management" rather than "Admin overview".
 */
const TITLE_MAP: readonly (readonly [prefix: string, title: string])[] = [
    ['/admin/users', 'User management'],
    ['/admin/kyc', 'KYC review'],
    ['/admin/monitoring', 'Transaction monitoring'],
    ['/admin/settlement', 'Settlement'],
    ['/admin/reversals', 'Reversals & disputes'],
    ['/admin/audit', 'Ledger & audit'],
    ['/admin/reconciliation', 'Reconciliation'],
    ['/admin/reports', 'Reports'],
    ['/admin', 'Admin overview'],
    ['/transactions', 'Transactions'],
    ['/wallet/withdrawals', 'Withdraw money'],
    ['/wallet', 'Wallet'],
    ['/loans/investments', 'My investments'],
    ['/loans/my', 'My loans'],
    ['/loans', 'Loan marketplace'],
    ['/receive', 'Receive money'],
    ['/kyc', 'KYC verification'],
    ['/notifications', 'Notifications'],
    ['/settings/profile', 'Profile'],
    ['/settings/security', 'Security'],
    ['/settings/appearance', 'Appearance'],
    ['/dashboard', 'Dashboard'],
];

/**
 * Derive a human title from a URL path.
 */
export function titleForPath(path: string): string {
    const clean = path.split('?')[0].replace(/\/+$/, '') || '/';

    for (const [prefix, title] of TITLE_MAP) {
        if (clean === prefix || clean.startsWith(prefix + '/')) {
            return title;
        }
    }

    return 'P2P Settle';
}

/**
 * The description currently declared by the page.
 *
 * A page calls this hook once; the layout reads the value through
 * `usePageDescriptionValue`.
 */
let currentDescription = '';

/**
 * Declare the current page's description.
 */
export function usePageDescription(description: string): void {
    const [value] = useState(description);

    useEffect(() => {
        currentDescription = value;

        return () => {
            currentDescription = '';
        };
    }, [value]);
}

/**
 * Read the description declared by the current page.
 */
export function usePageDescriptionValue(): string {
    const [, force] = useState(0);

    useEffect(() => {
        // Re-render whenever the page's effect has had a chance to set it.
        const timer = window.setTimeout(() => force((n) => n + 1), 0);

        return () => window.clearTimeout(timer);
    }, []);

    return currentDescription;
}
