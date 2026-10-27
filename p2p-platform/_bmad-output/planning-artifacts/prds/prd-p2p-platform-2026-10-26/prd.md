---
title: P2P Settlement — PRD Delta (Consolidated)
created: 2026-10-26
updated: 2026-10-26
status: draft
---

# PRD: P2P Settlement — Consolidated PRD Delta

> **This is a DELTA, not a replacement PRD.** The authoritative requirements source remains
> `../../../P2P_SPECIFICATION.md` (v1.0, "Design frozen — all 13 architectural decisions settled").
> This document layers the decisions taken during the BMAD requirements-clarification cycle onto
> that specification, and enumerates what remains open. It does **not** amend the specification.
> Nothing here is finally approved unless its status reads **Approved**.
>
> **Approval ceiling:** every decision in this document is **🟡 Provisional** or **⚪ Open**.
> Provisional means explicitly accepted by the user *for the MVP* and not yet finally approved.

---

## 0. Document Purpose

This PRD delta serves the Product Owner, the architect, and the delivery team for the **P2P Fund
Transfer & Transaction Settlement System**. It exists because the authoritative specification
(`P2P_SPECIFICATION.md`) is a frozen SRS that predates a structured requirements-gap analysis: it
contains genuine internal contradictions and undefined domain terms that block safe data modeling.

**Sources, in precedence order:**

1. `../../../P2P_SPECIFICATION.md` — authoritative. Unchanged by this document.
2. `_bmad-output/planning-artifacts/grill-me-decision-register.md` — the recovered decision
   register. Authoritative for *decision status*.
3. `_bmad-output/planning-artifacts/prds/prd-p2p-platform-2026-10-26/.memlog.md` — the run's
   append-only decision log.

**How it is structured:** §1–§3 restate the settled product intent, target user, and vocabulary
from the specification. §4 groups capabilities with globally numbered FRs. §5–§6 state non-goals
and MVP scope. §7 carries cross-cutting NFRs. §8 is the **delta table** — the actual new
requirements. §9 lists success metrics. §10 and §11 enumerate open questions and assumptions. §12
lists the remaining blockers.

**Vocabulary discipline:** §3 is the Glossary. FRs, UJs, and SMs use Glossary terms verbatim.

---

## 1. Vision

The P2P Fund Transfer & Transaction Settlement System is a peer-to-peer fund transfer platform for
a micro-finance context. Users transfer money to one another; **every movement of money is recorded
in an append-only, cryptographically verifiable double-entry ledger** that can be independently
audited and mathematically proven consistent.

The system's thesis is deliberate and load-bearing: **P2P in discovery and negotiation; centralized
in authoritative settlement** (spec §1.2). Peers find each other and build terms. The Transaction
Service is the **sole authority** that touches the ledger. The ledger is single, global, and
provably correct. A genuinely distributed ledger would be a blockchain, and the specification
records that its multi-year implementation cost and distributed-consensus surface are **not
justified by the stated requirements**.

This phase delivers the **transfer and settlement core only**. The marketplace is deferred to
Phase 2, not abandoned — see FR-14 and §6.2.

---

## 2. Target User

### 2.1 Jobs To Be Done

- **Send money to a known counterparty** and know it arrived, durably, and provably.
- **Trust that their balance is correct** without trusting the platform's word — the ledger can be
  independently replayed and proven consistent.
- **Operate transfers without double-charging** — retried, duplicated, or concurrent requests must
  produce exactly one money movement.
- **Verify transfers after the fact** — a cryptographically verifiable chain plus three nightly
  reconciliation invariants makes tampering detectable rather than merely unlikely.
- **Reverse an incorrect transfer** under controlled authority, with a full audit trail.
- **Have their identity verified** before being able to move money, with a clear path through
  review and re-submission.

### 2.2 Non-Users (v1)

- **Lenders making underwriting decisions.** The marketplace facilitates; risk decisions are out of
  scope (spec §1.4). No credit scoring, no risk assessment.
- **Multi-currency or cross-border users.** Phase 1 is single-currency (BDT, minor unit Poisha).
- **Nodes seeking distributed consensus.** The ledger is centralized by design.
- **Marketplace participants in Phase 1.** Listings, offers, and negotiation are deferred to
  Phase 2.

### 2.3 Key User Journeys

**UJ-1. Rahima sends money to a known counterparty.**
- **Persona + context:** Rahima, a smallholder needing to pay a supplier, is authenticated and
  `VERIFIED`.
- **Entry state:** Signed in on web. Payer wallet funded. Counterparty's handle known.
- **Path:** Rahima initiates a transfer → the system validates JWT, KYC status, ownership of both
  wallets, the idempotency key, and the amount bounds → funds are locked from Rahima's wallet
  (`LOCKED`) → the debit, credit, sequence assignment, and both ledger entries commit atomically
  (`CHAIN_PENDING`) → the API returns `ACCEPTED` → the singleton Chain Closer advances the chain
  head and the transaction becomes `POSTED`.
- **Climax:** Rahima sees `POSTED` — funds moved *and* are cryptographically provable.
- **Resolution:** Rahima can view her statement; the nightly reconciliation proves her balance.
- **Edge case:** Rahima taps "send" twice. The second submission carries the same idempotency key,
  and the system returns the original transfer's current status without moving money again.

**UJ-2. An operator reverses an incorrect transfer.**
- **Persona + context:** A settlement operator holds the dedicated reversal permission and finds a
  transfer that should not have completed.
