# Proposal

## Why

The P2P settlement system has a real, working wallet — and a real, working ledger substrate
underneath it — but the wallet balance is permanently **৳0**, and it must stay that way.

That is not an oversight. Decision **H-1** (SYSTEM ledger accounts, the only possible source of a
first balance) is 🟠 **Proposed (not approved)**, and **C-4** records the consequence directly:
*"Funding cannot be modelled honestly yet. Without SYSTEM accounts, there is no source for a
wallet's first balance."* Until H-1 is approved, any real balance in a real wallet would be money
appearing from an unexplained opening balance — precisely the thing H-1 forbids.

The result is a system where the hardest part of the product (money movement) is untestable
end-to-end. A KYC-approved user signs in, opens the Wallet page, sees **৳0**, and cannot exercise
the add-money, receipt, balance and history journeys at all. The business value of the entire
UI — the "Add Money" button, the method picker, the receipt, the dashboard total — is unreachable.

This change adds a **DEMO payment subsystem**: a fully working, database-backed simulation of
"Add Money" that lets a user exercise the whole journey end to end, while the authoritative
ledger-backed balance stays exactly where the specification says it must be.

**The isolation is the whole point.** The demo subsystem has its own tables, its own balance, and
its own money. It writes:

- **no** `ledger_entries`,
- **no** `transactions`,
- **no** SYSTEM ledger account,
- **no** funding entry pair,
- **and it never touches `wallets.balance_minor` or `wallets.reserved_minor`.**

The real wallet balance therefore remains whatever the ledger says it is — today, ৳0 — and it
remains a projection of authoritative ledger entries, never of a demo simulation. The demo balance
is **presentation and testing only**: it is not spendable through transfer, settlement,
withdrawal or reversal, because no such path reads it.

**Why now.** Every downstream story in the initiative (Epic 3 transfer execution, Epic 4
settlement, Epic 5 reconciliation) assumes a user has funds to move. A demo balance that is
strictly separated from the ledger gives those stories a **safe way to be developed and
demonstrated** without a single unapproved financial decision being made on the way.

## What Changes

- **Add a `demo_payments` table** — one row per attempted demo payment. Carries a unique,
  human-readable demo reference, the method, the integer-Poisha amount, the **server-controlled**
  status, the owning user, and the timestamp. It is the audit trail of the demo subsystem.
- **Add a `demo_balances` table** — the demo balance, one row per user, `BIGINT UNSIGNED` integer
  Poisha, with a `CHECK` that it is never negative. This is the demo money, and it is the *only*
  balance the demo subsystem writes.
- **Add a server-controlled payment state machine.** A demo payment moves
  `PENDING → SUCCEEDED | FAILED | CANCELLED`, and only the **server** may move it. The client
  may *request* an outcome; it may never *assert* one. A client-supplied "success" is a request
  for the server to simulate one, and the server decides.
- **Add a `DemoPaymentService`** that performs the whole flow atomically: validate, create the
  payment, apply the server-side simulation, credit the demo balance exactly once, and record the
  outcome — all inside one transaction, guarded by a row lock.
- **Make crediting idempotent.** A second submission of the same payment id, a replayed request,
  or two concurrent confirmations credit the demo balance **once**. The unique index on the payment
  reference plus the status transition rule make double-crediting impossible.
- **Add four simulated payment methods** — Demo bKash, Demo Nagad, Demo Bank Transfer, Demo
  Debit/Credit Card — each with its own screen, its own clearly fictional inputs, and its own
  simulated confirmation. **No real gateway, API, bank, or card network is contacted.**
- **Add an Inertia "Add Money" flow** — amount entry, method selection, method-specific simulated
  payment screen, an explicit Confirm / Simulate Failure / Cancel action, a receipt, and a demo
  transaction history — with the **real** wallet balance and the **demo** balance presented in
  visibly separate panels.
- **Label every demo surface.** Every demo payment screen, receipt, balance panel and history row
  states plainly that **no real money was transferred**. The demo balance is never labelled as
  available balance, spendable balance, or real funds.
- **Never collect real payment credentials.** The card form takes a fictional test card number, a
  fictional expiry and a fictional CVV, and explicitly refuses to accept or store a real card. No
  real PIN, OTP, CVV, bank credential or password is requested, transmitted or stored — the
  payment fields are demonstration inputs only.

### PENDING approval — carried, not assumed

Every item below is an **unapproved** decision. Each is marked PENDING in the artifact that relies
on it. None is treated as settled.

