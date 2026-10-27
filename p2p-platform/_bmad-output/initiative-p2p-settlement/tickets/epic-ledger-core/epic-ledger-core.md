---
type: epic
title: Ledger core — accounts, wallets, entries, and the hash chain
parent: ../initiative-p2p-settlement.md
status: draft
created: 2026-10-26
updated: 2026-10-26
refined: true
sources:
  - ../../../P2P_SPECIFICATION.md
  - ../../planning-artifacts/grill-me-decision-register.md
  - ../../planning-artifacts/prds/prd-p2p-platform-2026-10-26/prd.md
  - ../initiative-p2p-settlement.md
companions:
  - ../../architecture-p2p-settlement/architecture-p2p-settlement.md
---

# Epic: Ledger core — accounts, wallets, entries, and the hash chain

> **Approval ceiling — read first.**
> This epic's requirements derive from decisions that are **🟡 Provisional**, **🟠 Proposed (not
> approved)**, **⚪ Open**, or **✅ Technical default (unconfirmed)**. **Nothing here is finally
> approved.** `P2P_SPECIFICATION.md` is authoritative and **unmodified**.
>
> **No real-money production enablement is authorised by this epic.** Epic 1 builds the money
> *substrate*, not a money-movement feature. The transfer path is Epic 3.

**References.**
- `architecture-p2p-settlement/architecture-p2p-settlement.md` — the architecture spine and MySQL
  data model, adopted as a companion. Its ids (`A1`–`A18`) are cited below.
- `../../initiative-p2p-settlement.md` §7 — the reconciliation of the four disputed decisions
  (`D1`–`D4`) against the specification and the decision register.

---

## 1. Description

The authoritative money substrate. Ledger accounts mapped 1:1 to wallets, wallets with posted and
reserved balances in integer Poisha, immutable hash-chained double-entry ledger entries with
per-account sequence numbers, the chain-head singleton, and the chain-close record.

## 2. Outcome

A wallet's posted balance is provably reconstructible from its account's entries, and any
alteration, deletion, or reordering of an entry is detectable.

## 3. Boundaries

**Covers** parent R3, R4, R5, R6, R7, R8, R9, R10, and the ledger portion of R23.

**Does not cover** transfer execution, reversal, the reconciliation engine, the audit chain
appender, or the chain closer itself.

## 4. Requirements

*Cut from the parent initiative's `R<n>` lines. A parent id in brackets shows the origin.*

### 4.1 Wallets and balances

- **R1 (parent R3, C-4, M-8, SPEC §3.1)** — A user holds a Wallet with a currency, a posted balance
  (`balance_minor`), and a reserved balance (`reserved_minor`), all in **integer minor units
  (Poisha)**. Floating-point arithmetic is forbidden anywhere in the money path. Available balance
  is `balance_minor − reserved_minor` and is never negative. A user holds at most one Wallet per
  currency. `balance_minor` is the canonical column name (✅ technical default, unconfirmed).
- **R2 (parent R4, C-1, A9)** — `0 <= reserved_minor <= balance_minor` holds at all times, enforced
  by a database CHECK constraint rather than application code. No user wallet is ever negative, and
  under `STRICT_TRANS_TABLES` with `BIGINT UNSIGNED` a negative value is physically
  unrepresentable (A2).
- **R3 (parent R9, C-2, A3)** — A Wallet maps to exactly one Ledger Account via a
  `wallets.account_id` foreign key. The mapping is **immutable** — created once, never updated, no
  `ON UPDATE`/`ON DELETE` cascade that could re-point it. `account_id` sits inside the hashed body
  of every entry, so the field name is permanent. `wallet_id` and `account_id` are **not** assumed
  to have identical numeric values. **No second independently mutable balance field exists on the
  Account.**
- **R4 (parent R9, A3, H-1 🟠 proposed)** — The 1:1 wallet↔account mapping is enforced at the data
  layer by a unique index on `wallets.account_id` and a unique index on
  `ledger_accounts.owner_user_id`. `owner_user_id` is **nullable** and an `account_type` column
  distinguishes `USER` from `SYSTEM`, so that H-1's SYSTEM accounts can be adopted later **without
  a schema migration**. *Adopting H-1 is not authorised by this epic; the schema accommodates it.*

### 4.2 Ledger entries

- **R5 (parent R5, SPEC §4.1)** — A transfer produces exactly two Ledger Entries of equal magnitude
  and opposite direction — one DEBIT and one CREDIT — so that for every `transaction_id`,
  `SUM(DEBIT) = SUM(CREDIT)`, with exactly one of each. A fault between debit and credit insert
  produces zero entries.
- **R6 (parent R6, SPEC §4.2, H-6 🟠 proposed, D1)** — Ledger Entries are never updated and never
  deleted. No `UPDATE` or `DELETE` statement exists against `ledger_entries` in application code.
  Corrections are made only by appending compensating entries.