- **Entry state:** Target transaction is `POSTED`. The recipient still holds the full credited
  amount.
- **Path:** The operator submits a reversal with a reason code from the closed set → the system
  validates reversal authority and the transaction's current state → a compensating DEBIT/CREDIT
  pair and the status transition commit in one ACID transaction → the pair flows through the
  existing singleton Chain Closer.
- **Climax:** The original transaction is `REVERSED`; funds are back with the payer; the original
  ledger entries remain untouched and verifiable.
- **Edge case:** The recipient has already spent the funds. The system **rejects the automatic
  reversal** and routes the case to manual dispute review. It does not create a negative wallet
  and does not reimburse from a system account.

**UJ-3. A new applicant completes KYC.**
- **Persona + context:** Karim wants to send money but has never been verified.
- **Entry state:** Registered; KYC status `PENDING`.
- **Path:** Karim submits his details → an authorized KYC admin reviews and transitions his status
  to `VERIFIED` or `REJECTED` with a recorded reason → if `REJECTED`, Karim resubmits and returns
  to `PENDING`.
- **Climax:** Karim's status is `VERIFIED`, and only then can he initiate a transfer.
- **Edge case:** Karim tries to transfer while `PENDING`. The request is rejected at the
  authorization boundary, before the ledger path is touched.

---

## 3. Glossary

*Terms from spec §15, plus the terms this delta introduces. Defined once. FRs, UJs, and SMs use
these terms verbatim.*

- **Account** — The ledger-side counterpart of a Wallet, addressed by `account_id` inside the
  hashed body. Related: Wallet, Ledger Account.
- **Append-only** — Records are never updated or deleted; corrections are new compensating entries.
- **Available balance** — `balance_minor` minus `reserved_minor`. The amount spendable right now.
- **Balance reconstruction** — Replaying an account's entries in sequence order to reproduce its
  balance. Related: Invariant 3.
- **Chain head** — Singleton record holding the hash of the most recently closed entry.
- **Chain Closer** — Singleton worker that advances the global chain head. **Scaling it breaks the
  ledger.**
- **CHAIN_PENDING** — Entries committed and durable; chain not yet advanced. Money moved, not yet
  provable.
- **Double-entry** — Every transfer produces one DEBIT and one equal CREDIT; sum is always zero.
- **Epoch (`lock_epoch`)** — Monotonic fencing token; rejects writes from workers whose lease was
  reclaimed.
- **Idempotency key** — Client-supplied unique token preventing duplicate transfer execution.
- **Invariant 1 — Transaction balance** — Per `transaction_id`, exactly one DEBIT and one CREDIT of
  equal magnitude.
- **Invariant 2 — Cryptographic continuity** — Each entry's `prev_hash` equals the previous entry's
  `entry_hash`; the final entry's hash equals the chain head's current hash.
- **Invariant 3 — Balance reconstruction** — For each account, `opening_balance + credits − debits`
  over sequence ≤ N equals the stored balance.
- **Invariant 4 — Reservation reconciliation** — *(new, this delta)* A wallet's `reserved_minor`
  equals the sum of its valid active reservations.
- **KYC status** — A User's verification state. One of `PENDING`, `VERIFIED`, `REJECTED`.
  *(Expanded by this delta — see FR-13.)*
- **Ledger account** — The ledger-side entity a Wallet maps to. See FR-11.
- **Ledger Entry** — An immutable, hash-chained record of a single DEBIT or CREDIT.
- **Marketplace** — The listing/offer/negotiation surface. **Deferred to Phase 2** — see FR-14.
- **Money movement** — A committed debit and credit pair. Distinct from *provability*, which
  requires the chain to have advanced.
- **Projection** — Derived, rebuildable cache (`transactions.status`). Not authoritative.
- **Posted balance** — `balance_minor`: the balance attributable to committed ledger entries.
- **Reaper** — Worker that reclaims `LOCKED` transactions past lease TTL.
- **Reservation** — A durable hold on funds recorded against a transaction, distinct from a
  completed transfer.
- **Reversal** — A compensating DEBIT/CREDIT pair appended to the ledger to undo a `POSTED`
  transfer. Never a modification of the original entries.
- **Sweeper** — Worker that re-enqueues `CHAIN_PENDING` entries stuck past TTL.
- **TOCTOU** — Time-of-check-to-time-of-use race; eliminated here by conditional
  `UPDATE ... WHERE balance >= X`.
- **Two-phase chain close** — Entries commit first; chain advancement happens asynchronously.
- **Wallet** — The user-facing balance container. Related: Account.
- **Wallet ownership** — Verified at the Transaction Service before any ledger path is touched.

---

## 4. Features

*Capabilities, not implementation. Technical mechanism details live in `addendum.md`. FRs are
numbered globally and are stable references for downstream artifacts.*

### 4.1 Identity, KYC, and Wallets

**Description:** A user registers, authenticates, and progresses through KYC verification before
being able to move money. Each user holds a Wallet carrying a posted balance and a reserved
balance, expressed in integer minor units (Poisha). Realizes UJ-3.

#### FR-1: User registration and authentication

A visitor can register an account and authenticate with a short-lived signed JWT. Every
transfer instruction, regardless of origin, terminates at the Transaction Service — **no direct
peer write path exists**.

**Consequences (testable):**
- An unauthenticated request is rejected before any ledger path is touched.
- A JWT that is expired or fails signature validation is rejected.