| Item | Decision | Status | Effect on this change |
|---|---|---|---|
| **H-1** | SYSTEM ledger accounts as an exception to the 1:1 mapping | 🟠 **Proposed (not approved)** | **Not implemented, and not approximated.** The demo subsystem creates no SYSTEM account and no funding entry pair. It writes no ledger entries at all, so it is not a funding path. The real wallet stays at its ledger-backed balance. |
| **C-4** | Funding cannot be modelled honestly without H-1 | 🟠 **Proposed (not approved)** | **The exact blocker this change works around.** A real balance is refused. The demo balance is explicitly *not* a real balance and is not a substitute for one. |
| **C-3** | Business limit — numeric limit, daily cap, regulatory ceilings | ⚪ **Open** | The demo per-payment ceiling is a **configurable local-demo value** (`config('p2p-demo.max_amount_minor')`), clearly labelled as a demo limit. It is **not** a regulatory limit, and no Bangladesh compliance rule is invented. If C-3 is later decided, the demo limit is re-pointed at the decided value. |
| **A2** | Money is integer minor units | ⚙️ **Architecture default** | Honoured: the demo balance and every demo amount are `BIGINT UNSIGNED` integer Poisha. No float, no decimal, anywhere in the demo money path. |
| **A3** | The 1:1 wallet↔account mapping is immutable | ⚙️ **Architecture default — PENDING** | Honoured and **not relied upon**: the demo subsystem has no wallet and no ledger account. It does not weaken, extend or re-point the mapping. |
| **A9** | Reserved balance ≤ posted balance | ⚙️ **Architecture default** | Not applicable: the demo subsystem has no reservation concept. The real wallet's reserved balance is untouched. |
| **D1 / H-6** | `ledger_entries.status` shape | 🟠 **Proposed (not approved)** | Not applicable: no ledger entry is written. |
| **M-2** | Canonicalization for the hash chain | ⚪ **BLOCKED** | Not applicable: no ledger entry is written, so nothing is canonicalized or hashed. |
| **OQ-1 … OQ-4** | Transfer/reversal/ceiling approvals | ⚪ **Not approved** | Not touched. No production transfer, reversal or ceiling is enabled. |

**Explicitly NOT relied upon:** the ledger entry table, the hash chain, the chain head, sequence
allocation, reconciliation, the audit outbox, the reservations table, idempotency of real
transfers, and the marketplace (H-4, Phase 2).

## The precise conflict, and its resolution

`openspec/config.yaml` states: *"No real-money production enablement is authorised. Production
transfers and production reversals stay disabled until OQ-1, OQ-2, OQ-3 and OQ-4 are explicitly
approved."* H-1 states: *"All funding must have balanced, auditable ledger entries … no money
should appear from an unexplained opening balance."*

A demo payment that credited a real wallet would violate **both**. The conflict is real, and it
is resolved by **isolation rather than by approximation**:

| Property | Real wallet (authoritative) | Demo balance (this change) |
|---|---|---|
| Table | `wallets.balance_minor` | `demo_balances.balance_minor` |
| Authority | A projection of `ledger_entries` | A standalone simulation counter |
| Ledger entries written | Yes, by the ledger subsystem | **Never** — none exist in this change |
| SYSTEM account / funding pair | Required by H-1, **not approved** | **Never created** |
| Spendable via transfer / settlement / withdrawal / reversal | Yes, once those stories land | **Never** — no code path reads it |
| Value today | ৳0 (correct, and correct to stay) | Whatever the user simulated |

Because **no production code path reads `demo_balances`**, a demo balance can never become
spendable money, no matter what a future story does. The isolation is enforced by construction
and asserted by tests.

## Capabilities

### New Capabilities

- `demo/payments`: The DEMO payment subsystem — a fully working, database-backed simulation of
  "Add Money" with four simulated methods, a server-controlled state machine, an idempotent
  demo balance, unique demo references, receipts, history, and an absolute separation from the
  authoritative ledger-backed balance.

### Modified Capabilities

_None._ The `ledger/accounts-and-wallets` capability is **read** by this change (the wallet
balance is displayed next to the demo balance) but **not modified**: no column, constraint,
trigger, index or row of `wallets`, `ledger_accounts` or `ledger_entries` is altered.

## Impact

**New database objects (MySQL 8.0, InnoDB, `utf8mb4`, UTC):**

- `demo_payments`
- `demo_balances`

**New Laravel objects:**

- `app/Enums/DemoPaymentMethod.php` — the four simulated methods
- `app/Enums/DemoPaymentStatus.php` — `PENDING`, `SUCCEEDED`, `FAILED`, `CANCELLED`
- `app/Models/DemoPayment.php`
- `app/Models/DemoBalance.php`
- `app/Services/DemoPaymentService.php`
- `app/Http/Controllers/DemoPaymentController.php`
- `app/Http/Requests/DemoPaymentStoreRequest.php`
- `config/p2p-demo.php`
- One migration under `database/migrations/`
- One Inertia page under `resources/js/pages/demo/`

**Existing objects read but not modified:** `users` (referenced by both new tables),
`wallets` and `ledger_accounts` (read to display the authoritative balance next to the demo
balance), `kyc_submissions` (read to enforce eligibility).

**Out of scope:** the ledger entry table, append-only triggers, the hash chain and its
canonicalization (blocked by M-2), the chain head and close records, the `reservations` table,
real transfer execution, real reversal, reconciliation, the audit chain, the marketplace (H-4,
Phase 2), any real payment gateway or bank integration, and any production enablement.

**Not authorised by this change:** running the migration against a live database, changing `.env`,
committing, or pushing.