- **R7 (parent R6, A5, D1)** — The append-only guarantee is enforced **at the data layer**, by
  three mechanisms together: (1) the runtime role holds `INSERT` and `SELECT` on `ledger_entries`
  but **not** `UPDATE` or `DELETE`; (2) `BEFORE UPDATE` and `BEFORE DELETE` triggers
  `SIGNAL SQLSTATE '45000'`; (3) no `ON DELETE`/`ON UPDATE` cascade on any foreign key referencing
  `ledger_entries`. *Mechanism (1) is an infrastructure concern and is listed in the approval
  checklist as **G4**; mechanisms (2) and (3) are migration work.*
- **R8 (parent R8, SPEC §4.4, A6)** — Every Ledger Entry carries a monotonic
  `account_sequence_number`, **unique and gapless** per `account_id`, assigned inside the posting
  transaction from a per-account counter on `ledger_accounts.next_sequence_number` under a row
  lock. `MAX(sequence) + 1` is forbidden — it is not safe under concurrency.
- **R9 (parent R8, A6)** — Accounts are always locked in **ascending `account_id` order, never in
  transfer direction**, encoded in a single shared helper and not re-derived per call site. Two
  concurrent transfers `A→B` and `B→A` must not deadlock.
- **R10 (parent R7, SPEC §5.1–5.5, A12)** — Each Ledger Entry's hash is computed over a canonical
  JSON serialization of a fixed set of hashed fields concatenated with the previous entry's hash.
  The chain head is a singleton holding the hash of the most recently closed entry.
  `balance_after` is **deliberately excluded** from the hashed body. *The hash **implementation**
  is blocked by M-2 — see §6.*

### 4.3 Finality evidence and the chain head

- **R11 (parent R15, D2, SPEC §7.4, §9.2, A11)** — Finality is evidenced **only** by a durable,
  immutable `chain_close_records` row. `chain_close_records` carries a
  `UNIQUE (transaction_id, entry_id)` constraint — the mechanism that makes the closer safe under
  at-least-once delivery — plus a monotonic `chain_position`, and is queryable by `entry_id`
  alone.
- **R12 (D2, SPEC §7.4)** — The `transactions.status = 'POSTED'` write happens **outside** the
  chain-close ACID unit and **after** the broker acknowledgement. A crash in that window leaves a
  chain-closed entry whose transaction still reads `CHAIN_PENDING`; this is tolerated because
  `transactions.status` is a rebuildable projection and **the ledger wins**. *This epic provides
  the table that makes this derivable; the closer itself is Epic 3.*
- **R13 (parent R10, SPEC §4.3, A4)** — `transactions.status` is a **rebuildable projection**, not
  authoritative. It can be reconstructed from ledger entries at any time. If the projection and
  the ledger-derived state disagree, **the ledger wins**.
- **R14 (A11, A12)** — `ledger_chain_head` holds **exactly one row**, enforced by a
  `CHECK (chain_id = 1)` constraint. A bad migration cannot duplicate it.

### 4.4 Audit outbox table

- **R15 (parent R25, D4, A8)** — An `audit_outbox` table exists to carry audit **intent**
  atomically with money movement. It carries **scalar identifiers only and no foreign key to any
  hot-path table** (`transactions`, `wallets`, `ledger_accounts`, `ledger_entries`), so that an
  outbox insert can never participate in a lock-order inversion with the money path. *The drain
  worker and the `audit_log` chain are Epic 5.*

## 5. Acceptance criteria

*Cut from the parent's `AC<n>` lines.*

- **AC1 (parent AC1, R5, R6)** — For every `transaction_id`, `SUM(DEBIT) = SUM(CREDIT)` with exactly
  one of each. A static scan of application code finds no `UPDATE` or `DELETE` against the ledger
  entries table.
- **AC2 (parent AC2, R7)** — Given any altered field in a hashed entry, chain verification fails at
  that entry and every subsequent link. Given a full ledger replay in chain order, the final hash
  equals the chain head's current hash. `balance_after` is not present in the hashed body.
- **AC3 (parent AC3, R8)** — Two entries for the same account never share a sequence number. Chain
  order matches sequence order for every account.
- **AC4 (parent AC4, R9, R3)** — Each Wallet maps to exactly one Ledger Account and the mapping
  never changes. No second independently mutable balance field exists on the Account.
- **AC5 (R2)** — A direct `UPDATE` setting `reserved_minor` above `balance_minor` is **rejected by
  the database**, not by application code. A direct `UPDATE` setting `balance_minor` negative is
  rejected by the column type.
- **AC6 (R7)** — A direct `UPDATE` against `ledger_entries` raises an error from the trigger; a
  direct `DELETE` raises an error from the trigger. Both are rejected even when issued from a
  privileged session.
- **AC7 (R8, R9)** — Two concurrent transactions allocating a sequence for the same account receive
  **distinct, gapless** numbers. Two concurrent transfers between the same two accounts in
  opposite directions complete without deadlock.
- **AC8 (R11, R12)** — A duplicate `(transaction_id, entry_id)` close attempt raises a unique
  violation. An entry with no `chain_close_records` row is correctly identified as *not yet
  chain-closed* by a left-join query — the sweeper's predicate.