#### FR-2: KYC state machine *(expanded by this delta)*

A User's `kyc_status` is exactly one of `PENDING`, `VERIFIED`, `REJECTED`. New users begin in
`PENDING`. An **authorized KYC admin** may transition `PENDING → VERIFIED` or `PENDING → REJECTED`,
recording a reason and an audit trail entry. A `REJECTED` user may resubmit, returning to
`PENDING`. **Users cannot self-verify.** Only a `VERIFIED` user may initiate a transfer.

**Consequences (testable):**
- A `PENDING` or `REJECTED` user attempting to initiate a transfer is rejected at the
  authorization boundary, before the ledger path, with a security alert.
- Every KYC transition writes an audit record carrying actor, prior state, new state, and reason.
- A user cannot reach `VERIFIED` by any path other than an admin transition.
- A `REJECTED` user who resubmits has status `PENDING`, never `VERIFIED` directly.

**Out of Scope:**
- External KYC provider callbacks — deferred, not removed.
- Automatic expiry, suspension, or periodic re-verification of KYC status.

#### FR-3: Wallet and balance representation

A user holds a Wallet with a currency, a posted balance, and a reserved balance. All money is
stored in **integer minor units (Poisha)**; floating-point arithmetic is forbidden anywhere in the
money path. A user holds at most one Wallet per currency.

**Consequences (testable):**
- No float or decimal type appears in any money field, column, or computation.
- Available balance is `balance_minor − reserved_minor` and is never negative.

---

### 4.2 The Ledger

**Description:** Every movement of money is recorded as an immutable, hash-chained double-entry
pair. The ledger is the sole authority for posted money. Realizes UJ-1.

#### FR-4: Double-entry posting

A transfer produces exactly two Ledger Entries of equal magnitude and opposite direction —
one DEBIT and one CREDIT — so that the debit and credit sums for each `transaction_id` are equal.

**Consequences (testable):**
- For every `transaction_id`, `SUM(DEBIT) = SUM(CREDIT)`, with exactly one of each.
- No transfer produces a single entry; a fault between debit and credit insert produces zero
  entries.

#### FR-5: Append-only entries

Ledger Entries are never updated and never deleted. No `UPDATE` or `DELETE` statement exists
against `ledger_entries` in application code. Corrections are made only by appending compensating
entries.

**Consequences (testable):**
- A static scan of application code finds no `UPDATE` or `DELETE` against the ledger entries table.
- A deliberately removed entry is detected by Invariant 2 within one nightly cycle.

#### FR-6: Hash-chain integrity

Each Ledger Entry's hash is computed over a canonical JSON serialization of a fixed set of hashed
fields concatenated with the previous entry's hash. The chain head is a singleton holding the hash
of the most recently closed entry. `balance_after` is **deliberately excluded** from the hashed
body: storing an unverifiable assertion inside a tamper-evident structure makes the structure's
guarantee illusory.

**Consequences (testable):**
- Given any altered field in a hashed entry, chain verification fails at that entry and every
  subsequent link.
- Given a full ledger replay in chain order, the final hash equals the chain head's current hash.
- `balance_after` is not present in the hashed body.

#### FR-7: Per-account sequence numbers

Every Ledger Entry carries a monotonic `account_sequence_number`, unique and gapless per account,
assigned inside the posting transaction. This is what makes balance reconstruction deterministic
under concurrency.

**Consequences (testable):**
- Two entries for the same account never share a sequence number.
- Chain order matches sequence order for every account, verified over 24h of production traffic.

#### FR-8: Ledger as sole authority; status as projection

`transactions.status` is a **rebuildable projection** — a cache for query performance. It can be
reconstructed from ledger entries at any time. If `transactions.status` and the ledger-derived
state disagree, **the ledger wins.**

**Consequences (testable):**
- `transactions.status` is fully rebuildable from entries alone.
- The reconciliation engine diffs the projection against derived truth nightly.

---

### 4.3 Transfer Execution

**Description:** A transfer moves through authorization, lock, atomic post, and asynchronous
chain close. Realizes UJ-1.

#### FR-9: Authorization before the ledger path

Before any ledger path is touched, the Transaction Service validates, in order: the JWT; the
requester's KYC status; that the initiator owns the payer wallet; that the counterparty owns the
payee wallet; the idempotency key; and that the amount is positive and within the business limit.
Any failure at the ownership or KYC checks is logged to the Audit Service with a security alert.

**Consequences (testable):**
- A non-`VERIFIED` user cannot initiate any transfer.
- An unauthorized debit attempt is rejected before the ledger path and raises a security alert.
- A peer cannot read any balance other than their own, verified by attempting every API path.

#### FR-10: Single-transaction atomic posting *(amended by this delta)*

The payer's debit, the payee's credit, the sequence-number assignment, the insert of both Ledger
Entries, and the transition to `CHAIN_PENDING` occur in **one ACID transaction**. Any failure rolls
back everything, leaving zero entries.

**Amended by C-1.** The payer is debited **exactly once** across the whole flow. The prior
specification text (§7.2) performed a debit and then §7.3 performed a second debit of the same
amount — a literal double-charge. The corrected flow is:

- **Phase 2 — Reserve.** The payer is *not* debited. Instead a durable **Reservation** record is
  created and `reserved_minor` is incremented, atomically, using a concurrency-safe conditional
  update. This makes the funds *unavailable* without moving them.
