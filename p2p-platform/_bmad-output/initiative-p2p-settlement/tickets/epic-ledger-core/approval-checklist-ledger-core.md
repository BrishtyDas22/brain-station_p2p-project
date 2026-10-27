---
type: checklist
title: Consolidated approval checklist — P2P Settlement, Epic 1 and the architecture spine
status: draft
created: 2026-10-26
updated: 2026-10-26
sources:
  - ../initiative-p2p-settlement.md
  - ../epic-ledger-core.md
  - ../../architecture-p2p-settlement/architecture-p2p-settlement.md
  - ../../../planning-artifacts/grill-me-decision-register.md
---

# Consolidated approval checklist

> **One list. Nothing below is approved yet.** Every box is a decision the **user** makes.
> Tick a box to approve the item as written; leave it blank to keep the item open, in which case
> the work it gates stays blocked.
>
> **Section A is what you can approve to start work today. Section B is what can be built safely
> while decisions remain open. Section C is what is blocked until you decide.**
>
> **No real-money production enablement is authorised by any item below.** That requires OQ-1,
> OQ-2, OQ-3 and OQ-4, which are **not** on this list because they are not yet ready to be
> proposed — their values are still open.

---

## Section A — The four disputed decisions (reconciled)

*Each was read against `P2P_SPECIFICATION.md` and the decision register before a recommendation was
formed. Full reasoning is in the initiative ticket's §7. **None blocks Epic 1.***

### A1 — H-6, ledger status immutability

**Recommendation: remove `status` from `ledger_entries`.** Finality is proven by
`chain_close_records`; the sweeper selects on *absence of a close record*.

**Why this is the safe direction.** The specification contradicts itself: §4.2 and §14.1 say
entries are *never updated* and *no `UPDATE` exists against `ledger_entries`*, while §3.1, §7.3 and
§9.4 rely on a `status` column, and §5.1 lists `status` **inside the hashed body**. A mutable
column inside a hash is a contradiction — either the hash changes on every transition (rewriting
history) or the chain is broken. Removing the column is the only shape satisfying §4.2, §5.1,
§9.2 and §14.1 together. The sweeper's function and its 30s TTL are preserved exactly; only the
predicate changes from *"a column says so"* to *"a record is absent"* — which cannot disagree with
reality.

- ☐ **Approve** — `ledger_entries` has **no `status` column**. *Blocks: the hashed-body field
  list, and the sweeper predicate (Epic 3). Does not block any Epic 1 ticket.*
- ☐ **Reject** — `status` returns to `ledger_entries` per SPEC §3.1. *Then the hashed-body field
  list follows the spec, and Epic 1's story 2 gains a column. No Epic 1 ticket is blocked either
  way — the migration accommodates both shapes.*

**Status after this checklist: 🟠 Proposed (not approved).** Unchanged by this section.

### A2 — A11, chain-finality and the `POSTED` lifecycle

**Recommendation: keep the `POSTED` write outside the chain-close ACID unit, after the ACK.**

**Why this is not actually a dispute.** SPEC §7.4's pseudocode already reads
`COMMIT;` → `ACK to broker` → `UPDATE transactions SET status = 'POSTED'`. **A11 is SPEC §7.4
verbatim.** The stale-projection window it leaves is tolerable because SPEC §4.3 makes
`transactions.status` a rebuildable projection and says *the ledger wins*. The opposite choice
would add a third row write inside the hottest transaction in the system (SPEC §12.3 requires
close commits under 1ms) and buy nothing.

- ☐ **Confirm** the spec's ordering is intended, and that a stale `CHAIN_PENDING` projection is
  acceptable and is corrected by reconciliation. *Blocks: nothing in Epic 1. The chain-close
  table (story 5) is built either way.*
- ☐ **Change it** — write `POSTED` inside the chain-close transaction. *Then story 5's table is
  unchanged, but the closer's transaction widens. Epic 3 impact.*

**Status after this checklist: 🟡 Provisional, and now spec-conformant.** Reclassified from
"disputed" — a finding about the spec, not an approval of the spine.

### A3 — A7, reversal transaction modelling

**Recommendation: the reversal owns its own `transactions` row; `reversals.original_transaction_id`
is UNIQUE.**

**Why this is the safe direction.** SPEC §6.4 says a reversal appends a *new compensating pair*,
and SPEC §10.2's Invariant 1 requires that for **every** `transaction_id` there is exactly one
DEBIT and one CREDIT. If the compensating pair reused the original `transaction_id`, that
transaction would hold two of each and **the specification's own nightly invariant would fail on
every successful reversal**. The UNIQUE constraint makes double-reversal a *database error*, not a
failed application check — which is what H-2's "enforced at the data layer" requires.

