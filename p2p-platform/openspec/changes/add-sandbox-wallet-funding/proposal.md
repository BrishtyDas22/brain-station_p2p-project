# Proposal

## Why

The previous change (`add-demo-payment-subsystem`) modelled "Add Money" as a **parallel demo
balance** in its own tables, deliberately isolated from the authoritative ledger. It worked, but
it left the product's most important journey unrepresentative: the money a user "adds" never
touches the wallet they actually spend from, so the Dashboard, the Wallet and the transaction
history never move, and the real double-entry accounting is never exercised.

The business requirement is a **professional P2P payment application with ONE MAIN WALLET**. The
only thing that is genuinely unavailable is the **external provider** — the official bKash,
Nagad, bank and card APIs cannot be integrated in this environment. The gateway is therefore
simulated; **the accounting is not**.

This change replaces the parallel demo balance with a **local sandbox funding policy**: a
simulated gateway that tops up the user's real, ledger-backed main wallet through a properly
balanced pair of `ledger_entries`.

## The decision this change records

**H-1 (SYSTEM ledger accounts and the funding source) is 🟠 Proposed and NOT approved.**
**C-4** records the consequence: *"Funding cannot be modelled honestly yet. Without SYSTEM
accounts, there is no source for a wallet's first balance."*

This change **does not approve H-1**. It records a **narrower, local, development-only** policy
that the user has explicitly authorized: a **sandbox funding source** used solely to exercise
the Add Money journey in a local development and demonstration environment.

The distinction that matters:

| | This change | Production funding |
|---|---|---|
| Approval state | H-1 🟠 Proposed — **not approved here** | Requires H-1 to be approved |
| Funding source | One `SYSTEM`/`FUNDING` ledger account | Requires a decided, compliant source |
| Reachability | Sandboxed Add Money routes only | Every money-movement path |
| Money | None. No provider is contacted | Real |
| Ledger | Real, balanced, append-only | Real, balanced, append-only |

The funding account is a **source of funds, never a destination** — crediting it would create
money with no external inflow behind it, and `LedgerPostingService` refuses that outright.

## What Changes

- **Add a `wallet_fundings` table** — one row per funding attempt. Carries a unique
  server-generated reference, the method, the integer-Poisha amount, the **server-controlled**
  status, the owning user, the idempotency key, and the link to the ledger transaction it
  settled into.
- **Add a `WalletFundingService`** that runs the whole flow atomically: validate, create the
  attempt, apply the server-side gateway simulation, and — only on success — post a **balanced
  pair of ledger entries** that credit the user's main wallet.
- **Add a `LedgerPostingService`** that posts balanced double-entry postings to the
  append-only ledger, and advances the `wallets.balance_minor` projection **only after** the
  entries exist, and only by the amount the entries carry.
- **Make the outcome server-owned.** The client sends an *intent* (`CONFIRM`, `FAIL`,
  `CANCEL`); the server decides the outcome. A client-supplied status, balance, credited
  amount or reference is stripped before validation.
- **Make crediting exactly-once.** A conditional `UPDATE ... WHERE status = 'PENDING'` gates
  the whole credit, so a replayed or concurrent settlement posts the entry pair exactly once.
- **Add four payment methods** — bKash, Nagad, Bank Transfer and Debit / Credit Card — with
  professional labels, a method-specific input screen, and **no** real credential ever
  collected, transmitted or stored.
- **Credit the MAIN wallet** — a successful payment creates a real `transactions` row whose
  recipient is the user's ledger account, so the existing Dashboard, Wallet and Transactions
  pages show the credit with no change to their controller logic.
- **Remove the parallel demo subsystem** from the active UI and the code. The
  `demo_payments` and `demo_balances` **tables and their rows are left in place**, unmodified,
  for audit. The previously displayed ৳15,000 demo balance is **not** credited to the main
  wallet.
- **Remove every "Demo" label from the active UI** — no separate demo balance, no "Add Demo
  Money", no "Demo bKash", no orange simulation banners, no duplicate balance cards. A single
  accurate sandbox indicator sits in the page chrome, outside the payment interface.

## What is NOT changed

- **`../P2P_SPECIFICATION.md` is not modified.**
- **`.env` is not modified.**
- **No production money movement is enabled.** Transfers, settlement, withdrawal and reversal
  are untouched and cannot reach the sandbox funding account.
- **No ledger constraint is weakened.** The append-only triggers, the immutable
  wallet-to-account trigger and every `CHECK` constraint are intact.
- **H-1 is not approved, and C-4 is not resolved.**

## Impact

- Affected specs: `wallet/funding` (new)
- Affected code: the `wallet/fundings` route group, `WalletFundingController`,
  `WalletFundingService`, `LedgerPostingService`, `WalletFunding`, `FundingMethod`,
  `FundingStatus`, `FundingIntent`, `FundingDetails`, `config/p2p-funding.php`, and the
  Dashboard / Wallet / Transactions pages.
- Affected data: one new table (`wallet_fundings`). The `demo_payments` and `demo_balances`
  tables and all their rows are **left in place**.