- **Phase 3 — Post.** One ACID transaction consumes the Reservation, debits the payer exactly once,
  credits the payee, assigns sequences, inserts both entries, and transitions to `CHAIN_PENDING`.

**Consequences (testable):**
- Two concurrent transfers each for the full available balance: **at most one succeeds.**
- A fault injected between debit and credit insert produces **zero** ledger entries.
- The payer's posted balance is reduced exactly once for a single successful transfer.
- `0 <= reserved_minor <= balance_minor` holds at all times; no user wallet is ever negative.
- A reservation is never released twice, and never released after successful posting.

#### FR-11: Ledger Account entity *(new — resolves C-2)*

A Wallet maps to exactly one Ledger Account in the current version, via a `wallets.account_id`
foreign key. The mapping is **immutable**. Ledger Entries reference `account_id`; per-account
sequence numbers are per `account_id`; balance reconstruction is per account.

**The hashed field name is permanent.** `account_id` sits inside the hashed body of every entry.
It cannot be renamed or re-pointed without invalidating every hash in the chain. The indirection
is therefore deliberate and permanent.

**Consequences (testable):**
- Each Wallet maps to exactly one Ledger Account, and the mapping never changes.
- `wallet_id` and `account_id` are not assumed to have identical numeric values.
- No second independently mutable balance field exists on the Account.
- Multi-account-per-wallet is not supported in this version; the constraint is data-level.

#### FR-12: Chain finality, closer singleton, and recovery

Chain close is a second phase. Entries commit first; chain advancement happens asynchronously
through a **singleton** Chain Closer. The closer buffers per account and processes in sequence
order, so chain order equals sequence order. It inserts a chain-close record (whose uniqueness
constraint absorbs duplicate closes harmlessly), advances the chain head, and **commits before
acknowledging**. A sweeper re-enqueues entries stuck in `CHAIN_PENDING`, reading persisted hashed
fields from storage and never regenerating them.

**Consequences (testable):**
- A closer crash after commit causes no duplicate chain entries.
- The sweeper recovers any `CHAIN_PENDING` entry older than 30s.
- Sweeper-processed entries have hashes identical to what the first closer computed.
- A startup assertion verifies the closer holds a leader lease before it processes anything.

---

### 4.4 Reversal

**Description:** A `POSTED` transfer may be reversed exactly once, in full, by an authorised party.
Realizes UJ-2.

#### FR-13: One-shot full-amount terminal reversal *(new — resolves H-2)*

A `POSTED` transaction may be reversed **exactly once**, for its **full amount**. A reversal may
never itself be reversed, and partial reversal is not permitted. `REVERSED` is terminal.

The reversal appends a **compensating DEBIT/CREDIT pair** with new hashes. The original entries
are never modified or removed.

**If the recipient no longer holds sufficient available funds, the automatic reversal is rejected**
and the case is routed to **manual dispute review**. The system does **not** create a negative user
wallet and does **not** reimburse automatically from a system account.

**Enforcement** is at the data layer, not only in application code: a reversal record carries a
uniqueness constraint on the original transaction, the reversal posts in one ACID transaction, the
status transition is a conditional update, and concurrency locking plus idempotency prevent a
double refund.

**A separate reversal lifecycle** distinguishes reversal posting from reversal chain finality, so
a committed-but-not-yet-chain-closed reversal is distinguishable from a fully final one. Reversal
entries flow through the existing singleton Chain Closer — no separate close machinery.

**Consequences (testable):**
- A second reversal against the same transaction is impossible at the data layer.
- A reversal cannot be reversed; `REVERSED` has no outgoing transition.
- A partial reversal is impossible; the compensating pair always equals the original amount.
- A reversal against a transaction whose recipient lacks sufficient available funds is rejected,
  produces no ledger entries, and creates a dispute-review item.
- No user wallet becomes negative as a result of a rejected reversal.
- Original ledger entries are byte-identical before and after a reversal.
- A reversal with insufficient funds never draws from a system account.

**Out of Scope:**
- Partial refunds and refunds after the reversal window — see §10 (OQ-1, OQ-2).

#### FR-14: Dedicated reversal authority and audit

Reversal requires a **dedicated permission**, separate from transfer-initiate authority and
separate from balance-read authority. Every reversal attempt requires a **reason code** drawn
from a closed set. Every attempt — **granted and rejected alike** — writes an immutable audit
record to the Audit Service's independent chain. Rejected attempts raise a security alert.

**Consequences (testable):**
- A user with transfer authority but not reversal authority cannot trigger a reversal.
- A reversal request without a valid reason code from the closed set is rejected.
- A rejected reversal attempt still produces an audit record and a security alert.
- Audit records for reversals are not present in the ledger chain, and vice versa.

#### FR-15: Reversal policy constraints *(values open)*

Reversal execution is constrained by policy values that are **not yet set**: the reversal time
window, the reversal fee policy, and the higher-value approval threshold. **Production execution
of reversals is disabled until the required policies are approved.**

**Consequences (testable):**
- In production configuration, reversal execution is refused until the required policies are set.
- The mechanism for each policy value is specified and configurable without a schema change.

---

### 4.5 Idempotency

#### FR-16: Idempotent transfer submission

Every transfer submission must carry a client-generated idempotency key, scoped to
`(user_id, idempotency_key)` with a composite uniqueness constraint. Replay returns the original
transaction record and its current status, verbatim. **Failures never clear the key.** A new
logical attempt requires a new key.