- ☐ **Confirm** the reversal is its own transaction with a UNIQUE original-reference. *Blocks:
  Epic 4's `reversals` table. Nothing in Epic 1.*
- ☐ **Change it** — the compensating pair reuses the original `transaction_id`, and Invariant 1
  gains an explicit exception clause for reversed transactions. *Then Epic 4 must also carry that
  exception into reconciliation.*

**Status after this checklist: ⚙️ Architecture default.** Still needs confirmation.

### A4 — A8, audit-outbox ordering

**Recommendation: keep the outbox pattern — intent atomic with the money, chain append
asynchronous.**

**Why this is the safe direction.** The audit *intent* commits with the money, so suppressing the
ledger write would require suppressing the same database write — which is what AC14's "no
undetected tampering" needs. And the ledger never blocks on the audit sink, so an audit-component
outage cannot freeze transfers. Putting audit writes inline would convert an observability
dependency into a money-availability dependency — a worse failure mode for a financial system.

- ☐ **Confirm** the trade: **availability of money movement is preferred over immediate audit
  presence.** The residual window between the ledger commit and the outbox drain is bounded by
  the worker's poll interval, and the backlog depth is a monitored metric. *Blocks: Epic 5's drain
  worker. Epic 1's story 7 (the table) is unaffected.*
- ☐ **Change it** — audit writes go inline in the money transaction. *Then the money path gains a
  dependency on the audit chain's availability, and Epic 1's story 7 gains a foreign key
  constraint that the recommendation deliberately avoids.*

**Status after this checklist: ⚙️ Architecture default.** Still needs confirmation.

---

## Section B — Spine choices that need sign-off but block nothing today

*These are the spine's §5.3 list. Each is a technical choice the requirement layer left open.
**None of them blocks an Epic 1 ticket.** They are listed so nothing is inherited silently.*

- ☐ **G1 — ULID identifiers.** `ledger_entries.entry_id` is a `CHAR(26)` ULID, not an
  auto-increment. *Prevents the id sequence from leaking transaction volume through the hashed
  body.* Affects story 2.
- ☐ **G2 — Sequence-counter placement.** `next_sequence_number` lives on `ledger_accounts`, not
  on `wallets`. *It is an allocation counter, not a balance, and is reconciled by sequence
  continuity rather than by Invariant 3.* Affects story 4.
- ☐ **G3 — "Reconciliation partitioning by year" is a scan filter,** not table partitioning.
  *The spec's phrase is ambiguous; this reading preserves the deferral of table partitioning
  (M-1).* Affects Epic 5.
- ☐ **G4 — Runtime role cannot UPDATE or DELETE on the chain tables.** The append-only guarantee
  is enforced by privilege revocation **and** triggers. *This is an infrastructure concern, not
  just a migration — it needs a runtime role that is separate from the migration role.* Affects
  story 3.
- ☐ **G5 — Chain-closer singleton enforcement.** Enforced by a leader lease + startup assertion +
  conditional update. *SPEC §13.1 says failure 11 has no runtime defence; this adds one.* Affects
  Epic 3.

**Status: ⚙️ Architecture defaults.** None is approved by this checklist until ticked.

---

## Section C — What is blocked, and by what

### C1 — Blocked by M-2 (canonicalization, ⚪ BLOCKED)

**The hash implementation cannot be written.** The exact byte-level canonicalization rules and the
cross-language SHA-256 test vectors are not specified, so `canonical_json()` and the verifier
cannot be written without inventing a contract that other implementations would have to match.

- ☐ **Define M-2.** The team must supply: field order, integer encoding, timestamp precision and
  format, null policy, `prev_hash` encoding, string escaping, and at least one worked SHA-256
  test vector. *Until then, Epic 1's story 8 is blocked at its implementation and story 9 is
  blocked.*
- ☐ **Defer M-2 to Epic 5.** *Then Epic 1 ships without a working hash, and the chain verifier
  moves out of Epic 1 entirely.*

### C2 — Blocked by M-3 (genesis literal, ⚪ Open)

**The chain cannot be bootstrapped** with a real genesis value.

- ☐ **Choose the genesis literal.** *Until then the chain-head seed is written as a parameterised
  seed, and the "clean dataset chain verifier passes" test in Epic 1's Done-when is deferred with
  it.*

### C3 — Blocked by OQ-1, OQ-2, OQ-3, OQ-4 — **production enablement only**

**Production real-money transfers and production reversal execution stay disabled.** Not on this
checklist as an approval item, because the values are still open and no recommendation can be made
without inventing a policy.

| # | Question | Blocks |
|---|---|---|
| **OQ-1** | Reversal time window | Production reversal execution |
| **OQ-2** | Reversal fee policy | Production reversal execution |
| **OQ-3** | Higher-value reversal approval threshold | Production reversal execution |
| **OQ-4** | Per-transaction ceiling, daily cap, regulatory ceiling | Production real-money transfers |