- **AC9 (R13)** — `transactions.status` is fully rebuildable from entries alone, and a deliberately
  corrupted status value is corrected by the rebuild without touching a ledger entry.
- **AC10 (R14)** — A second row in `ledger_chain_head` is rejected by the `CHECK (chain_id = 1)`
  constraint.

## 6. Open questions and assumptions

### 6.1 Assumptions this epic proceeds on

- **[ASSUMPTION]** The **runtime database role is separate from the migration role**, or that the
  `UPDATE`/`DELETE` revocation on `ledger_entries` is applied to the runtime role only. Without
  this, R7's mechanism (1) cannot be built. *Listed as **G4** in the approval checklist.*
- **[ASSUMPTION]** The specification's `ledger_entries.status` references (§3.1, §7.3, §9.4) are
  read as describing **intent** rather than prescribing a stored column, per **D1** in the parent's
  §7.2. **If the user rejects D1, this epic's `ledger_entries` table gains a `status` column and
  the hashed-body field list changes.** The migration is written to accommodate either shape.
- **[ASSUMPTION]** M-2's canonicalization rules remain **⚪ BLOCKED**. No hashing code is written in
  this epic; only the hashing module's **interface** and its **injection point** are built, with the
  implementation supplied once M-2 is answered.
- **[ASSUMPTION]** M-3's genesis literal remains **⚪ Open**. The chain bootstrap is written to take
  the genesis value as a **parameterised seed**, not a literal.

### 6.2 Open questions that do not block this epic

| # | Question | Blocks | Status |
|---|---|---|---|
| **OQ-7 / M-2** | Exact canonicalization rules and cross-language SHA-256 test vectors | **The hash implementation only** — not the tables, constraints, triggers, models, or sequence allocator | ⚪ **Blocked** |
| **OQ-6 / M-3** | The ledger genesis literal | Chain bootstrap (seeded as a parameter) | ⚪ **Open** |
| **H-1** | SYSTEM ledger accounts for funding | Honest funding modelling; the schema accommodates it | 🟠 **Proposed** |
| **OQ-11 / A15** | Audit chain as a separate table vs separate schema | Epic 5, not this epic | ⚙️ **Needs confirmation** |
| **OQ-16** | Dispute-review queue | Epic 4 | ⚪ **Open** |

### 6.3 The one thing that is genuinely blocked inside this epic

> **The hash implementation is blocked by M-2. Nothing else is.**
>
> Every table, constraint, trigger, model, sequence allocator, chain-head singleton, and
> chain-close record can be built and tested now. The hashing module's interface and injection
> point can be built now. Only the function that computes a hash over a canonical body waits for
> M-2's byte-level contract and test vectors.
>
> This is why Epic 1's tickets below are ordered to deliver the whole ledger *except* the hash.

## 7. The stories

*Planned in `tickets/tickets.toml`, in build order. Each is small, ordered, and independently
testable. The first ticket is `story-ledger-schema-foundation`.*

| # | Slug | Title | Status |
|---|---|---|---|
| 1 | `ledger-schema-foundation` | Ledger and wallet tables: accounts, wallets, and the ownership constraints | ✅ Built |
| 2 | `ledger-entry-table` | The `ledger_entries` table: append-only shape, hashed fields, and the no-status decision | ✅ Built |
| 3 | `ledger-entry-immutability-triggers` | Append-only enforcement: UPDATE/DELETE triggers and the cascade prohibition | ✅ Built |
| 4 | `account-sequence-allocator` | Per-account sequence allocation: the counter, the conditional increment, and the lock order | 🟢 Ready |
| 5 | `chain-head-and-close-records` | The chain head singleton and the chain-close record table | 🟢 Ready |
| 6 | `ledger-models-and-scopes` | Eloquent models, the money cast, and the read scopes that forbid negative balances | 🟢 Ready |
| 7 | `audit-outbox-table` | The `audit_outbox` table: atomic intent with no foreign key to the hot path | 🟢 Ready |
| 8 | `hashing-module-interface` | The hashing module's interface and injection point, with the implementation deferred | ⛔ Blocked by M-2 |
| 9 | `ledger-invariants-and-verifier` | The ledger's own invariants: double-entry check, sequence continuity, and the chain verifier | ⛔ Blocked by M-2 |

**The consolidated approval checklist** is `approval-checklist-ledger-core.md`, in this folder. It
carries the four reconciled decisions (`D1`–`D4`), the spine choices needing sign-off (`G1`–`G5`),
what is blocked and by what, and — in Section D — the seven tickets that can start with **no
approval at all**.

## 8. Done when

- AC1–AC10 hold.
- A wallet's posted balance is reconstructible from its account's entries alone.
- The chain verifier passes on a clean dataset.
- The append-only guarantee holds against a privileged session, not merely against application
  code.
- Every ticket in `tickets/tickets.toml` is built and reviewed.

## 9. Not authorised by this epic

Application code beyond the tickets listed here, migrations to be run against a live database,
environment changes, commits, pushes, and any production enablement.