**Consequences (testable):**
- The same `(user_id, idempotency_key)` submitted 10× concurrently produces exactly **one**
  transfer.
- Replay of a `FAILED` transaction returns the failure and does **not** clear the key.
- Replay of an in-flight transaction returns current status without side effects.

#### FR-17: Tiered idempotency retention *(proposed — not approved)*

Idempotency-key validity, duplicate-financial-operation prevention, failed/in-flight retry
handling, and audit-record retention are separated into distinct lifetimes, with **no unconditional
nightly deletion** and a defined safe behaviour for retries after expiry.

> ⚠️ **Status: 🟠 Proposed, not approved.** The specification's current behaviour — nightly batch
> deletion of expired records, never on failure — is the fallback until this is decided.

---

### 4.6 Reconciliation and Audit

#### FR-18: Nightly reconciliation of four invariants

A nightly batch over the full active dataset reports four invariants. **Any violation is a tamper
or corruption alert — there is no "expected drift" category.** Invariant 4 is new in this delta.

- **Invariant 1 — Transaction balance.** Exactly one DEBIT and one CREDIT of equal magnitude per
  `transaction_id`.
- **Invariant 2 — Cryptographic continuity.** The chain links unbroken from genesis to the chain
  head.
- **Invariant 3 — Balance reconstruction.** Per account, `opening_balance + credits − debits`
  equals the stored balance.
- **Invariant 4 — Reservation reconciliation.** *(new, from C-1)* A wallet's `reserved_minor`
  equals the sum of its valid active reservations.

**Consequences (testable):**
- All four invariants pass on a clean dataset.
- An injected balance drift is detected by Invariant 3 within one nightly cycle.
- An injected field alteration or a deliberately removed entry is detected by Invariant 2 within
  one nightly cycle.
- A drift between `reserved_minor` and active reservations is detected by Invariant 4.
- The reconciliation engine does **not** silently auto-repair financial state.

#### FR-19: Independent audit chain

The Audit Service maintains its **own independent hash chain**, separate from the ledger chain.
Compromise of one does not permit undetected tampering of the other. Rejections are never silent.

**Consequences (testable):**
- Ledger chain compromise does not permit undetected Audit Service tampering, and vice versa.
- An invalid, forged, or unauthorized debit attempt triggers immediate audit logging with a
  security alert.

---

## 5. Non-Goals (Explicit)

- **Not a blockchain.** No distributed ledger consensus between nodes. The ledger is centralized
  and single-writer by design.
- **Not a marketplace in Phase 1.** Listings, offers, and negotiation are deferred, not cancelled.
- **Not a lending-underwriting system.** The marketplace facilitates; risk decisions are out of
  scope.
- **Not multi-currency.** Phase 1 is BDT only, in integer Poisha. Currency is stored as
  forward-compatible metadata only.
- **Not cross-border.**
- **Not programmable money.** No smart contracts.
- **Not a trustless system.** Peer-to-peer is in discovery and negotiation; settlement is
  centralized and authoritative.
- **Never mutates the ledger.** Corrections are compensating entries, never edits or deletes.
- **Never lets a projection outrank the ledger.** `transactions.status` is a cache; the ledger
  wins.

---

## 6. MVP Scope

### 6.1 In Scope

- User registration and authentication with short-lived signed JWTs.
- KYC status tracking across `PENDING` / `VERIFIED` / `REJECTED` with admin transitions, recorded
  reasons, audit trail, and re-submission.
- Wallet creation with integer-minor-unit balances (posted and reserved).
- Transfer submission with idempotency keys and six-step authorization.
- Reservation-and-atomic-post transfer flow: the payer is debited exactly once.
- Append-only, hash-chained double-entry ledger with per-account sequence numbers.
- Asynchronous chain finality via a singleton Chain Closer, with sweeper recovery.
- One-shot, full-amount, terminal reversal with dedicated authority, reason codes, and immutable
  audit.
- Nightly reconciliation across four invariants, including reservation reconciliation.
- Audit logging with security alerting on an independent audit chain.

### 6.2 Out of Scope for MVP

- **Marketplace listings, browsing, offers, and negotiation** — *deferred to Phase 2*. `[NOTE FOR PM]`
  This is a load-bearing deferral: the specification's §1.2 thesis and §1.3 scope bullets assume
  a marketplace in Phase 1, and the specification's §11.3 already references listing visibility.
  Phase 2 requires its own architecture decision, including how marketplace entities relate to
  C-2's strict one-to-one wallet/account mapping. *It is deferred, not deleted from product scope.*
- **External KYC provider callbacks** — deferred; admin-driven verification is the MVP mechanism.
- **KYC expiry, suspension, and periodic re-verification** — not required in the current version.
- **Multi-currency conversion and cross-border settlement.**
- **Distributed ledger consensus between nodes.**
- **Lending underwriting logic.**
- **Smart contracts or programmable money.**
- **Partial refunds and post-window refunds** — pending the reversal policy values (FR-15).
- **Ledger partitioning** — deferred pending benchmark results; the current claim is scoped to
  MySQL InnoDB foreign-key limitations only.

---

## 7. Cross-Cutting Non-Functional Requirements

*System-wide quality attributes not tied to a single feature.*

### 7.1 Capacity

- Sustained throughput: **200–500 TPS**.
- Burst throughput: **1,000 TPS**.
- Storage growth: 2 ledger entries per transfer plus 1 chain-close record.

### 7.2 Latency

