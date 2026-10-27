# Design

## Context

The system has a real wallet (`wallets`), a real ledger (`ledger_entries`, append-only, with
BEFORE UPDATE / DELETE triggers raising SQLSTATE 45000) and a real account model
(`ledger_accounts`). The `wallets.balance_minor` column is a **rebuildable projection**; the
ledger entries are the **authority**.

A `ledger_accounts` row is constrained by `ck_ledger_accounts_type`:

```sql
CHECK (
    (account_type = 'USER'   AND owner_user_id IS NOT NULL AND system_role IS NULL) OR
    (account_type = 'SYSTEM' AND owner_user_id IS NULL     AND system_role IS NOT NULL)
)
```

So a `SYSTEM` account has **no owner** and a role. That is precisely the shape a funding source
needs, and precisely what **H-1** has not yet approved.

## Goals

1. A successful simulated payment credits the user's **real** main wallet, through **real**
   double-entry postings.
2. The money path is safe: balanced entries, integer Poisha, no direct balance assignment, no
   double credit, no cross-user leakage.
3. The sandbox boundary is **enforceable in code**, not merely documented.
4. Nothing about production funding is silently approved.

## Non-goals

- Approving H-1, resolving C-4, deciding C-3, M-2 or M-3.
- Any real provider integration, real money movement, or withdrawal/settlement/reversal
  capability.
- Changing the ledger schema, its constraints or its triggers.

## Decisions

### D-1 — The funding source is a `SYSTEM` / `FUNDING` ledger account, created lazily

The counterparty of a funding posting is one `SYSTEM` account with the `FUNDING` role and
`owner_user_id = NULL`. It is created lazily on the first funding and reused thereafter, so
exactly one such account ever exists.

**Why:** it is the only shape the `ck_ledger_accounts_type` constraint permits for an account
with no owner, and using it keeps the funding on the **same** ledger as everything else — one
balance sheet, one hash chain, one reconciliation.

**Consequence:** this account is an *external liability*, not a user balance. It holds no user
money and is never a destination.

### D-2 — A `SYSTEM` account is a source, never a destination

`LedgerPostingService::postPair()` refuses any posting whose destination is a `SYSTEM` account.
Crediting it would create money with no inflow behind it — the one thing a balanced ledger
forbids.

**Why:** this is the tripwire that keeps the sandbox funding account out of every production
money-movement path, in code, rather than by convention.

### D-3 — The funding account's `balance_after` is its cumulative sourced amount

`ledger_entries.balance_after` is `BIGINT UNSIGNED` and constrained by
`ck_ledger_entries_balance CHECK (balance_after IS NULL OR balance_after >= 0)`. A running
balance for the funding account would go negative on its first posting and could not be
written.

**Decision:** for a wallet-less `SYSTEM` account, `balance_after` records the **cumulative
amount it has sourced** (its total debits). This only grows, is always non-negative, and is
the honest description of an external source.

**For a user account**, `balance_after` remains the running balance, exactly as before.

### D-4 — The wallet projection moves by a delta, after the entries exist

`wallets.balance_minor` is never assigned an arbitrary number. The posting service appends both
entries first, then advances the projection by `balance_minor + delta` under a row lock, and
refuses a negative result.

**Why:** the projection must always be re-derivable from the entries. If the entries exist, the
projection is a cache; if the projection moves first, money can exist with no entries behind it.

### D-5 — The outcome is server-owned; the client sends an intent

The client submits `intent ∈ {CONFIRM, FAIL, CANCEL}`. The server maps the intent to a terminal
state and performs the transition. `WalletFundingStoreRequest::prepareForValidation()` rebuilds
the payload from an explicit allow-list and strips `status`, `balance_minor`, `credited_minor`,
`amount_minor`, `reference`, `owner_user_id`, `wallet_id`, `transaction_id`, `account_id`,
`pin`, `otp`, `password` and `cvv` before validation even runs.

**Why:** a client that can assert an outcome can mint money.

### D-6 — Crediting is exactly-once via a conditional update

The settlement transition is a conditional
`UPDATE wallet_fundings SET status = ... WHERE id = ? AND status = 'PENDING'`. Only a writer
whose update actually changed a row proceeds to `credit()`. The funding row, the wallet row and
both ledger accounts are locked `FOR UPDATE`, and the accounts are locked in **sorted id order**
to make a deadlock between two postings impossible.

**Why:** a replayed request, a double-clicked button and two concurrent confirmations are all
the same problem — only one writer may pass the gate.

### D-7 — A unique idempotency key makes a replayed creation a no-op

`uq_wallet_fundings_idempotency_key` is unique. A replayed creation request returns the original
attempt; the fast-path read is an optimisation, the index is the guarantee.

### D-8 — The funding is visible in the main transaction history

A successful funding creates a real `transactions` row with the user's ledger account as the
recipient and the note `Add Money — sandbox gateway funding`. The existing
`TransactionController` scoping (`sender_account_id = X OR recipient_account_id = X`) therefore
surfaces it with **no controller change**, and `kind` / `kindLabel` distinguish it from a peer
transfer.

**Why:** the requirement is that the user sees one transaction history, not a parallel one.

### D-9 — Amounts are integer Poisha, parsed by string arithmetic

The client sends a Taka **string**. `DemoAmount::toPoisha()` converts it with string
arithmetic, so "exactly ৳5,000" is provable and no float ever touches the value.

### D-10 — No real credential is ever collected or stored

There is no field for a PIN, an OTP, a bank password or an account password. The card screen
accepts **only** the documented fictional test card: a different number, expiry or security
code is refused. Of the card, only `card_last_four` and `card_holder` are stored — never the
security code, the expiry or the number.

### D-11 — The sandbox indicator appears once, in the page chrome

A single short, accurate line — "Sandbox environment — the payment gateway is simulated and no
real money is transferred." — appears in the page chrome of the Add Money, receipt and history
screens. It is **not** repeated on every card, row and panel, because a payment application that
repeats a warning on every surface reads as a prototype rather than a product. It is **not**
inside the payment interface itself.

**Why:** the requirement is "do not falsely claim a real external provider processed the
payment", which one accurate line satisfies, and "no repetitive banners", which it does not
violate.

## Risks

| Risk | Mitigation |
|---|---|
| The sandbox funding is mistaken for production funding | Documented in `config/p2p-funding.php`, the service docblocks, this change and the epic memlog. The account is `SYSTEM`/`FUNDING` and every screen says "Sandbox environment". |
| A future story routes a production path through the funding account | D-2's tripwire refuses a `SYSTEM` account as a destination, and a test asserts the funding account never appears in a peer transfer. |
| The funding account's balance is mistaken for a user balance | It has no wallet, no owner, and no page that presents it. |
| M-2 / M-3 remain undecided | The chain uses a **local, documented** canonical form and a **local, documented** genesis constant. Neither is claimed to be the specification's rule. |

## Migration Plan

One additive migration creates `wallet_fundings`. It changes no existing table, no constraint,
no index and no trigger. The `demo_payments` and `demo_balances` tables and their rows are left
in place, untouched, for audit.

Rollback drops `wallet_fundings` only. The `ledger_entries` written by a funding are
append-only and **cannot** be rolled back — by design, and by the triggers.

## Open Questions

None blocking. The open specification decisions (H-1, C-3, C-4, M-2, M-3) remain open; this
change records a local sandbox policy around them and approves none of them.
