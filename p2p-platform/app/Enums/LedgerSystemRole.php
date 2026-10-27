<?php

namespace App\Enums;

/**
 * The role a SYSTEM ledger account plays.
 *
 * Set only on accounts whose `account_type` is `SYSTEM`; always NULL on `USER` accounts.
 * Enforced by `ck_ledger_accounts_type`.
 *
 * The four roles are four genuinely different things:
 *
 *  - `FUNDING` — an **external source** of money. It is only ever debited, and its
 *    `balance_after` records the cumulative amount it has sourced. It is the counterparty
 *    of the sandbox "Add Money" gateway.
 *  - `ESCROW` — **custody** of real user money. It is credited when a lender commits
 *    capital to a loan and debited when that capital is disbursed to a borrower or
 *    returned to a lender. Every entry touching it is backed by a real user wallet
 *    movement, so no money appears from an unexplained opening balance.
 *  - `PAYOUT` — an **external sink**. Money that has left the platform. It is only ever
 *    credited, and its `balance_after` records the cumulative amount it has paid out. It
 *    is the counterparty of the simulated "Withdraw Money" payout. It is the exact
 *    mirror of `FUNDING`: the two are the outside world's wallet.
 *  - `TREASURY` — reserved. Not used by any code path yet.
 *
 * H-1 (🟠 Proposed, not approved) is what this enum would eventually be used for. The
 * `FUNDING` role exists under the separately-authorised **local sandbox funding policy**;
 * the `ESCROW` role exists because the PRD's escrow mechanism requires it; the `PAYOUT`
 * role exists because the PRD's "Withdraw Money" scenario requires a counterparty for
 * money that leaves the platform.
 */
enum LedgerSystemRole: string
{
    case FUNDING = 'FUNDING';
    case TREASURY = 'TREASURY';
    case ESCROW = 'ESCROW';
    case PAYOUT = 'PAYOUT';
}