- API confirmation at `CHAIN_PENDING`: **p50 < 50ms**, **p99 < 200ms**.
- Chain finality `CHAIN_PENDING → POSTED`: **p99 < 1.5s**.
- Chain close transaction: **< 1ms**.

### 7.3 Capacity analysis

The Chain Closer is the architectural ceiling: single-writer maximum TPS = 1 ÷ close-commit
latency. At 1ms → 1,000 TPS; at 5ms → 200 TPS; at 10ms → 100 TPS. The 1,000 TPS burst target
therefore requires close commits under 1ms — a hard, testable database requirement. **Load-test the
closer before building the wallet path.** Sustained 500 TPS requires close commits ≤ 2ms.

### 7.4 Retention

- Active data within the retention window: fully indexed.
- Cold archive after **7 years** (regulatory compliance).
- Reconciliation partitioning by year.

### 7.5 Consistency

- **Strong** within the posting transaction.
- **Bounded eventual** between `CHAIN_PENDING` and `POSTED` (< 1.5s p99).
- Ledger: append-only, hash-chained, single source of truth.

### 7.6 Security and privacy

- A peer can never read a counterparty's balance — enforced at the service layer, not by
  client-side filtering.
- Counterparty identity is exposed only as a pseudonymized handle plus credit/risk tier.
- KYC status is a hard precondition for any ledger write path.
- The Audit Service's chain is independent of the ledger chain.

---

## 8. Delta Table — What Changes From the Specification

*The actual new requirements. Each row states the change, its source decision, and its status.*

| # | Spec location | Change required | Source | Status |
|---|---|---|---|---|
| D-1 | §7.2 + §7.3 | **Replace the double-debit flow.** §7.2 no longer debits; it creates a durable Reservation and increments `reserved_minor`. §7.3 debits exactly once, inside the single ACID transaction. | C-1 | 🟡 Provisional |
| D-2 | §3.1, §4, §10.4 | **Add the Ledger Account entity.** `wallets.account_id` FK, strict 1:1 in this version, immutable mapping. No second mutable balance field on Account. | C-2 | 🟡 Provisional |
| D-3 | §3.1 (Wallet) | **Add `reserved_minor`** alongside `balance_minor`. Enforce `0 <= reserved_minor <= balance_minor`. | C-1 | 🟡 Provisional |
| D-4 | §3.1 (Wallet) | **Rename bare `balance` to `balance_minor`** everywhere as the canonical column name. | C-4 | ✅ Technical default — unconfirmed |
| D-5 | §10 | **Add Invariant 4 — reservation reconciliation.** Wallet `reserved_minor` must equal the sum of valid active reservations. No silent auto-repair. | C-1 | 🟡 Provisional |
| D-6 | §3.1 (User), §7.1, §11.1, §14.7 | **Define the `kyc_status` enum** as `PENDING` / `VERIFIED` / `REJECTED`, with admin-only transitions, recorded reasons, audit trail, and `REJECTED → PENDING` re-submission. Reconcile §7.1's literal `KYC_VERIFIED` check with the enum's stored representation. | H-5 | 🟡 Provisional |
| D-7 | §1.3, §1.2 | **Remove marketplace from Phase 1 scope.** §1.3's marketplace bullet and §1.2's "browse listings" phrase must be amended to reflect deferral to Phase 2. Marketplace remains in product scope. | H-4 | 🟡 Provisional |
| D-8 | §6.4, §6.1, §6.2 | **Add H-2's reversal rules:** one-shot, full-amount, terminal; no reversal-of-reversal; no partial reversal. | H-2 | 🟡 Provisional |
| D-9 | §6.4 (new) | **Add the reversal shortfall rule.** Insufficient recipient funds → reject the automatic reversal → manual dispute review. No negative wallet, no automatic system-funded reimbursement. | H-2 | 🟡 Provisional |
| D-10 | §6.1, §6.2 (new) | **Add a separate reversal lifecycle** distinguishing reversal posting from reversal chain finality. | H-2 | 🟡 Provisional |
| D-11 | §2.1, §11.2 (new) | **Add a dedicated reversal permission**, a closed-set reason code, and immutable audit of every attempt (granted and rejected) with security alerting on rejection. | H-2 | 🟡 Provisional |
| D-12 | §12 / new section | **Add reversal policy constraints** — time window, fee policy, higher-value approval threshold — with production reversal execution disabled until set. | H-2 | 🟡 Provisional |
| D-13 | §10.4, §3.1 | **Replace the seeded `opening_balance` with a derived value.** Funding must be a balanced, auditable ledger entry pair from explicitly typed SYSTEM accounts. | H-1 | 🟠 **Proposed — not approved** |
| D-14 | §3.1 (Ledger Entry), §9 | **Remove the `status` column from `ledger_entries`**; finality is proven by durable chain-close records. | H-6 | 🟠 **Proposed — not approved** |
| D-15 | §8, new section | **Replace unconditional idempotency cleanup with tiered retention** separating key validity, duplicate prevention, retry handling, and audit retention. | M-4 | 🟠 **Proposed — not approved** |
| D-16 | §5.3, new section | **Specify the canonicalization contract in full** — exact canonicalization rules, field order, integer encoding, timestamp precision, null policy, `prev_hash` encoding, and cross-language SHA-256 test vectors. Do not label a custom fixed-key-order format as RFC 8785 unless it conforms. | M-2 | ⚪ **Blocked** |
| D-17 | §12.4, new section | **Defer partitioning.** Document MySQL InnoDB partitioning and foreign-key limitations. Do not claim partitioning inherently destroys sequence uniqueness in all architectures. Revisit after benchmarks without weakening sequence, FK, or chain invariants. | M-1 | 🟠 **Proposed — not approved** |
| D-18 | §7.1 step 6 | **Define the business limit mechanism** as a configurable positive per-transaction ceiling. Numeric value, daily cumulative caps, and regulatory ceilings remain open; no compliance rules may be invented. Production real-money transfers stay disabled until approved. | C-3 | 🟠 **Proposed — not approved** |
| D-19 | §5.3 | **Publish the genesis literal constant.** | M-3 | ⚪ Open — value not chosen |
| D-20 | §8.1 | **Define `request_fingerprint`** — SHA-256 of the canonical request payload — and a mismatch returns **409**. | M-5 | ✅ Technical default — unconfirmed |
| D-21 | §5.3, §11.5 | **Specify a separate canonical form and separate genesis for the audit chain.** Never share the ledger genesis. | H-8 | ✅ Technical default — unconfirmed |
| D-22 | §6.3 | **Define `lock_epoch` scope** as a per-transaction monotonic counter, incremented on each lock acquisition. | H-7 | ✅ Technical default — unconfirmed |
| D-23 | §3.1 (Wallet), new | **Represent currency as `CHAR(3)` ISO-4217**, default `'BDT'`, with a CHECK constraint. | M-8 | ✅ Technical default — unconfirmed |
| D-24 | §6.3 | **Define the lease TTL and Reaper interval** as configuration, exceeding typical Phase 3 latency and below the user-visible SLA. A 30s default is recommended. | H-3 | ✅ Technical default — unconfirmed |
| D-25 | §12.4 | **Defer the 7-year cold-archive mechanism.** No deletion before legal review. | M-6 | ⏸️ Deferred — regulatory |

