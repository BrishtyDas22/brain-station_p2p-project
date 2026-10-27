---
type: initiative
title: P2P Settlement
parent: none
status: draft
created: 2026-10-26
updated: 2026-10-26
sources:
  - ../../../P2P_SPECIFICATION.md
  - ../planning-artifacts/grill-me-decision-register.md
  - ../planning-artifacts/prds/prd-p2p-platform-2026-10-26/prd.md
companions:
  - ./architecture-p2p-settlement/architecture-p2p-settlement.md
---

# Initiative: P2P Settlement — Core Fund Transfer & Transaction Settlement

> **Approval ceiling — read first.**
> Every requirement below derives from decisions that are **🟡 Provisional**, **🟠 Proposed
> (not approved)**, **⚪ Open**, or **✅ Technical default (unconfirmed)**. **Nothing in this
> initiative is finally approved.** The authoritative specification remains `P2P_SPECIFICATION.md`
> and is **unmodified**. This ticket layers on top of it.
>
> **No real-money production enablement is authorised by this ticket.** Production transfer
> execution and production reversal execution stay disabled until the policy values in
> [Open questions](#6-open-questions) are explicitly approved by the user.

**References.**
- `architecture-p2p-settlement/architecture-p2p-settlement.md` — the architecture spine and MySQL
  data model, **adopted as a companion of this ticket** on 2026-10-26. Its stable ids (`A1`–`A18`)
  are cited by the epics and their tickets. The spine's decisions are **⚙️ Architecture defaults**;
  it grants no approval, and its §5.2 and §5.3 lists remain open until the user signs them off.

---

## 1. Summary

A peer-to-peer fund transfer and transaction settlement platform for a micro-finance context.
Users transfer money to one another; **every movement of money is recorded in an append-only,
cryptographically verifiable double-entry ledger** that can be independently audited and proven
consistent.

The system thesis is load-bearing: **P2P in discovery and negotiation; centralized in
authoritative settlement.** The Transaction Service is the sole authority that touches the ledger.
The ledger is single, global, and provably correct. A genuinely distributed ledger would be a
blockchain; the specification records that its multi-year implementation cost and
distributed-consensus surface are **not justified by the stated requirements**.

**Phase 1 delivers the transfer and settlement core only.** The marketplace is **deferred to
Phase 2, not abandoned** (H-4).

---

## 2. Scope

### 2.1 In scope (Phase 1)

- User registration and authentication with short-lived signed JWTs.
- KYC status tracking across `PENDING` / `VERIFIED` / `REJECTED` with admin transitions, recorded
  reasons, audit trail, and re-submission.
- Wallet creation with integer-minor-unit balances (posted and reserved).
- Transfer submission with idempotency keys and six-step authorization.
- Reservation-and-atomic-post transfer flow: the payer is debited **exactly once**.
- Append-only, hash-chained double-entry ledger with per-account sequence numbers.
- Asynchronous chain finality via a singleton Chain Closer, with sweeper recovery.
- One-shot, full-amount, terminal reversal with dedicated authority, reason codes, and immutable
  audit.
- Nightly reconciliation across four invariants, including reservation reconciliation.
- Audit logging with security alerting on an independent audit chain.

### 2.2 Out of scope for Phase 1

| Deferred item | Status |
|---|---|
| Marketplace listings, browsing, offers, negotiation | ⏸️ **Phase 2.** Deferred, *not* deleted from product scope (H-4). Requires its own architecture decision, including how marketplace entities relate to R9's strict 1:1 wallet/account mapping. |
| External KYC provider callbacks | ⏸️ Deferred. Admin-driven verification is the MVP mechanism (H-5). |
| KYC expiry, suspension, periodic re-verification | ⏸️ Not required in the current version. |
| Multi-currency conversion, cross-border settlement | ⏸️ Out of scope. `currency` is forward-compatible metadata only. |
| Distributed ledger consensus between nodes | ⏸️ Out of scope by design. |
| Lending underwriting logic | ⏸️ Out of scope. |
| Smart contracts / programmable money | ⏸️ Out of scope. |
| Partial refunds, post-window refunds | ⏸️ Pending reversal policy values (see OQ-1, OQ-2). |
| Ledger partitioning | ⏸️ Deferred pending benchmark results (M-1, 🟠 proposed). |
| 7-year cold-archive mechanism | ⏸️ Deferred pending legal review (M-6). No deletion before legal review. |

### 2.3 Non-goals (explicit)

- **Not a blockchain.** No distributed ledger consensus between nodes.
- **Not a trustless system.** Peer-to-peer is in discovery and negotiation; settlement is
  centralized and authoritative.
- **Never mutates the ledger.** Corrections are compensating entries, never edits or deletes.
- **Never lets a projection outrank the ledger.** `transactions.status` is a cache; the ledger
  wins.

---

## 3. Requirements

*One line per requirement. Each carries a stable `R<n>` id and names its source.
`R<n>` ids are permanent — they are cited by epics and stories. Source ids used here:
`FR-<n>` = PRD delta requirement · `C-<n>`, `H-<n>`, `M-<n>` = Grill Me decision-register gap ·
`SPEC §<n>` = authoritative specification section.*

### 3.1 Identity, KYC, and wallets

- **R1 (FR-1, SPEC §7.1)** — A visitor can register and authenticate with a short-lived signed JWT.
  Every transfer instruction, regardless of origin, terminates at the Transaction Service; there
  is no direct peer write path. An unauthenticated or invalid-signature request is rejected
  before any ledger path is touched.
- **R2 (FR-2, H-5, SPEC §7.1)** — A user's `kyc_status` is exactly one of `PENDING`, `VERIFIED`,
  `REJECTED`. New users begin `PENDING`. An **authorized KYC admin** may transition
  `PENDING → VERIFIED` or `PENDING → REJECTED`, recording a reason and an audit trail entry.
  A `REJECTED` user may resubmit, returning to `PENDING`. **Users cannot self-verify.** Only a
  `VERIFIED` user may initiate a transfer.
- **R3 (FR-3, C-4, M-8, SPEC §3.1)** — A user holds a Wallet with a currency, a posted balance
  (`balance_minor`), and a reserved balance (`reserved_minor`), all in **integer minor units
  (Poisha)**. Floating-point arithmetic is forbidden anywhere in the money path. Available balance
  is `balance_minor − reserved_minor` and is never negative. A user holds at most one Wallet per
  currency. `balance_minor` is the canonical column name (✅ technical default, unconfirmed).
- **R4 (FR-10, C-1)** — `0 <= reserved_minor <= balance_minor` holds at all times. No user wallet
  is ever negative.

### 3.2 The ledger

- **R5 (FR-4, SPEC §4.1)** — A transfer produces exactly two Ledger Entries of equal magnitude and
  opposite direction — one DEBIT and one CREDIT — so that for every `transaction_id`,
  `SUM(DEBIT) = SUM(CREDIT)`, with exactly one of each. A fault between debit and credit insert
  produces zero entries.
- **R6 (FR-5, SPEC §4.2)** — Ledger Entries are never updated and never deleted. No `UPDATE` or
  `DELETE` statement exists against `ledger_entries` in application code. Corrections are made
  only by appending compensating entries.
- **R7 (FR-6, SPEC §5.1–5.5)** — Each Ledger Entry's hash is computed over a canonical JSON
  serialization of a fixed set of hashed fields concatenated with the previous entry's hash. The
  chain head is a singleton holding the hash of the most recently closed entry. `balance_after` is
  **deliberately excluded** from the hashed body.
- **R8 (FR-7, SPEC §4.4)** — Every Ledger Entry carries a monotonic `account_sequence_number`,
  unique and gapless per `account_id`, assigned inside the posting transaction. Chain order
  matches sequence order for every account.
- **R9 (FR-11, C-2, SPEC §3.1)** — A Wallet maps to exactly one Ledger Account via a
  `wallets.account_id` foreign key. The mapping is **immutable**. `account_id` sits inside the
  hashed body of every entry, so the field name is permanent. `wallet_id` and `account_id` are
  **not** assumed to have identical numeric values. **No second independently mutable balance
  field exists on the Account.** Multi-account-per-wallet is out of scope in this version.
- **R10 (FR-8, SPEC §4.3)** — `transactions.status` is a **rebuildable projection**, not
  authoritative. It can be reconstructed from ledger entries at any time. If the projection and
  the ledger-derived state disagree, **the ledger wins**.
- **R11 (FR-16, SPEC §8)** — Every transfer submission carries a client-generated idempotency
  key scoped to `(user_id, idempotency_key)` with a composite uniqueness constraint. Replay
  returns the original transaction record and its current status, verbatim. **Failures never
  clear the key.** A new logical attempt requires a new key.

### 3.3 Transfer execution

- **R12 (FR-9, SPEC §7.1, §11.2)** — Before any ledger path is touched, the Transaction Service
  validates in order: the JWT; the requester's KYC status; that the initiator owns the payer
  wallet; that the counterparty owns the payee wallet; the idempotency key; and that the amount
  is positive and within the business limit. Any failure at the ownership or KYC checks is logged
  to the Audit Service with a security alert.
- **R13 (FR-10, C-1, SPEC §7.2–7.3)** — The payer is debited **exactly once** across the whole
  flow. **Phase 2 — Reserve:** the payer is *not* debited; a durable Reservation record is created
  and `reserved_minor` incremented atomically via a concurrency-safe conditional update.
  **Phase 3 — Post:** one ACID transaction consumes the Reservation, debits the payer exactly
  once, credits the payee, assigns sequences, inserts both entries, and transitions to
  `CHAIN_PENDING`. The conditional update is the anti-TOCTOU primitive: two concurrent
  transfers for the full available balance produce at most one success.
- **R14 (FR-10, C-1)** — Reservations have durable, uniquely identifiable records. Reserve and
  record update atomically. Reaper expiry/release and posting are **mutually exclusive** (locking
  plus fencing): a reservation is never released twice, nor released after successful posting.
- **R15 (FR-12, SPEC §7.4, §9.1–9.3)** — Chain close is a second phase. Entries commit first;
  chain advancement happens asynchronously through a **singleton** Chain Closer. The closer
  buffers per account and processes in sequence order, so chain order equals sequence order. It
  inserts a chain-close record (whose uniqueness constraint absorbs duplicate closes
  harmlessly), advances the chain head, and **commits before acknowledging**. A sweeper
  re-enqueues entries stuck in `CHAIN_PENDING`, reading **persisted** hashed fields from storage
  and never regenerating them. A startup assertion verifies the closer holds a leader lease before
  it processes anything.
- **R16 (SPEC §6.1–6.3, H-7)** — The transaction state machine is
  `INITIATED → PENDING → LOCKED → CHAIN_PENDING → POSTED`, with terminal `FAILED` and `REVERSED`.
  Legality is enforced **in the database** via CHECK constraints and triggers, not only in
  application code. Every write in the posting transaction is guarded by a conditional update on
  `status` and `lock_epoch`; zero rows affected means the worker lost the lock and must roll back
  immediately. `lock_epoch` is a per-transaction monotonic counter, incremented on each lock
  acquisition (✅ technical default, unconfirmed).

### 3.4 Reversal

- **R17 (FR-13, H-2, SPEC §6.4)** — A `POSTED` transaction may be reversed **exactly once**, for
  its **full amount**. A reversal may never itself be reversed. Partial reversal is not
  permitted. `REVERSED` is terminal. The reversal appends a **compensating DEBIT/CREDIT pair**
  with new hashes; the original entries are never modified or removed.
- **R18 (FR-13, H-2)** — If the recipient no longer holds sufficient available funds, the
  automatic reversal is **rejected** and the case is routed to **manual dispute review**. The
  system does **not** create a negative user wallet and does **not** reimburse automatically from
  a system account.
- **R19 (FR-13, H-2)** — Reversal uniqueness is enforced at the data layer: a reversal record
  carries a uniqueness constraint on the original transaction, the reversal posts in one ACID
  transaction, the status transition is a conditional update, and concurrency locking plus
  idempotency prevent a double refund.
- **R20 (FR-13, H-2)** — A **separate reversal lifecycle** distinguishes reversal posting from
  reversal chain finality, so a committed-but-not-yet-chain-closed reversal is distinguishable
  from a fully final one. Reversal entries flow through the existing singleton Chain Closer — no
  separate close machinery.
- **R21 (FR-14, H-2, SPEC §11.4)** — Reversal requires a **dedicated permission**, separate from
  transfer-initiate authority and separate from balance-read authority. Every reversal attempt
  requires a **reason code** drawn from a closed set. Every attempt — **granted and rejected
  alike** — writes an immutable audit record to the Audit Service's independent chain. Rejected
  attempts raise a security alert.
- **R22 (FR-15, H-2)** — Reversal execution is constrained by policy values that are **not yet
  set**: the reversal time window, the reversal fee policy, and the higher-value approval
  threshold. **Production execution of reversals is disabled until the required policies are
  approved.** The mechanism for each policy value is configurable without a schema change.

### 3.5 Reconciliation and audit

- **R23 (FR-18, C-1, SPEC §10.1–10.4)** — A nightly batch over the full active dataset reports
  four invariants. **Any violation is a tamper or corruption alert — there is no "expected drift"
  category.** (1) Transaction balance: exactly one DEBIT and one CREDIT of equal magnitude per
  `transaction_id`. (2) Cryptographic continuity: the chain links unbroken from genesis to the
  chain head. (3) Balance reconstruction: per account, `opening_balance + credits − debits`
  equals the stored balance. (4) **Reservation reconciliation:** a wallet's `reserved_minor` equals
  the sum of its valid active reservations.
- **R24 (FR-18, C-1)** — The reconciliation engine does **not** silently auto-repair financial
  state. A drift finding is a bug or an attack, full stop.
- **R25 (FR-19, H-8, SPEC §11.5)** — The Audit Service maintains its **own independent hash
  chain**, separate from the ledger chain. Compromise of one does not permit undetected tampering
  of the other. Rejections are never silent. An invalid, forged, or unauthorized debit attempt
  triggers immediate audit logging with a security alert.

### 3.6 Constraints

- **R26 (SPEC §2.2, §12.1–12.3)** — The Chain Closer is a **singleton**. Scaling it beyond one
  instance is a correctness incident, not a performance change. Sustained throughput target
  200–500 TPS; burst target 1,000 TPS. API confirmation at `CHAIN_PENDING`: p50 < 50ms, p99 <
  200ms. Chain finality `CHAIN_PENDING → POSTED`: p99 < 1.5s. Chain close transaction: < 1ms.
- **R27 (SPEC §12.3)** — The Chain Closer is the architectural ceiling: single-writer max TPS =
  1 ÷ close-commit latency. The 1,000 TPS burst target therefore requires close commits under 1ms
  — a hard, testable database requirement. **Load-test the closer before building the wallet
  path.**
- **R28 (SPEC §11.3)** — A peer can never read a counterparty's balance. Enforced at the service
  layer, not by client-side filtering. Counterparty identity is exposed only as a pseudonymized
  handle.
- **R29 (C-3)** — A configurable **positive per-transaction business limit** must exist. The
  numeric limit, daily cumulative limits, and any applicable regulatory ceiling are **open**.
  **Real-money production transfers are not enabled until these controls are approved.**
  No compliance rules are to be invented.
- **R30 (M-2, SPEC §5.1)** — The hashing contract is deterministic and byte-level. The exact
  canonicalization rules — field order, integer encoding, timestamp precision, null policy,
  `prev_hash` encoding — plus cross-language SHA-256 test vectors **must be fully specified
  before any hashing code is written**. A custom fixed-key-order format must not be labelled
  RFC 8785 unless it actually conforms.

---

## 4. Priorities

*Ordered. P1 must exist before P2 is meaningful; P2 must exist before P3 is meaningful.*

| Priority | Scope | Rationale |
|---|---|---|
| **P1 — Ledger core** | Ledger accounts, wallets, balances, ledger entries, hash chain, chain head, chain close, per-account sequences | The ledger is the product. Everything else is a client of it. |
| **P1 — Authorization & KYC** | Registration, JWT, KYC state machine with admin transitions and audit | A transfer path that cannot reject a non-`VERIFIED` user is not safe to ship at all. |
| **P2 — Transfer execution** | Idempotency, reservation, atomic post, state machine, fencing, sweeper, reaper | The core user journey. Needs P1 to exist. |
| **P2 — Reversal** | One-shot terminal reversal, dedicated authority, reason codes, separate reversal lifecycle | Required by H-2 but sits on top of the posting path; can follow transfer execution. |
| **P3 — Reconciliation & audit hardening** | Four nightly invariants, independent audit chain, security alerting, `OLDEST_CHAIN_PENDING_AGE` paging | Proves the P1/P2 guarantees. Cannot be retrofitted cheaply once data exists. |
| **P4 — Production enablement** | Business-limit values, reversal window/fee/threshold, capacity load test | **Blocked** on user-approved policy values. No real-money enablement without them. |

> **P1 and P2 are effectively one delivery** for a financial system: shipping a writable ledger
> without the idempotency and concurrency guarantees is worse than shipping nothing, because it
> creates money movement that cannot be safely corrected.

---

## 5. Acceptance criteria

*Initiative-level. Each is verifiable and traceable to a requirement. Epic and story level
criteria are cut from these by Sofia when each epic is refined.*

### 5.1 Ledger integrity

- **AC1 (R5, R6)** — For every `transaction_id`, `SUM(DEBIT) = SUM(CREDIT)` with exactly one of
  each. A static scan of application code finds no `UPDATE` or `DELETE` against the ledger
  entries table.
- **AC2 (R7)** — Given any altered field in a hashed entry, chain verification fails at that
  entry and every subsequent link. Given a full ledger replay in chain order, the final hash
  equals the chain head's current hash. `balance_after` is not present in the hashed body.
- **AC3 (R8)** — Two entries for the same account never share a sequence number. Chain order
  matches sequence order for every account.
- **AC4 (R9)** — Each Wallet maps to exactly one Ledger Account and the mapping never changes. No
  second independently mutable balance field exists on the Account.

### 5.2 Atomicity and concurrency

- **AC5 (R13)** — Two concurrent transfers each for the full wallet balance: **at most one
  succeeds**. A fault injected between debit and credit insert produces **zero** ledger entries.
  The payer's posted balance is reduced exactly once for a single successful transfer.
- **AC6 (R14, R16)** — A paused worker resuming with a stale `lock_epoch` has its write
  **rejected at commit** (zero rows affected ⇒ immediate rollback). A reservation is never
  released twice, nor released after successful posting.
- **AC7 (R15)** — A fault injected after posting commit but before queue emit leaves the
  transaction `CHAIN_PENDING`, recoverable by sweeper. A closer crash after commit causes no
  duplicate chain entries. Sweeper-processed entries have hashes identical to what the first
  closer computed.

### 5.3 Idempotency

- **AC8 (R11)** — The same `(user_id, idempotency_key)` submitted 10× concurrently produces
  exactly **one** transfer. Replay of a `FAILED` transaction returns the failure and does **not**
  clear the key. Replay of an in-flight transaction returns current status without side effects.

### 5.4 Reversal

- **AC9 (R17, R19)** — A second reversal against the same transaction is impossible at the data
  layer. A reversal cannot be reversed; `REVERSED` has no outgoing transition. A partial reversal
  is impossible; the compensating pair always equals the original amount. Original ledger entries
  are byte-identical before and after a reversal.
- **AC10 (R18)** — A reversal against a transaction whose recipient lacks sufficient available
  funds is rejected, produces no ledger entries, and creates a dispute-review item. No user wallet
  becomes negative. The reversal never draws from a system account.
- **AC11 (R21)** — A user with transfer authority but not reversal authority cannot trigger a
  reversal. A reversal request without a valid reason code from the closed set is rejected. A
  rejected reversal attempt still produces an audit record and a security alert.

### 5.5 Reconciliation, audit, and security

- **AC12 (R23)** — All four invariants pass on a clean dataset. Injected balance drift is
  detected by Invariant 3 within one nightly cycle. An injected field alteration or a deliberately
  removed entry is detected by Invariant 2 within one nightly cycle. A drift between
  `reserved_minor` and active reservations is detected by Invariant 4.
- **AC13 (R10, R23)** — `transactions.status` is fully rebuildable from entries alone.
- **AC14 (R25)** — Ledger chain compromise does not permit undetected Audit Service tampering,
  and vice versa. Audit records for reversals are not present in the ledger chain, and vice
  versa.
- **AC15 (R12, R28)** — A peer cannot read any balance other than their own, verified by
  attempting every API path. An unauthorized debit attempt is rejected before the ledger path and
  raises a security alert.

### 5.6 KYC

- **AC16 (R2)** — A `PENDING` or `REJECTED` user attempting to initiate a transfer is rejected
  at the authorization boundary, before the ledger path, with a security alert. Every KYC
  transition writes an audit record carrying actor, prior state, new state, and reason. A user
  cannot reach `VERIFIED` by any path other than an admin transition. A `REJECTED` user who
  resubmits has status `PENDING`, never `VERIFIED` directly.

### 5.7 Performance and production gates

- **AC17 (R26, R27)** — 500 TPS sustained for 1 hour with p99 < 200ms API confirmation. 1,000 TPS
  burst with p99 < 1.5s chain finality. Closer sustains required throughput with close commits
  < 1ms. Nightly reconciliation completes within its window at full data volume.
- **AC18 (R22, R29)** — In production configuration, reversal execution is refused until the
  required reversal policies are set. Real-money transfer execution is refused until the business
  limit and any applicable cumulative or regulatory ceiling are approved.

---

## 6. Open questions

*Recorded here rather than silently assumed. Each is blocking for the work named in its
"blocks" line, and none is resolved by this ticket.*

| # | Question | Blocks | Status |
|---|---|---|---|
| **OQ-1** | What is the **reversal time window**? The mechanism is specified; the value is not. What happens to a reversal request after the window expires — refused, or routed to a separate dispute process? | Production reversal execution (P4) | ⚪ **Open** |
| **OQ-2** | What is the **reversal fee policy**? Is a fee charged, to whom, and is it retained by the platform or passed to the payer? | Production reversal execution (P4) | ⚪ **Open** |
| **OQ-3** | What is the **higher-value reversal approval threshold** at which a second approval is required? What is the "high value" number? | Production reversal execution (P4) | ⚪ **Open** |
| **OQ-4** | What is the **numeric per-transaction ceiling**, the **daily cumulative cap**, and is any **regulatory ceiling** applicable? *(Requires the user's operating context or legal counsel. No compliance rules may be invented.)* | Production real-money transfers (P4) | ⚪ **Open** |
| **OQ-5** | What is the **exact value** of the lease TTL and the Reaper interval? *(30s recommended; unconfirmed.)* | Reaper implementation tuning | ✅ Technical default — unconfirmed |
| **OQ-6** | What is the **genesis literal constant** for the ledger chain, and what is the separate genesis for the audit chain? | Chain bootstrap | ⚪ **Open** (M-3) |
| **OQ-7** | What are the **exact canonicalization rules and cross-language SHA-256 test vectors** for the hashing contract — key order, integer encoding, timestamp precision, null policy, `prev_hash` encoding? | Writing `canonical_json()` or any verifier. **Does not block table and column design.** | ⚪ **Blocked** (M-2) |
| **OQ-8** | Should the **reversal record** be keyed by the original `transaction_id` alone, and does it carry its own `transaction_id` so the compensating pair does not disturb Invariant 1's per-transaction entry count? | Schema design of the reversal record | ⚪ **Open** — resolved in the architecture spine as a design decision, pending user approval |
| **OQ-9** | How is the **`REVERSED` projection** rebuilt under §4.3, given a reversed transaction has four entries rather than two? | Projection rebuild logic | ⚪ **Open** — resolved in the architecture spine as a design decision, pending user approval |
| **OQ-10** | What is the **audit write ordering** relative to the reversal's ledger commit? If the audit write is in the same transaction, an Audit Service outage blocks reversals; if it is not, §14.7's "compromise does not permit undetected Audit Service tampering" needs an explicit ordering statement. | Audit reliability posture | ⚪ **Open** — resolved in the architecture spine as a design decision, pending user approval |
| **OQ-11** | Should the **audit chain** be a separate table or a separate schema, and does it share the ledger's canonicalization? *(H-8 proposes a separate canonical form and genesis; unconfirmed.)* | Audit storage design | ✅ Technical default — unconfirmed; spine proposes a choice |
| **OQ-12** | Does the **sweeper's 30s threshold** and the **page threshold of 30s** remain identical? *(Not the withdrawn M-7, which concerned the margin against the closer's p99.9. This is a separate observation: at the moment a paged entry is simultaneously eligible for re-enqueue, so there is no window in which a stuck entry is alerted but not yet swept.)* | Operational alerting design | ⚪ **Open** — observational, needs confirmation |
| **OQ-13** | Is the **`POSTED` status write** intentionally outside the chain-close ACID unit and after the broker acknowledgement? *(§7.4 ordering implies yes, but the consequence — a crash leaving a closed entry with a stale `CHAIN_PENDING` projection — is unstated, and is tolerable only because §4.3 makes status a rebuildable projection.)* | Chain-closer transaction boundary | ⚪ **Open** — **reconciled in §7.3 (D2): SPEC §7.4 already specifies this ordering, so A11 is spec-conformant rather than disputed.** The only remaining question is whether to accept the stale-projection window, which §4.3 makes tolerable |
| **OQ-14** | When the marketplace returns in Phase 2, how do marketplace entities relate to R9's strict 1:1 wallet/account mapping? | Phase 2 architecture | ⏸️ Deferred by H-4 |
| **OQ-15** | Should the **KYC admin action** be a first-class role, a permission, or part of an existing operations role? *(H-5 approves the authority model but not the role mechanism.)* | Authorization implementation | ⚪ **Open** |
| **OQ-16** | Is there a **dispute-review workflow and data model** for rejected reversals, or is manual review handled outside the system? *(H-2 mandates manual dispute review but does not specify whether that queue is in-system.)* | Dispute queue scope | ⚪ **Open** |

### 6.1 Decisions still at proposal stage

*These are recorded in the decision register as 🟠 **Proposed — not approved** or ✅ **Technical
default — unconfirmed**. They are inputs to the architecture spine, not settled facts. The
spine marks each one and names who must approve it.*

| Gap | Proposal | Status |
|---|---|---|
| **H-1** | Introduce explicitly typed **SYSTEM ledger accounts** as an explicit exception to the strict user-wallet/account 1:1 mapping. All funding must have balanced, auditable ledger entries. `opening_balance` is **derived, never seeded**. | 🟠 Proposed — not approved |
| **H-6** | Ledger entries remain immutable. Use durable, unique, immutable **chain-close records** as evidence of finality. Transaction status remains a rebuildable projection. *(Elaboration: remove `status` from `ledger_entries`.)* | 🟠 Proposed — not approved |
| **M-4** | **Tiered idempotency retention** separating key validity, duplicate-financial-operation prevention, failed/in-flight retry handling, and audit-record retention. **No unconditional nightly deletion.** Define safe behavior for retries after expiry. | 🟠 Proposed — not approved |
| **M-1** | **No partitioning now.** Document MySQL InnoDB partitioning and foreign-key limitations with a scoped claim only. Revisit after benchmarks without weakening sequence, FK, or chain invariants. | 🟠 Proposed — not approved |
| **C-3** | Configurable positive per-transaction limit required; numeric limit, daily cumulative caps, and regulatory ceilings left **open**; no real-money production transfers until approved. | 🟠 Proposed (values open) |
| **C-4** | `balance_minor` is the canonical column name; deprecate bare `balance`. | ✅ Technical default — unconfirmed |
| **H-3** | Lease TTL and Reaper interval as configuration, above typical Phase 3 latency and below the user-visible SLA. A 30s default is recommended. | ✅ Technical default — unconfirmed |
| **H-7** | `lock_epoch` is a per-transaction monotonic counter, incremented on each lock acquisition. | ✅ Technical default — unconfirmed |
| **H-8** | Separate canonical form and separate genesis for the audit chain. Never share the ledger genesis. | ✅ Technical default — unconfirmed |
| **M-5** | `request_fingerprint` = SHA-256 of the canonical request payload; a mismatch returns **409**. | ✅ Technical default — unconfirmed |
| **M-8** | Currency represented as `CHAR(3)` ISO-4217, default `'BDT'`, with a CHECK constraint. | ✅ Technical default — unconfirmed |
| **M-6** | 7-year cold-archive mechanism deferred. No deletion before legal review. | ⏸️ Deferred |
| **M-7** | Sweeper TTL vs `OLDEST_CHAIN_PENDING_AGE` margin. | ❌ **Withdrawn** — false positive |

### 6.2 Assumptions

- **[ASSUMPTION]** The specification's §7.2 debit was a drafting artifact of the escrow concept
  rather than an intentional two-phase money movement — hence C-1 replaces it with a reservation.
  *(Source: C-1's Option A3 and its 9 mandatory conditions.)*
- **[ASSUMPTION]** The specification's references to "credit/risk tier" and "marketplace listing"
  visibility describe Phase 2 surface rather than a Phase 1 requirement — hence H-4's deferral
  leaves them out of scope rather than contradicting them.
- **[ASSUMPTION]** The 500 TPS sustained target sits at the top of the 200–500 band, which implies
  close commits ≤ 2ms — a row absent from the specification's own TPS table.
- **[ASSUMPTION]** `currency` is fixed to BDT for the current version, so the ISO-4217 `CHAR(3)`
  representation is forward-compatible metadata rather than an active multi-currency capability.
- **[ASSUMPTION]** The Laravel 13 / React 19 starter kit is the implementation substrate; it is
  currently a foundation with no settlement code. No domain schema exists yet — the database
  reports zero tables.

---

## 7. Reconciliation of the architecture spine against the specification

*The architecture spine's §5.2 and §5.3 listed eleven open items. This section reconciles the four
the user named against the authoritative specification and the decision register, and states one
recommended financially-safe resolution for each. **Nothing here promotes a decision's status.** The
spine is a companion, not an approval.*

*Method: each disputed decision was read against `P2P_SPECIFICATION.md` and
`grill-me-decision-register.md` before a recommendation was formed. Where the specification
contradicts itself, that contradiction is named rather than resolved by preference.*

### 7.1 The recommendation summary

| # | Disputed decision | Recommended resolution | Financially safe? | Blocks Epic 1? | Status after this section |
|---|---|---|---|---|---|
| **D1** | **H-6** — `ledger_entries.status` column | **Remove `status` from `ledger_entries`.** Finality is proven by `chain_close_records`; the sweeper selects on *absence of a close record* instead. | **Yes** — stronger than the alternative | **No.** The table shape is settled either way; the sweeper is Epic 3. | 🟠 **Proposed (not approved)** — unchanged |
| **D2** | **A11** — `POSTED` write boundary | **Keep the `POSTED` write outside the chain-close ACID unit, after the ACK**, exactly as SPEC §7.4 specifies. | **Yes** — matches the spec verbatim | **No.** The chain-close table and head singleton are Epic 1; the closer is Epic 3. | 🟡 **Provisional**, and now **spec-conformant** — no longer disputed |
| **D3** | **A7** — reversal transaction modelling | **Keep the reversal as its own `transactions` row with `reversals.original_transaction_id` UNIQUE.** | **Yes** — Invariant 1 needs no exception clause | **No.** `reversals` is Epic 4. | ⚙️ **Architecture default**, still needs confirmation |
| **D4** | **A8** — audit-outbox ordering | **Keep the outbox pattern: intent atomic with the money, chain append asynchronous.** | **Yes, with one caveat** — it protects availability, and the residual window is bounded and monitored | **No.** `audit_outbox` is Epic 5; the *table* may be created in Epic 1. | ⚙️ **Architecture default**, still needs confirmation |

**None of D1–D4 blocks Epic 1.** The recommendation is that Epic 1 proceeds. D1 and D2 are recorded
as **decisions to confirm before Epic 3**, D3 before Epic 4, D4 before Epic 5 — but the underlying
tables and constraints can be built now under either outcome of each dispute.

### 7.2 D1 — H-6, ledger status immutability

**The dispute.** SPEC §3.1 lists `status` on the Ledger Entry entity. SPEC §7.3 inserts entries with
`status = 'CHAIN_PENDING'`. SPEC §9.4's sweeper selects `WHERE status = 'CHAIN_PENDING'`. But SPEC
§4.2 says ledger entries are *"**never updated and never deleted**"* and corrections happen *"only by
appending compensating entries"*, and SPEC §14.1 requires that no `UPDATE` or `DELETE` exists
against `ledger_entries`. SPEC §5.1 also lists `status` **inside the hashed body**.

**The specification contradicts itself, and the contradiction is arithmetic, not editorial.**

- If `status` is stored on the entry **and** is in the hashed body, then changing `status` from
  `CHAIN_PENDING` to closed **changes the entry's hash**. That is either a rewrite of history —
  forbidden by §4.2 — or a broken chain. There is no third option.
- If `status` is stored on the entry **and** is *not* in the hashed body, then §5.1 is violated and
  the column is an unverified mutable field on an append-only table — precisely what R10 forbids
  for `transactions.status`.
- If `status` is stored on the entry and is updated by the closer, then §4.2 and §14.1's
  "no `UPDATE` statement exists against `ledger_entries`" are both violated, and §7.4's closer
  transaction would need a third write.

**Recommended resolution: remove `status` from `ledger_entries` (H-6's Option B, as the spine
proposes).** This is not a preference; it is the only shape that satisfies §4.2, §5.1, §14.1 and
§9.2 simultaneously. The spec's `status` references in §3.1, §7.3 and §9.4 are read as
**describing intent** ("this entry is awaiting close") rather than prescribing a stored column,
because §4.2 and §14.1 are the more specific and more safety-critical statements.

**The sweeper adapts, and does not lose its function.** SPEC §9.4's sweeper selects entries whose
`status = 'CHAIN_PENDING'` and older than 30s. Without the column, the equivalent query is:

```sql
SELECT e.*
  FROM ledger_entries e
  LEFT JOIN chain_close_records c ON c.entry_id = e.entry_id
 WHERE c.entry_id IS NULL            -- not yet chain-closed
   AND e.created_at < now() - INTERVAL 30 SECOND;
```

An entry with no close record is, by definition, not yet closed. The sweeper's function and its
30s TTL are preserved exactly; only the *predicate* changes from "a column says so" to "a record is
absent". This is **more** robust: the predicate cannot disagree with reality, because it *is*
reality. H-6's own words — *"durable, unique, immutable chain-close records as evidence of
finality"* — describe exactly this.

**Financially safe? Yes — strictly safer.** The recommendation removes the only path by which an
entry's hash could change after insertion. It makes the append-only guarantee hold at the *data*
layer (A5's privilege revocation and triggers) rather than by convention, and it removes a mutable
money-adjacent field from the tamper-evident structure.

**Blocks Epic 1? No.** The `ledger_entries` table shape is settled under either answer: with the
column it is `status ENUM(...)`; without it, nothing. The **absence** of a column is the cheaper
error to reverse later than the presence of one, because a later migration can add a nullable
column but adding a column that was supposed to be in the hashed body changes every historical
hash. **However — one caveat that does block a sub-ticket:** the hashed-body field list (§5.1)
cannot be finalized until D1 is confirmed, because `status`'s presence or absence changes the hash
input. See §7.6.

### 7.3 D2 — A11, chain-finality and the `POSTED` lifecycle

**The dispute.** The spine's A11 puts the `UPDATE transactions SET status = 'POSTED'` **outside**
the chain-close ACID unit and **after** the broker ACK, accepting a window in which a crash leaves
a chain-closed entry whose transaction still reads `CHAIN_PENDING`.

**This dispute dissolves on reading the spec.** SPEC §7.4's chain-close pseudocode is:

```
BEGIN;
  INSERT INTO chain_close ... ;
  UPDATE chain_head ... ;
COMMIT;

ACK to broker          ← strictly AFTER commit

UPDATE transactions SET status = 'POSTED'
```

The spec already places the `POSTED` write **after** the ACK and **outside** the transaction. **A11
is SPEC §7.4 verbatim, not a deviation from it.** The spine's §5.2 flag on A11 was raised because
OQ-13 was framed as an open question — but the specification had already answered it. Recording
this correctly matters: A11 should not consume a user approval slot for a question the spec settled.

**The stale-projection window is acceptable, and the spec says why.** SPEC §6.1 defines `POSTED` as
*"Chain closed; transfer final and provable"*. A transaction whose entries are chain-closed but
whose projection still says `CHAIN_PENDING` **has** had its chain closed — the projection is simply
stale. SPEC §4.3 makes `transactions.status` a rebuildable projection and says *"the ledger wins"*.
SPEC §13's failure-mode table lists no failure mode for this window precisely because it is not
money-impacting: the money moved, the chain closed, the hash is in the head — only a cached label
lags.

**The opposite choice is worse, not safer.** Writing `POSTED` inside the chain-close transaction
would add a third row write inside the hottest, most latency-constrained transaction in the system
(SPEC §12.3 requires close commits under 1ms). It would buy nothing, because the projection is
rebuildable either way, and it would make the closer's transaction wider for no correctness gain.
**The financially safe direction is the spec's own: keep the money-moving transaction minimal, and
let reconciliation repair the projection.**

**One consequence must be recorded, because it changes Epic 1's scope.** If `POSTED` is written
outside the transaction, then something must eventually correct a stale projection. SPEC §4.3
assigns that job to the reconciliation engine. **That means a `CHAIN_PENDING` transaction whose
entries are already chain-closed must be detectable without reading `transactions.status`** — the
reconciler must derive chain-closedness from `chain_close_records` (§10.2 Invariant 2 territory).
This is a **load-bearing constraint on Epic 1's `chain_close_records` table**: it must be queryable
by `entry_id` and by `transaction_id`, and it must be the sole evidence of finality.

**Financially safe? Yes — it is the specification's own design, and it preserves the ledger as the
sole authority.**

**Blocks Epic 1? No.** The chain-close record table and the chain-head singleton are Epic 1
deliverables; the closer itself is Epic 3. A11 changes **what the table must support**, and that is
recorded in the Epic 1 tickets.

### 7.4 D3 — A7, reversal transaction modelling

**The dispute.** Whether the compensating entries of a reversal are written under the **original**
`transaction_id` or under a **new** transaction row of their own.

**The specification settles this by implication, in §6.4 and §10.2 together.** SPEC §6.4 says a
reversal *"is implemented by appending a new compensating DEBIT/CREDIT pair with new hashes"* and
that *"the original entries are never modified or removed"*. SPEC §10.2's Invariant 1 states that
for **every** `transaction_id`, `SUM(DEBIT) = SUM(CREDIT)` **with exactly one of each**.

If the compensating pair were written under the original `transaction_id`, that transaction would
hold **two DEBITs and two CREDITs** — and Invariant 1 would report a violation for a perfectly
correct reversal. The specification therefore *cannot* intend the original id to be reused: doing
so would make the specification's own nightly invariant fail on every successful reversal. A7's
resolution is the one that keeps Invariant 1 true.

**Recommended resolution: the reversal owns its own `transactions` row.** The uniqueness of
"one reversal per original transaction" is then carried by a `UNIQUE` constraint on
`reversals.original_transaction_id` — a **database error**, not a failed application check, which
is what H-2's "enforced at the data layer" requires.

**A second consequence worth surfacing, because the spine did not state it plainly.** SPEC §6.1
says `REVERSED` is a *state*, reached by `POSTED ──▶ REVERSED`, and SPEC §6.2 shows
`POSTED ──▶ REVERSED [compensating entry pair appended]`. But if the reversal is its own
transaction, then the reversal transaction *itself* starts at `INITIATED` and ends at `POSTED` —
it is not born `REVERSED`. The original transaction is the one that becomes `REVERSED`. So the
spec's state machine holds if and only if `REVERSED` is applied to **the original transaction**,
and the reversal's own transaction runs the ordinary `INITIATED → … → POSTED` path. This is
consistent, and it means Epic 4 needs no special-cased state machine — but it must be recorded,
because "the reversal transaction's status" and "the reversed transaction's status" are two
different things and a naive implementation would conflate them.

**Financially safe? Yes.** It is the only shape under which the specification's own Invariant 1
holds on a reversed transaction, and it makes double-reversal structurally impossible.

**Blocks Epic 1? No.** `reversals` is Epic 4. Nothing in Epic 1 depends on it.

### 7.5 D4 — A8, audit-outbox ordering

**The dispute.** Whether the audit write joins the ledger transaction or follows it.

**The specification does not answer this one — it only constrains it.** SPEC §11.5 requires the
Audit Service to maintain a **separate hash chain**, so that compromising one does not grant the
ability to erase footprints in the other. SPEC §14.7/AC14 then requires that *"compromise does not
permit undetected Audit Service tampering"*. SPEC §12.5 (consistency) and §12.1 (capacity) require
the money path to sustain 500 TPS with p99 < 200ms. A synchronous dependency on an audit sink
would put the audit chain's availability inside the money path's latency budget.

**Recommended resolution: keep the spine's outbox pattern.** The same ACID transaction that moves
money inserts an `audit_outbox` row; a worker drains it into the immutable `audit_log` chain.
Two properties make this the safe choice:

1. **The audit *intent* is atomic with the money.** An attacker who could suppress the ledger
   write would also have to suppress the audit intent — which requires compromising the same
   database write, not merely the audit chain. AC14's "no undetected tampering" holds.
2. **The ledger never blocks on the audit sink.** An audit-component outage cannot freeze
   transfers. Availability of money movement is preserved.

**The caveat, stated rather than buried.** Between the ledger commit and the outbox drain, the
audit chain does not yet contain the record. The window is bounded by the worker's poll interval
and the backlog depth is a monitored metric. This is a **deliberate trade**: immediate audit
presence is preferred over money-path availability, and the specification's capacity and latency
requirements support that ordering. It is still a trade the user should confirm rather than have
inherited silently.

**A second caveat that the spine did not state, and that matters for Epic 1.** The outbox row is
written in the same transaction as the money. If `audit_outbox` were created with a foreign key to
a table the money path also writes, a lock-order inversion could deadlock the hot path. The
outbox row must therefore have **no foreign key to the money path's hot tables** — it carries
scalar identifiers only. This is an Epic 1 constraint on the `audit_outbox` table.

**Financially safe? Yes, provided the caveat is accepted and the backlog is monitored.** The
alternative — audit writes inline — converts an observability dependency into a money-availability
dependency, which is a worse failure mode for a financial system.

**Blocks Epic 1? No.** The `audit_outbox` *table* may be created in Epic 1 (it is part of the
ledger-core schema and its "no FK to hot tables" constraint is an Epic 1 property), but the drain
worker is Epic 5 and the audit chain is Epic 5.

### 7.6 What the reconciliation changes for Epic 1

**D1 is confirmed? It still blocks one thing: the hashed-body field list.** SPEC §5.1 fixes the set
of hashed fields. If `status` is in the hashed body, the field list cannot be finalised until D1 is
answered. **But this does not block Epic 1's ticket sequence**, because the hash *implementation*
is blocked anyway by **M-2 (canonicalization, ⚪ BLOCKED)** — the exact byte-level rules and
cross-language SHA-256 test vectors are not specified, so no hashing code can be written yet
regardless. Epic 1 can build and test every table, constraint, trigger, model, and sequence
allocator, and can build the **hashing module's interface** with its implementation deferred.

This is the single most important scheduling fact about Epic 1:

> **M-2 blocks the hash implementation, not the ledger.** Everything else in Epic 1 — schema,
> constraints, triggers, immutability enforcement, account ownership, sequence allocation,
> chain-head singleton, chain-close records — is buildable and testable now.

**D2 changes what `chain_close_records` must support.** Because `POSTED` is written outside the
chain-close transaction, finality is evidenced *only* by the close record. Epic 1's ticket for
that table must therefore give it: a `UNIQUE (transaction_id, entry_id)` constraint (SPEC §9.2),
queryability by `entry_id` alone (the sweeper's predicate, §7.2), a monotonic `chain_position`, and
append-only protection identical to `ledger_entries`.

**D3 has no Epic 1 effect.** `reversals` is Epic 4. Recorded here so the reversal transaction's
distinct `status` is not conflated with the original's.

**D4 constrains one Epic 1 table.** `audit_outbox` carries no foreign key to hot tables.

### 7.7 What this section does not do

- **It does not approve anything.** H-6 remains 🟠 **Proposed (not approved)**. A7 and A8 remain ⚙️
  **Architecture defaults requiring confirmation**. A11 is reclassified from "disputed" to
  "spec-conformant", which is a *finding about the spec*, not an approval of the spine.
- **It does not override the specification.** Where the spec contradicts itself (D1), the
  contradiction is documented and the resolution is argued from the spec's own stronger statements.
  `P2P_SPECIFICATION.md` is **unmodified**. If the user disagrees with D1's resolution, the
  specification stands and Epic 1's hashed-body field list follows the spec's §3.1 shape.
- **It does not close OQ-7, OQ-6, OQ-1–OQ-4, or any other open question.** M-2 and M-3 remain
  open and block the hash implementation and the chain bootstrap respectively.

---

## 8. Implementation epics

*Written thin with `refined: false`. Each carries Description, Outcome, Boundaries, and Done-when
as far as it is known. **Requirements are not yet cut into epics** — Sofia does that during
epic refinement, once the architecture spine exists and the epic's own `R<n>` lines are settled.
Until then, the initiative's Requirements (R1–R30) and Acceptance Criteria (AC1–AC18) are the
spec of record.*

*The epic order below is the build order. Prerequisites are noted because each epic depends on
the contract its predecessor fixes.*

### Epic 1 — Ledger core: accounts, wallets, entries, and the hash chain

- **Status:** 🟢 **Refined** on 2026-10-26 — `refined: true`. The epic's own Requirements are cut
  from the parent in `tickets/epic-ledger-core/epic-ledger-core.md` (R1–R11), and its stories are
  planned in `tickets/epic-ledger-core/tickets/tickets.toml`. The spine is adopted as a companion.
- **Description:** The authoritative money substrate. Ledger accounts mapped 1:1 to wallets,
  wallets with posted and reserved balances in integer Poisha, immutable hash-chained double-entry
  ledger entries with per-account sequence numbers, the chain-head singleton, and the chain-close
  record.
- **Outcome:** A wallet's posted balance is provably reconstructible from its account's entries,
  and any alteration, deletion, or reordering of an entry is detectable.
- **Boundaries:** Covers R3, R4, R5–R10, and the ledger portion of R23. Does **not** cover
  transfer execution, reversal, or the reconciliation engine itself.
- **Done when:** AC1, AC2, AC3, AC4, AC13 hold. A wallet can be funded and its balance
  reconstructed from entries alone. The chain verifier passes on a clean dataset.
- **Prerequisites:** none — this is the first epic.
- **Carries:** H-1 (SYSTEM accounts, 🟠 proposed) must be settled before funding can be modelled
  honestly. M-2 (canonicalization, ⚪ blocked) blocks the hash implementation but **not** the table
  design. M-3 (genesis literal, ⚪ open) blocks chain bootstrap.
- **Reconciled:** D1–D4 in §7. **None of them blocks this epic.** D1 and D2 shape the
  `ledger_entries` and `chain_close_records` tables; D4 shapes `audit_outbox`.

### Epic 2 — Identity, KYC, and authorization boundary

- **Description:** Registration, short-lived JWT issuance and validation, the three-state KYC
  machine with admin-only transitions and recorded reasons, and the six-step authorization
  boundary that gates every ledger write path.
- **Outcome:** A non-`VERIFIED` user, a user attempting to spend another user's wallet, and an
  unauthenticated caller are all rejected before any ledger path is touched, and each rejection
  is audited.
- **Boundaries:** Covers R1, R2, R12, R28, and the KYC and authorization parts of R25. Does
  **not** cover balance mutation.
- **Done when:** AC15, AC16 hold. A peer cannot read any balance other than their own via any
  API path.
- **Prerequisites:** Epic 1 (authorization resolves wallet ownership to the ledger account).
- **Open:** OQ-15 — is the KYC admin a first-class role, a permission, or part of an existing
  operations role?

### Epic 3 — Transfer execution: idempotency, reservation, atomic post, and recovery

- **Description:** The four-phase transfer flow — authorize, reserve, post, chain-close — with
  user-scoped idempotency, a durable Reservation record, conditional balance updates, the
  per-transaction `lock_epoch` fencing token, the Reaper, and the Chain Recovery Sweeper.
- **Outcome:** A transfer either moves money exactly once or not at all; retries and concurrent
  duplicates cannot produce a second money movement; a crashed worker cannot post a stale
  write.
- **Boundaries:** Covers R11, R13, R14, R15, R16, R26, R27, and the reservation part of R23.
- **Done when:** AC5, AC6, AC7, AC8 hold. A closer crash after commit causes no duplicate chain
  entries; a sweeper-recovered entry's hash is identical to the first closer's computation.
- **Prerequisites:** Epics 1 and 2.
- **Carries:** OQ-5 (lease TTL value), OQ-12 (sweeper vs page threshold), OQ-13 (`POSTED` write
  boundary), M-4 (idempotency retention, 🟠 proposed), M-5 (`request_fingerprint` / 409, ✅
  unconfirmed).

### Epic 4 — Reversal: one-shot terminal reversal under dedicated authority

- **Description:** Exactly one full-amount reversal per `POSTED` transaction, with a dedicated
  permission, a closed-set reason code, immutable audit of every attempt, a separate reversal
  lifecycle distinguishing posting from chain finality, and the insufficient-funds path that
  rejects to manual dispute review.
- **Outcome:** A wrongly-completed transfer can be undone without ever editing history, and no
  user wallet can be driven negative or silently reimbursed from a system account.
- **Boundaries:** Covers R17–R22. Does **not** cover the dispute-review queue implementation
  (OQ-16) or the reversal policy values (OQ-1, OQ-2, OQ-3).
- **Done when:** AC9, AC10, AC11 hold. A second reversal against the same transaction is
  impossible at the data layer.
- **Prerequisites:** Epics 1 and 3.
- **Open:** OQ-1, OQ-2, OQ-3 (policy values — block production execution only), OQ-8, OQ-9
  (schema and projection derivation), OQ-10 (audit ordering), OQ-16 (dispute queue scope).

### Epic 5 — Reconciliation, audit chain, and operational signals

- **Description:** The nightly four-invariant reconciliation engine with no silent auto-repair,
  the independent Audit Service hash chain with its own genesis and canonical form, security
  alerting on rejected attempts, and the `OLDEST_CHAIN_PENDING_AGE` paged metric.
- **Outcome:** Every financial guarantee in Epics 1–4 is independently verified every night, and
  tampering or drift is detected rather than assumed absent.
- **Boundaries:** Covers R23, R24, R25 and the operational half of R26. Does **not** cover cold
  archive (M-6, ⏸️ deferred) or partitioning (M-1, 🟠 proposed).
- **Done when:** AC12, AC13, AC14 hold. All four invariants pass on a clean dataset; injected
  drift is detected within one nightly cycle.
- **Prerequisites:** Epics 1, 3, and 4.
- **Open:** OQ-6 (audit genesis), OQ-11 (audit table vs schema), H-8 (separate canonical form and
  genesis, ✅ unconfirmed).

### Epic 6 — Production enablement gates and capacity validation

- **Description:** The configuration surfaces for the business limit, the reversal time window,
  the reversal fee policy, and the higher-value reversal approval threshold — plus the closer
  load test that establishes whether the 1,000 TPS burst target is physically reachable on the
  chosen database.
- **Outcome:** The system is demonstrably safe to enable for real money, or demonstrably is not.
- **Boundaries:** Covers R22, R29, R26, R27 and AC18. Does **not** cover new features.
- **Done when:** AC17 and AC18 hold. Production refusal is the default until each policy value is
  explicitly approved.
- **Prerequisites:** Epics 1–5.
- **Blocked on:** OQ-1, OQ-2, OQ-3, OQ-4 — **all require explicit user approval**. This epic
  cannot be completed by the team alone.

---

## 9. Handoff

- **Done:** the architecture spine was produced and **adopted as a companion of this ticket** (see
  the References line at the top). The four disputed decisions the user named were reconciled
  against the specification and the decision register in **§7**, each with one recommended
  financially-safe resolution, its Epic 1 blocking status, and its status preserved. **None of
  them blocks Epic 1.**
- **Next:** **Epic 1 is refined** — `tickets/epic-ledger-core/epic-ledger-core.md` carries its own
  R1–R11 cut from the parent, and `tickets/epic-ledger-core/tickets/tickets.toml` holds its
  stories in build order. The **first implementation ticket** is `story-ledger-schema-foundation`.
- **Then:** confirm the consolidated approval checklist in
  `tickets/epic-ledger-core/approval-checklist-ledger-core.md`. Items **G1–G4** are the spine
  choices that need sign-off; **B1–B3** are the decisions that must be settled before later
  epics. Neither set blocks starting Epic 1's first ticket.
- **Then:** refine Epics 2–5 in the same way, each citing the spine and the §7 reconciliation.
- **Not authorised by this ticket:** application code, migrations, environment changes, commits,
  pushes, or any production enablement. Epic 1's stories are planned but **not yet built**.

---

*Initiative ticket populated by the `bmad-agent-po` (Sofia) workflow from the three named
sources. No specification file, application code, database schema, environment file, or test
configuration was modified. No migrations, tests, commits, or pushes were performed.*