*These are Epic 6's business. Nothing in Epic 1–5 depends on them.*

### C4 — Blocked by H-1 (SYSTEM accounts, 🟠 Proposed)

**Funding cannot be modelled honestly yet.** Without SYSTEM accounts, there is no source for a
wallet's first balance.

- ☐ **Approve H-1.** Introduce typed SYSTEM ledger accounts as an explicit exception to the 1:1
  wallet↔account mapping; funding is a balanced auditable entry pair; the opening balance is
  derived, never seeded. *The schema already accommodates this — `owner_user_id` is nullable and
  `account_type` distinguishes `USER` from `SYSTEM` — so no Epic 1 migration is needed either
  way.*
- ☐ **Reject H-1.** Then funding has no auditable source and the question reopens in Epic 3.

### C5 — Blocked by OQ-7 / M-2, OQ-11 / A15, OQ-16, and the H-series defaults

Still open, unchanged, and **not proposed for approval here** because their values are still
missing: OQ-5 (lease TTL), OQ-6 (genesis literals), OQ-7/M-2 (canonicalization), OQ-11/A15 (audit
table vs schema), OQ-12 (sweeper vs page threshold), OQ-15 (KYC admin role mechanism), OQ-16
(dispute queue). Unconfirmed technical defaults carried unchanged: C-4, H-3, H-7, H-8, M-5, M-8.
Withdrawn: M-7. Deferred: M-1 (partitioning), M-6 (cold archive).

---

## Section D — What you can start today, with no approval at all

*These Epic 1 tickets need **no decision** from Section A, B, or C to begin. They are listed so
the approval queue never blocks the ledger's substrate.*

| Ticket | Title | Why it is unblocked |
|---|---|---|
| **1** | `ledger-schema-foundation` | Accounts, wallets, and the ownership constraints. R4's nullable-owner design accommodates H-1 either way. |
| **2** | `ledger-entry-table` | The migration accommodates both D1 shapes, so A1's answer is not needed to write it. |
| **3** | `ledger-entry-immutability-triggers` | The triggers and cascade prohibition are required whichever way D1 goes. G4 affects only the *privilege* half. |
| **4** | `account-sequence-allocator` | Independent of every open decision. G2 is a placement detail, reversible in a migration. |
| **5** | `chain-head-and-close-records` | SPEC §7.4 and §9.2 fix the table's shape regardless of A2's confirmation. |
| **6** | `ledger-models-and-scopes` | Models and read scopes; no decision dependency. |
| **7** | `audit-outbox-table` | The table's "no FK to the hot path" constraint holds under A4 either way. |

**Blocked:** story **8** (hashing module — implementation waits on M-2) and story **9** (invariants
and verifier — waits on stories 4 and 8).

---

## Section E — Status ledger after this checklist

*Recorded so a later reader can tell what was decided and what was not. **Tick marks here are the
user's; an unticked box leaves the status exactly as it was.***

| Item | Before | After (if ticked) | After (if not ticked) |
|---|---|---|---|
| **H-6** — ledger `status` column | 🟠 Proposed (not approved) | 🟡 Provisional | 🟠 Proposed (not approved) |
| **A11** — `POSTED` write boundary | 🟡 Provisional, disputed | 🟡 Provisional, spec-conformant | 🟡 Provisional, disputed |
| **A7** — reversal transaction modelling | ⚙️ Architecture default | ⚙️ Confirmed architecture default | ⚙️ Architecture default, needs confirmation |
| **A8** — audit-outbox ordering | ⚙️ Architecture default | ⚙️ Confirmed architecture default | ⚙️ Architecture default, needs confirmation |
| **G1–G5** — spine choices | ⚙️ Needs sign-off | ⚙️ Signed off | ⚙️ Needs sign-off |
| **M-2** — canonicalization | ⚪ Blocked | ✅ Contract defined | ⚪ Blocked |
| **M-3** — genesis literal | ⚪ Open | ✅ Chosen | ⚪ Open |
| **H-1** — SYSTEM accounts | 🟠 Proposed | 🟡 Provisional | 🟠 Proposed |
| **Production enablement** | ⛔ Disabled | ⛔ **Still disabled** | ⛔ Disabled |

> **Note on H-6's status.** Even ticked, H-6 becomes **🟡 Provisional** — the same tier as the
> other Grill Me decisions — **not** a final approval. Nothing in this initiative reaches a
> final-approval tier through this checklist.

---

*Produced by the `bmad-agent-po` (Sofia) workflow. No specification file, application code,
database schema, environment file, or test configuration was modified. No migrations, tests,
commits, or pushes were performed.*