---

## 9. Success Metrics

**Primary**
- **SM-1**: Ledger consistency — all four reconciliation invariants pass on every nightly cycle
  with zero violations over 30 consecutive days. Validates FR-8, FR-18.
- **SM-2**: Transfer correctness — zero instances of a payer debited more than once for a single
  successful transfer; zero double-refunds from reversal concurrency, detected by the uniqueness
  constraint and confirmed by nightly reconciliation. Validates FR-10, FR-13.
- **SM-3**: Throughput — 500 TPS sustained for 1 hour with p99 API confirmation < 200ms and chain
  finality p99 < 1.5s. Validates FR-12, FR-17.

**Secondary**
- **SM-4**: Recovery correctness — 100% of `CHAIN_PENDING` entries older than 30s recovered by the
  sweeper, with hashes identical to the first closer's computation. Validates FR-12.
- **SM-5**: Idempotency correctness — zero duplicate money movements from replayed or concurrent
  submissions in production. Validates FR-16.
- **SM-6**: Audit completeness — every reversal attempt (granted and rejected) has an audit record
  in the independent chain. Validates FR-14, FR-19.

**Counter-metrics (do not optimize)**
- **SM-C1**: Maximum single-wallet balance concentration — not to be optimized; a system that
  rewards balance concentration may be masking missing transfer volume.
- **SM-C2**: Reversal count per transaction — not to be optimized; a reversal rate that *falls*
  because reversals are hard to request, rather than because transfers are correct, indicates a
  usability or trust failure rather than success.

---

## 10. Open Questions

*Numbered. These become follow-up tickets, research, or architecture decisions — never silent gaps.*

### 10.1 Policy values — required before production, not before data modeling

- **OQ-1**: What is the **reversal time window**? The mechanism is specified; the value is not.
  What happens to a reversal request after the window expires — refused, or routed to a separate
  dispute process?
- **OQ-2**: What is the **reversal fee policy**? Is a fee charged, to whom, and is it retained by
  the platform or passed to the payer?
- **OQ-3**: What is the **higher-value reversal approval threshold** at which a second approval is
  required? What is the "high value" number?
- **OQ-4**: What is the **numeric per-transaction ceiling**, the **daily cumulative cap**, and is
  any **regulatory ceiling** applicable? *(No compliance rules may be invented — this requires the
  user's operating context or legal counsel.)*
- **OQ-5**: What is the **exact value** of the lease TTL and the Reaper interval? *(A 30s default
  is recommended; the value is unconfirmed.)*
- **OQ-6**: What is the **genesis literal constant** for the ledger chain, and what is the separate
  genesis for the audit chain?

### 10.2 Technical decisions — required before implementation

- **OQ-7**: What are the **exact canonicalization rules and cross-language test vectors** for the
  hashing contract — key order, integer encoding, timestamp precision, null policy, `prev_hash`
  encoding? *(Blocks writing `canonical_json()` or any verifier. Does **not** block table and
  column design.)*
- **OQ-8**: Should the **reversal record** be keyed by the original `transaction_id` alone, and
  does it carry its own `transaction_id` so that the compensating pair does not disturb Invariant
  1's per-transaction entry count? *(This must be settled before the schema is drawn, because
  §4.3's "exactly one DEBIT and one CREDIT per `transaction_id`" test must remain true for a
  reversed transaction.)*
- **OQ-9**: How is the **`REVERSED` projection** rebuilt under §4.3, given that a reversed
  transaction has four entries rather than two? The ledger-wins rule needs a defined derivation
  here, not just a rule.
