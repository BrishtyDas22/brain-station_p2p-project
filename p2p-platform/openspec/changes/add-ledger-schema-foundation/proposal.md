# Proposal

## Why

The P2P settlement system has no money substrate. Every downstream guarantee — atomic transfers,
reversal, reconciliation, an independently verifiable audit trail — rests on ledger accounts and
wallets existing with the right constraints, and the development database currently reports **zero
tables**. Building the transfer flow first would mean inventing the money schema implicitly, in
the hottest code path in the system, with no independent review of it.

This change establishes that substrate first: the two tables everything else references, and the
data-layer constraints that make the financial invariants true by construction rather than by
convention. It is the first of nine planned stories in BMAD Epic 1 (`ledger-core`), and it is
deliberately the smallest one — it adds **no** ledger entries, **no** hash chain, and **no**
transfer path.

**Why now, in this order:** Epic 1's later stories (entry table, immutability triggers, sequence
allocator, chain head) all reference `ledger_accounts` and `wallets`. Fixing those two tables
first means every later story inherits a reviewed contract instead of re-deciding it.

## What Changes

- **Add a `ledger_accounts` table** — the account half of the 1:1 wallet↔account mapping. Carries
  `owner_user_id`, `account_type` (`USER`/`SYSTEM`), `system_role`, and `next_sequence_number`
  (the per-account sequence allocator, A6/G2).
- **Add a `wallets` table** — the money-holding table. Carries `balance_minor` and
  `reserved_minor` as `BIGINT UNSIGNED` integer Poisha, `currency`, and the immutable
  `account_id` foreign key (A3/C-2).
- **Enforce the 1:1 wallet↔account mapping at the data layer** via a unique index on
  `wallets.account_id` and a unique index on `ledger_accounts.owner_user_id` (A3, R3, R4,
  AC4). Not by application code.
- **Enforce `0 <= reserved_minor <= balance_minor` as a database CHECK constraint**
  (`ck_wallets_reserved_within_balance`, A9, R2, AC5) — expressible because both columns live on
  one row.
- **Make a negative wallet physically unrepresentable** by using `BIGINT UNSIGNED` money columns
  under `STRICT_TRANS_TABLES` (A2, R1, R2). A negative value is rejected at write time, not by
  application logic.
- **Make the wallet→account mapping immutable** — no `ON UPDATE`/`ON DELETE` cascade on
  `wallets.account_id` (R3, AC4). `account_id` sits inside the hashed body of every ledger entry,
  so this column's name and binding are permanent (C-2).
- **Accommodate H-1 without a migration** by making `ledger_accounts.owner_user_id` nullable and
  adding `account_type` with a `CHECK` that separates `USER` from `SYSTEM` (A3, R4). **H-1 itself
  remains 🟠 Proposed (not approved) and is NOT implemented by this change.**

### PENDING approval — carried, not assumed

Every item below is an **unapproved** decision that this proposal depends on. Each is marked
PENDING in the artifact that relies on it. None of them is treated as settled.

| Item | Decision | Status | Effect on this change |
|---|---|---|---|
| **H-1** | SYSTEM ledger accounts as an exception to the 1:1 mapping | 🟠 **Proposed (not approved)** | The schema **accommodates** it (nullable owner + `account_type`). No SYSTEM account is created and no funding path is modelled. If H-1 is rejected, the columns become `NOT NULL` in a later migration. |
| **A3 / G2** | `next_sequence_number` lives on `ledger_accounts`, not `wallets` | ⚙️ **Architecture default — PENDING** | The column is created in this change. If G2 is signed off differently, the column moves in a later migration. It is a placement choice, not a financial guarantee. |
| **A3** | `owner_user_id` nullable + `account_type` split | ⚙️ **Architecture default — PENDING** | Same as H-1's row: designed for it, implementing it is not authorised. |
| **C-4** | `balance_minor` is the canonical column name | ✅ **Technical default (unconfirmed)** | The column is named `balance_minor`. |
| **M-8** | Currency is `CHAR(3)` ISO-4217, default `'BDT'` | ✅ **Technical default (unconfirmed)** | The column is `CHAR(3)` with a CHECK. |
| **C-2 addendum** | Wallet↔account mapping is immutable | 🟠 **Proposed (not approved)** | Enforced as stated; the mapping's *immutability* is what C-2 proposed. |

**Explicitly NOT relied upon:** the `ledger_entries` table, the `status`-column question (H-6 / D1),
the hash chain, the chain head, the chain-close record, reservations-as-records (A9's `reservations`
table), idempotency, reversal, and the audit outbox. Those are Epic 1 stories 2–9 and Epic 3–5.

## Capabilities

### New Capabilities

- `ledger/accounts-and-wallets`: The money substrate — ledger accounts and wallets, their 1:1
  immutable mapping, integer-Poisha balances, the reserved-balance ceiling, and the
  sequence-allocation counter that later stories depend on.

### Modified Capabilities

_None._ The project has no existing OpenSpec specs (`openspec list --specs` returns empty); this
is the first capability.

## Impact

**New database objects (MySQL 8.0, InnoDB, `utf8mb4`, UTC):**

- `ledger_accounts`
- `wallets`

**New Laravel objects:**

- `app/Models/LedgerAccount.php`
- `app/Models/Wallet.php`
- Two migrations under `database/migrations/`

**Existing objects read but not modified:** `users` (referenced by `ledger_accounts.owner_user_id`
and `wallets.owner_user_id`). The `users` table is **not** altered by this change — the KYC columns
(`kyc_status`, `handle`, `auth_subject`) belong to BMAD Epic 2, not Epic 1.

**Downstream dependencies created:** Epic 1 stories 2 (`ledger-entry-table`), 4
(`account-sequence-allocator`), 5 (`chain-head-and-close-records`), 6 (`ledger-models-and-scopes`),
and 7 (`audit-outbox-table`) all build on these two tables.

**Out of scope:** the ledger entry table, append-only triggers, the hash chain and its
canonicalization (blocked by M-2), the chain head and close records, the `reservations` table,
idempotency, transfer execution, reversal, reconciliation, the audit chain, the marketplace
(H-4, Phase 2), and any production enablement.

**Not authorised by this change:** running the migration against a live database, changing `.env`,
committing, or pushing.