- **OQ-10**: What is the **audit write ordering** relative to the reversal's ledger commit? If the
  audit write is in the same transaction, an Audit Service outage blocks reversals; if it is not,
  §14.7's "compromise does not permit undetected Audit Service tampering" needs an explicit
  ordering statement.
- **OQ-11**: Should the **audit chain** be a separate table or a separate schema, and does it
  share the ledger's canonicalization? *(H-8 proposes a separate canonical form and genesis;
  unconfirmed.)*
- **OQ-12**: Does the **sweeper's 30s threshold** and the **page threshold of 30s** in the
  operational signal remain identical? *(This is **not** the withdrawn M-7. M-7 concerned the
  margin against the closer's p99.9 processing time and was withdrawn as a false positive. This is
  a separate observation: at the moment a paged entry is simultaneously eligible for re-enqueue,
  so there is no window in which a stuck entry is alerted but not yet swept. Confirm whether that
  is intended.)*
- **OQ-13**: Is the **`POSTED` status write** intentionally outside the chain-close ACID unit and
  after the broker acknowledgement? *(The specification's §7.4 ordering implies yes, but the
  consequence — a crash leaving a closed entry with a stale `CHAIN_PENDING` projection — is not
  stated, and is only tolerable because §4.3 makes the status a rebuildable projection. Confirm
  and state it.)*

### 10.3 Scope and product

- **OQ-14**: When the marketplace returns in Phase 2, how do marketplace entities relate to C-2's
  strict 1:1 wallet/account mapping? *(Deferred by H-4; needs its own architecture decision.)*
- **OQ-15**: Should the **KYC admin action** be a first-class role, a permission, or part of an
  existing operations role? *(H-5 approves the authority model — "authorized KYC admin" — but does
  not name the role mechanism.)*
- **OQ-16**: Is there a **dispute-review workflow and data model** for rejected reversals, or is
  manual review handled outside the system? *(H-2 mandates manual dispute review but does not
  specify whether that queue is in-system.)*

---

## 11. Assumptions Index

*Every `[ASSUMPTION]` in this document, surfaced for explicit confirmation.*

- **[ASSUMPTION: §7.2]**: The specification's §7.2 debit was a drafting artifact of the escrow
  concept rather than an intentional two-phase money movement — hence C-1 replaces it with a
  reservation rather than treating the second debit as intentional. *Source: C-1's Option A3 and
  its 9 mandatory conditions.*
- **[ASSUMPTION: §11.3 / §12.4]**: The specification's references to "credit/risk tier" and
  "marketplace listing" visibility describe Phase 2 surface rather than a Phase 1 requirement —
  hence H-4's deferral leaves them out of scope rather than contradicting them.
- **[ASSUMPTION: §4.3]**: A reversal's compensating pair is recorded under a distinct
  transaction identifier, so that Invariant 1's "exactly one DEBIT and one CREDIT per
  `transaction_id`" continues to hold for the original transaction. *This is the basis of OQ-8 and
  is not yet decided.*
- **[ASSUMPTION: §12.3]**: The 500 TPS sustained target is intended to sit at the top of the
  200–500 band, which implies close commits ≤ 2ms — a row absent from the specification's own
  TPS table.
- **[ASSUMPTION: §3.1]**: `currency` is fixed to BDT for the current version, so the ISO-4217
  `CHAR(3)` representation is forward-compatible metadata rather than an active multi-currency
  capability. *Source: M-8's recommendation, unconfirmed.*

---

## 12. Remaining Blockers

### 12.1 Blockers to safe data modeling (ERD / schema)

**None outstanding.** The three original hard blockers are resolved:

| Original blocker | Resolved by | Status |
|---|---|---|
| H-2 — reversal constraints | FR-13, FR-14, FR-15 | 🟡 Provisional |
| H-4 — marketplace scope | FR-14 (scope), §6.2 | 🟡 Provisional |
| H-5 — KYC states | FR-2 | 🟡 Provisional |

Two schema-level questions remain and must be answered **during** schema design rather than
before it:

- **OQ-8** — the reversal record's keying and its relationship to Invariant 1's per-transaction
  entry count.
- **OQ-9** — how `REVERSED` is derived under §4.3, given four entries instead of two.

### 12.2 Blockers to writing the hashing code

- **OQ-7** — the canonicalization contract and cross-language test vectors. Ledger **tables and
  columns** can be designed now; hashing code cannot be written until this is settled.

### 12.3 Blockers to production operation

- **OQ-1, OQ-2, OQ-3** — reversal window, fee policy, approval threshold. **Production reversal
  execution stays disabled until these are approved** (FR-15).
- **OQ-4** — business-limit value and regulatory applicability. **Production real-money transfers
  stay disabled until approved** (D-18).

### 12.4 Items requiring a separately reviewed architecture decision

- **D-13 (H-1)** — SYSTEM accounts and the derived `opening_balance`. Currently 🟠 proposed. Note
  the coupling: **C-1's Invariant 4 and H-2's shortfall rule are both satisfiable without H-1**,
  but a wallet that can be funded from outside the ledger has no auditable funding source without
  it.
- **D-17 (M-1)** — partitioning, deferred pending benchmarks.
- **D-25 (M-6)** — cold archive, deferred pending legal review.

### 12.5 Approval ceiling

Every decision in this document is **🟡 Provisional** or **⚪ Open**. **None is finally approved.**
The specification remains unmodified and authoritative; this delta layers on top of it and must be
merged into it only through a separate, explicitly-approved change.
