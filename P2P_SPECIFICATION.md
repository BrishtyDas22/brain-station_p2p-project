# P2P Fund Transfer & Transaction Settlement System
## Product Requirements Document & Software Requirements Specification

**Version:** 1.0 (Post-architectural-review baseline)
**Status:** Design frozen — all 13 architectural decisions settled
**Currency:** BDT (minor unit: Poisha) — single currency, Phase 1

---

## Table of Contents

1. [Purpose & Scope](#1-purpose--scope)
2. [System Architecture](#2-system-architecture)
3. [Domain Model](#3-domain-model)
4. [The Ledger](#4-the-ledger)
5. [Hash Chain & Integrity](#5-hash-chain--integrity)
6. [Transaction State Machine](#6-transaction-state-machine)
7. [Transfer Execution Flow](#7-transfer-execution-flow)
8. [Idempotency](#8-idempotency)
9. [Chain Finality & Recovery](#9-chain-finality--recovery)
10. [Reconciliation](#10-reconciliation)
11. [Trust, Privacy & Security](#11-trust-privacy--security)
12. [Non-Functional Requirements](#12-non-functional-requirements)
13. [Failure Modes](#13-failure-modes)
14. [Acceptance Criteria](#14-acceptance-criteria)
15. [Glossary](#15-glossary)

---

## 1. Purpose & Scope

### 1.1 Purpose

A peer-to-peer fund transfer and settlement platform for a micro-finance context. Users discover counterparties, negotiate terms, and execute transfers. Every movement of money is recorded in an **append-only, cryptographically verifiable double-entry ledger** that can be independently audited and mathematically proven consistent.

### 1.2 The architectural thesis

This system is **P2P in discovery and negotiation; centralized in authoritative settlement.**

This is a deliberate decision, not a compromise forced by difficulty. A genuinely distributed ledger — one where every node holds and validates the full ledger state — is a blockchain, and it carries a multi-year implementation cost and a distributed consensus surface that this system's stated requirements do not justify. Instead:

- **Peers** find each other, negotiate, browse listings, and build terms.
- **The Transaction Service** is the sole authority that touches the ledger.
- **The ledger** is single, global, and provably correct.

What the user experiences is peer-to-peer. What guarantees the money is correct is one authoritative ledger with a hash chain and three reconciliation invariants.

### 1.3 In scope (Phase 1)

- User registration, authentication, KYC status tracking
- Wallet creation and balance management
- P2P marketplace: loan/transfer listings, browsing, negotiation
- Transfer execution with escrow-style fund locking
- Append-only double-entry ledger with SHA-256 hash chaining
- Asynchronous chain finality via a single-writer closer
- Idempotent transfer submission
- Ledger reconciliation and tamper detection
- Audit logging with security alerting

### 1.4 Out of scope (Phase 1)

- Multi-currency conversion (`currency` is stored as forward-compatible metadata only)
- Cross-border settlement
- Distributed ledger consensus between nodes
- Lending underwriting logic (marketplace facilitates; risk decisions are out of scope)
- Smart contracts or programmable money

---

## 2. System Architecture

### 2.1 Components

| Component | Responsibility | Scaling model |
|---|---|---|
| **User Service** | Registration, authentication, KYC status, profile publication | Horizontal |
| **Wallet Service** | Wallet lifecycle, balance reads, **balance mutations** | Horizontal (sharded by wallet ID) |
| **Transaction Service** | Transfer orchestration, state machine, authorization | Horizontal |
| **Ledger Service** | Entry append, chain head read, chain close | Horizontal for append; **single-writer for close** |
| **Chain Closer** | Consumes close events, advances global chain head | **Single instance — hard constraint** |
| **Reaper** | Reclaims expired `LOCKED` leases | Active-passive |
| **Chain Recovery Sweeper** | Re-enqueues entries stuck in `CHAIN_PENDING` | Horizontal, idempotent |
| **Reconciliation Engine** | Three-invariant nightly audit | Scheduled batch |
| **Audit Service** | Immutable event log, tamper/security alerting | Append-only sink |
| **Message Queue** | Chain-close event transport, event distribution | Partitioned by `account_id` |
| **Database** | System of record | Primary + replicas |

### 2.2 Critical architectural constraints

> ⚠️ **The Chain Closer is a singleton. Scaling it beyond one instance is a correctness incident, not a performance change.** Two closers produce two chain heads and fork the ledger irrecoverably. This is enforced in deployment configuration and documented in the operational runbook.

### 2.3 Trust boundaries

```
┌─────────────────────────────────────────────────────────────┐
│  PEER ZONE — untrusted                                      │
│  Peers see only: own balance, own statement, public profile │
│  Peers CANNOT: see others' balances, write to ledger        │
└──────────────────────┬──────────────────────────────────────┘
                       │  Short-lived JWT (HTTPS)
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  TRUSTED ZONE                                               │
│  Transaction Service: authn → KYC check → wallet ownership  │
│  → ledger write path → balance lock                         │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────────┐
        │  Ledger (append-only)            │
        │  + Global hash chain             │
        │  + Per-account sequences         │
        └──────────────────────────────────┘
```

**Every transfer instruction, regardless of origin, terminates at the Transaction Service.** There is no direct peer-to-peer write path into the ledger. This is enforced architecturally, not by convention.

---

## 3. Domain Model

### 3.1 Entities

**User**
```
id, handle (pseudonymized, public), auth_subject, kyc_status,
public_profile, created_at
```

**Wallet**
```
id, owner_user_id, currency (constant: BDT), balance_minor,
created_at, updated_at
UNIQUE(owner_user_id, currency)
```

> Balance is stored in **minor units (Poisha)** as an integer. Floating-point arithmetic is forbidden anywhere in the money path. One BDT = 100 Poisha.

**Transaction**
```
id, initiator_user_id, counterparty_user_id, payer_wallet_id,
payee_wallet_id, amount_minor, currency, status,
idempotency_key, lock_epoch, locked_at, chain_pending_at,
posted_at, failure_reason, created_at, updated_at
```

> ⚠️ **`status` is a derived projection, not authoritative.** See §4.3.

**Ledger Entry**
```
entry_id, transaction_id, account_id, direction (DEBIT|CREDIT),
amount_minor, currency, status, account_sequence_number,
prev_hash, entry_hash, created_at
```

**Ledger Chain Head**
```
chain_id (singleton), current_hash, last_entry_id, last_sequence, updated_at
```

**Idempotency Record**
```
user_id, idempotency_key, transaction_id, request_fingerprint,
created_at, expires_at
UNIQUE(user_id, idempotency_key)
```

**Chain Close Record**
```
transaction_id, entry_id, chain_position, closed_at
UNIQUE(transaction_id, entry_id)   ← guarantees idempotent closing
```

**Audit Log**
```
id, actor, action, target, outcome, metadata, prev_hash, entry_hash, created_at
```

> The Audit Service maintains its **own independent hash chain**, separate from the ledger chain. Tampering with one does not protect an attacker from the other.

---

## 4. The Ledger

### 4.1 Double-entry invariant

Every transfer produces exactly two entries of equal magnitude, opposite direction:

```
Transfer of 500 BDT from Alice to Bob:

  DEBIT   Alice's account    50000 Poisha
  CREDIT  Bob's account      50000 Poisha
  ─────────────────────────────────────────────
  Sum                                  0
```

**Sum(DEBIT) = Sum(CREDIT) = 0 for every `transaction_id`.** This is verified nightly (§10).

### 4.2 Append-only

Ledger entries are **never updated and never deleted.** No `UPDATE` or `DELETE` statement exists against `ledger_entries` in the application. Corrections are made only by appending *compensating* entries (§6.4).

### 4.3 The ledger is the sole authority

> **This is the most important principle in the system.**

A transaction is `POSTED` **if and only if** exactly one DEBIT and one CREDIT entry exist for its `transaction_id`, both correctly chained, summing to zero.

The `transactions.status` column is a **rebuildable projection** — a cache for query performance. It can be reconstructed from ledger entries at any time, and the reconciliation engine (§10) does exactly that, diffing the cache against the derived truth.

If `transactions.status` and the ledger-derived state ever disagree, **the ledger wins.** Any other arrangement creates two sources of truth that can silently diverge, and a ledger whose correctness depends on a mutable status column is not a ledger.

### 4.4 Per-account sequence numbers

Every entry carries a monotonic `account_sequence_number`, unique and gapless per `account_id`, assigned **inside the posting transaction**.

This serves three purposes:
1. **Balance reconstruction** — replaying an account's entries in sequence order reproduces its balance exactly (§10.3).
2. **Chain-order alignment** — the Chain Closer buffers per-account to guarantee chain order matches sequence order (§9.3).
3. **Contention isolation** — the sequence counter contends only between transfers touching the *same account*, never globally.

---

## 5. Hash Chain & Integrity

### 5.1 The chained body

Each entry's hash is computed over a canonical JSON serialization of exactly these fields:

```
entry_id, transaction_id, account_id, direction,
amount_minor, currency, status, created_at
```

### 5.2 What is deliberately excluded

| Field | Why excluded |
|---|---|
| `balance_after` | Not independently verifiable — nobody can recompute it under concurrency. Kept as a non-authoritative column for display and debugging. **Storing an unverifiable assertion inside a tamper-evident structure makes the structure's guarantee illusory.** |
| `account_sequence_number` | Ordering metadata, verifiable separately via reconstruction (§10.3) |
| `prev_hash`, `entry_hash` | Chain linkage fields themselves |

> **Note on `balance_after`:** removing it from the hashed body was a deliberate amendment during design review. Its presence would mean the chain attested to a number that no verifier could check — a hash that always verifies proves nothing.

### 5.3 Computation

```
entry_hash = SHA256( canonical_json(chained_body) || prev_hash )
```

`prev_hash` for the first entry is a genesis constant.

### 5.4 Chain properties

- **Global** — one linear chain across all accounts, providing total ordering for reconciliation
- **Total order** — every entry has exactly one position
- **Tamper-evident** — altering any hashed field breaks that entry's hash and every subsequent link
- **Append-only** — verified by the continuity invariant (§10.2)

### 5.5 Tamper detection depth

```
Chain breaks. Which entry was altered?
                          │
        ┌─────────────────┴──────────────────┐
        ▼                                    ▼
  Hash mismatch                      prev_hash mismatch
  (field changed)                    (entry removed)
        │                                    │
        └──────────────┬─────────────────────┘
                       ▼
              Walk backward from head,
            comparing each entry's stored
              prev_hash to its predecessor's
                  entry_hash
                       │
                       ▼
              First mismatch = point of tampering
```

Both an altered field and a deleted entry are caught. A **reordered** entry is also caught: its `prev_hash` no longer matches the entry now preceding it.

---

## 6. Transaction State Machine

### 6.1 States

| State | Meaning | Money location |
|---|---|---|
| `INITIATED` | Request received, not yet authorized | Unchanged |
| `PENDING` | Authorized, awaiting balance lock | Unchanged |
| `LOCKED` | Funds escrowed from payer | **In flight** |
| `CHAIN_PENDING` | Entries committed, chain not yet advanced | Moved, not provable |
| `POSTED` | Chain closed; transfer final and provable | Final |
| `FAILED` | Terminal failure; any lock released | Unchanged |
| `REVERSED` | Compensating entries appended | Final (net effect reversed) |

### 6.2 Transitions

```
INITIATED ──▶ PENDING ──▶ LOCKED ──▶ CHAIN_PENDING ──▶ POSTED
                │           │              │
                │           │              └──▶ (sweeper re-enqueue)
                │           │
                ▼           ▼
              FAILED ◀─────┘   [lease expiry → reaper → PENDING]

POSTED ──▶ REVERSED   [compensating entry pair appended]
```

**Legality is enforced in the database** via CHECK constraints and triggers — not only in application code. Application-level enforcement can be bypassed by any other writer: a migration, an operator script, a future service. Data-level guarantees must live at the data layer.

### 6.3 The escrow window

`LOCKED` is the state where **money has left the payer but not reached the payee** — it is owned by nobody. Two mechanisms protect this:

**Lease with expiry.** `locked_at` timestamp plus a TTL. If the settling worker dies, the Reaper reclaims the transaction and returns it to `PENDING`.

**Fencing token.** A monotonic `lock_epoch` is stamped on every lock acquisition and reclamation.

Every write in the posting transaction is guarded:
```sql
UPDATE transactions
SET status = 'CHAIN_PENDING', lock_epoch = ?, updated_at = ?
WHERE id = ?
  AND status = 'LOCKED'
  AND lock_epoch = ?;
```
Zero rows affected ⇒ this worker has lost the lock. **Roll back immediately.**

### 6.4 Reversal semantics

`REVERSED` is reachable **only from `POSTED`**, and is implemented by appending a **new compensating DEBIT/CREDIT pair** with new hashes. The original entries are never modified or removed.

```
POSTED transfer Alice → Bob, 500 BDT

Reversal appends:
  DEBIT   Bob's account    50000    (money returns)
  CREDIT  Alice's account  50000

Original entries remain in the chain, untouched and still verifiable.
```

> **A "reversal" that modified the original entries would violate append-only and destroy auditability.** The ledger records what happened, including reversals. It is a history, not a current-state view.

---

## 7. Transfer Execution Flow

### 7.1 Phase 1 — Authorization (Transaction Service)

```
1. Validate JWT (short-lived, signed)
2. Check kyc_status = 'KYC_VERIFIED'
3. Verify initiator owns payer_wallet_id
4. Verify counterparty owns payee_wallet_id
5. Check idempotency key (§8)
6. Validate amount_minor > 0 and amount <= business limit
```

Any failure at steps 1–4 is logged to the Audit Service with a **security alert** and rejected **before the ledger path is touched**.

### 7.2 Phase 2 — Lock (Transaction Service)

```sql
BEGIN;
  SELECT * FROM transactions WHERE id = ? FOR UPDATE;
  -- verify status = 'LOCKED' AND lock_epoch = :expected_epoch

  UPDATE wallets
     SET balance = balance - :amount, updated_at = now()
   WHERE id = :payer_wallet AND balance >= :amount;
  -- 0 rows → insufficient funds → ROLLBACK

  -- store escrow debit as provisional; formal entries written in Phase 3
COMMIT;
```

### 7.3 Phase 3 — Post (single ACID transaction)

Five ordered writes. **Any failure rolls back everything.**

```
BEGIN;
  1. Conditional debit:    UPDATE wallets SET balance = balance - X
                           WHERE id = payer AND balance >= X
                           → 0 rows ⇒ ROLLBACK (insufficient funds)

  2. Conditional credit:   UPDATE wallets SET balance = balance + X
                           WHERE id = payee

  3. Assign per-account sequence numbers (payer, payee)

  4. INSERT ledger entry    DEBIT   payer   (status = CHAIN_PENDING)
     INSERT ledger entry    CREDIT  payee   (status = CHAIN_PENDING)

  5. UPDATE transactions SET status = 'CHAIN_PENDING'
       WHERE id = ? AND status = 'LOCKED' AND lock_epoch = ?
       → 0 rows ⇒ ROLLBACK (lock lost)
COMMIT;

  Emit chain-close events to Message Queue
```

> **Step 1 is the anti-TOCTOU primitive.** The balance check and the deduction are a single indivisible operation. There is no window between reading the balance and writing it in which another transaction can intervene. Two concurrent transfers for the full balance cannot both succeed.

### 7.4 Phase 4 — Chain close (Chain Closer)

```
Receive event (account_id, entry_id)

Buffer per-account; process in account_sequence_number order
  → guarantees chain order ≡ sequence order

BEGIN;
  INSERT INTO chain_close (transaction_id, entry_id) ...
    → UNIQUE VIOLATION ⇒ already closed ⇒ ACK and continue

  prev_hash = chain_head.current_hash
  next_hash = SHA256(canonical_json(body) || prev_hash)

  UPDATE chain_head
     SET current_hash = next_hash, last_entry_id = ...
COMMIT;

ACK to broker          ← strictly AFTER commit

UPDATE transactions SET status = 'POSTED'
```

> **Commit-then-ack, always.** Acknowledging first means a crash loses the event and the transfer hangs in `CHAIN_PENDING` — making the sweeper a correctness dependency for *every* transaction. Committing first means a crash causes redelivery, which the `(transaction_id, entry_id)` unique constraint absorbs harmlessly.

### 7.5 Client-visible latency

| Moment | Client sees | SLA |
|---|---|---|
| Phase 3 commit | HTTP 200 `ACCEPTED` — funds moved, entries durable | p50 <50ms, p99 <200ms |
| Phase 4 commit | `POSTED` — final and cryptographically provable | p99 <1.5s |

> **"Money moved" and "money provable" are now different moments.** The API contract must be explicit about which one a 200 response represents. This document defines it as **`CHAIN_PENDING` — funds are irrevocably moved and durable, but not yet chain-final.** A reversal is impossible at that point; the chain will close.

---

## 8. Idempotency

### 8.1 Contract

Every transfer submission **must** carry a client-generated idempotency key.

| Property | Value |
|---|---|
| Scope | `(user_id, idempotency_key)` — composite unique |
| TTL | 24 hours |
| Replay response | Original transaction record and current status, verbatim |
| Failure handling | **Failures never clear the key** |

### 8.2 Replay semantics

| Original status | Replay returns |
|---|---|
| `INITIATED` / `PENDING` / `LOCKED` / `CHAIN_PENDING` | Same transaction, current status — client polls |
| `POSTED` | Same transaction, `POSTED` |
| `FAILED` | Same transaction, `FAILED`, with original `transaction_id` and failure reason |
| `REVERSED` | Same transaction, `REVERSED` |

> **Why failures do not clear the key:** automatic clearing means the idempotency table forgets the key was ever used, destroying the duplicate-detection evidence the Audit Service needs. It also risks a client accidentally creating a *second* transfer the instant a transient failure clears.

### 8.3 Client obligation

**A new logical attempt requires a new idempotency key.** Documented in the API contract. A client reusing one key across a multi-day outage will execute once per 24-hour window — this is by design, because the server cannot distinguish a retry from a new intent without client cooperation.

---

## 9. Chain Finality & Recovery

### 9.1 Why two-phase

Moving the global chain-head lock out of the posting transaction removes the wallet-row lock convoy from the hot path. The posting transaction touches only wallet rows and inserts entries — both shardable. The Chain Closer is a **single writer** that preserves total ordering while committing tiny, fast transactions.

**This does not weaken integrity — it relocates where integrity begins.** An unclosed entry is a *proposed* entry, not an authoritative one. `CHAIN_PENDING` means: money has moved and is durable, but is not yet cryptographically provable.

### 9.2 Idempotent closing

```sql
UNIQUE (transaction_id, entry_id)   -- in chain_close table
```

This constraint is what makes the closer safe under at-least-once delivery. A duplicate close attempt raises a unique violation, which the closer treats as **"already closed"** — logs an audit trace and acknowledges. This is not an error path; it is a normal, expected branch.

### 9.3 Ordering guarantee

The closer maintains an **in-memory per-account buffer** and advances entries strictly in `account_sequence_number` order. Therefore:

> **Chain order ≡ account_sequence_number order.**

This matters beyond tidiness. Without it, the chain order and the sequence order become two independent orderings that can disagree — producing discrepancies an auditor can discover but the system cannot explain. One ordering, always consistent.

### 9.4 Chain Recovery Sweeper

```
Every 30s:
  SELECT entries WHERE status = 'CHAIN_PENDING'
                    AND chain_pending_at < now() - 30s

  For each:
    Re-read the PERSISTED hashed fields from disk
    Re-enqueue for closing
```

> ⚠️ **The sweeper must read hashed fields from storage, never regenerate them in memory.** If it recomputes `created_at` or any hashed field, it produces a *different* hash for the same entry — silently forking the chain from that point. The old hash vanishes; the chain still verifies; nothing alerts. Read the truth or don't write.

**Tuning constraint:** sweeper TTL (30s) **must** exceed the closer's p99.9 processing time (<100ms). The current ratio provides a 300× margin. Monitor this ratio — closing it manufactures duplicate work by design.

### 9.5 Operational signal

> **`OLDEST_CHAIN_PENDING_AGE` is a first-class, paged metric.** An entry stuck in `CHAIN_PENDING` is money that moved and is not yet provable. Alert immediately if it exceeds the 1.5s finality SLA, page if it exceeds 30s.

---

## 10. Reconciliation

### 10.1 Schedule & scope

Nightly batch, full active dataset, partitioned by year. Reports three invariants. **Any violation is a tamper or corruption alert — there is no "expected drift" category.**

### 10.2 Invariant 1 — Transaction balance

```
For every transaction_id:
    SUM(amount WHERE direction = DEBIT) = SUM(amount WHERE direction = CREDIT)
    And exactly one of each
```

Detects partial postings — one entry committed without its counterpart. Given the atomicity rules in §7.3, this should be impossible; its presence indicates corruption, external tampering, or a bug.

### 10.3 Invariant 2 — Cryptographic continuity

```
Walk the chain from genesis:
    For each entry:
        entry.prev_hash == previous_entry.entry_hash
Final entry.entry_hash == chain_head.current_hash
```

Detects altered fields, deleted entries, reordering, and chain forking.

### 10.4 Invariant 3 — Balance reconstruction ⭐

```
For each account:
    Reconstruct = opening_balance
                  + SUM(CREDIT.amount WHERE sequence ≤ N)
                  - SUM(DEBIT.amount  WHERE sequence ≤ N)

    Assert reconstruct == wallet.balance
```

**This is the invariant with real teeth.** It catches tampering with balances, not just entry contents — the difference between validating a hash function and validating money.

> **This invariant is possible only because of the per-account sequence number.** Under concurrent conditional updates, entry insertion order does not equal balance-application order, so summing in `created_at` order cannot reproduce the balance. The sequence number makes reconstruction deterministic.

It also retroactively validates `balance_after`: if the stored value disagrees with the reconstructed value, the stored value was wrong — exactly the forensic capability lost when that field was removed from the hashed body.

**Caveat:** the wallet mutation and the entry insert occur in one ACID transaction (§7.3), so they **cannot** legitimately diverge. A drift finding is therefore never benign. It is a bug or an attack, full stop.

---

## 11. Trust, Privacy & Security

### 11.1 Authentication

- Short-lived JWTs; OAuth2 authorization flow
- KYC status (`KYC_VERIFIED`) is a **hard precondition** for any ledger write path

### 11.2 Authorization

Every instruction is validated for: valid token → KYC verified → **initiator owns payer wallet** → counterparty owns payee wallet → amount in bounds. **No direct peer write path exists.**

### 11.3 Data visibility

| Data | Who can see |
|---|---|
| Own balance and statement | The owner, only |
| Another user's balance | **Nobody** — zero peer-to-peer visibility |
| Counterparty identity | Pseudonymized handle, credit/risk tier |
| Marketplace listing | Handle, amount, terms, tier |

> **A peer can never read a counterparty's balance.** Not in listings, not in transfer flows, not via API. This is enforced at the service layer, not by client-side filtering.

### 11.4 Security alerting

Invalid, forged, or unauthorized debit attempts trigger **immediate audit logging with security alerts** — before reaching the ledger engine. Rejections are never silent; a system that quietly rejects attacks cannot detect them.

### 11.5 Audit chain independence

The Audit Service maintains a **separate hash chain** from the ledger chain. Compromise of the ledger does not grant an attacker the ability to erase their footprints in the audit log, and vice versa.

---

## 12. Non-Functional Requirements

### 12.1 Capacity

| Metric | Target |
|---|---|
| Sustained throughput | 200–500 TPS |
| Burst throughput | 1,000 TPS |
| Storage growth | 2 ledger entries per transfer + 1 close record |

### 12.2 Latency

| Operation | Target |
|---|---|
| API confirmation (at `CHAIN_PENDING`) | p50 <50ms, p99 <200ms |
| Chain finality (`CHAIN_PENDING` → `POSTED`) | p99 <1.5s |
| Chain close transaction | <1ms (must sustain ~1,000 TPS on a single writer) |

### 12.3 Capacity analysis

**The Chain Closer is the architectural ceiling.** Single-writer maximum TPS = 1 ÷ close-commit latency.

| If close commit latency | Max closer TPS |
|---|---|
| 1 ms | 1,000 |
| 5 ms | 200 |
| 10 ms | 100 |

**Implication:** the 1,000 TPS burst target requires close commits under 1ms. This is a hard, testable database requirement. **Load-test the closer before building the wallet path** — every other component's design is waiting on this number.

**Finality budget:** 1.5s p99 allows ~1,400ms for queue round-trip + close + status write. Comfortable, but monitor the queue's contribution separately.

### 12.4 Retention

- **Active:** all data within the retention window, fully indexed
- **Cold archive:** after 7 years (regulatory compliance)
- **Reconciliation partitioning:** by year — the nightly audit scans only the active partition. Without this, an O(n) full replay grows without bound and eventually times out.

### 12.5 Consistency

- **Strong** within the posting transaction (§7.3)
- **Bounded eventual** between `CHAIN_PENDING` and `POSTED` (<1.5s p99)
- **Ledger:** append-only, hash-chained, single source of truth

---

## 13. Failure Modes

| # | Failure | Detection | Response | Data impact |
|---|---|---|---|---|
| 1 | Crash before posting commit | Transaction rollback | None needed — nothing committed | **None** |
| 2 | Insufficient funds | Conditional update returns 0 rows | `FAILED` | **None** |
| 3 | Lock lease expires mid-flight | `lock_epoch` guard returns 0 rows | ROLLBACK; Reaper reclaims | **None** |
| 4 | Worker paused past lease (GC, partition) | Fencing token rejects write at commit | ROLLBACK | **None** |
| 5 | Closer crashes before commit | Chain head unchanged | Redelivery → reprocessed | **None** |
| 6 | Closer crashes after commit, before ack | Chain advanced; event redelivered | Unique violation → "already closed" → ACK | **None** |
| 7 | Entry stuck in `CHAIN_PENDING` | Sweeper at 30s; `OLDEST_CHAIN_PENDING_AGE` alert | Re-enqueue from persisted fields | None — already durable |
| 8 | Sweeper recomputes hash incorrectly | **Silent** — chain forks, still verifies | **Undetectable by design** | ⚠️ **Corruption** |
| 9 | Ledger entry altered externally | Invariant 2 failure | Page; investigate | Tamper detected |
| 10 | Balance drift vs. entries | Invariant 3 failure | Page; investigate | Money discrepancy |
| 11 | Chain Closer scaled to 2 instances | **Silent** — two heads | ⚠️ **Irrecoverable fork** | ⚠️ **Ledger split** |
| 12 | Unauthorized debit attempt | Security alert at auth boundary | Rejected pre-ledger; investigate | **None** |

### 13.1 The two silent failures

> **Failure 8 and Failure 11 are the ones that matter.** Every other failure in this table is loud, detected, and recoverable. These two are not.

**#8** is prevented entirely by the sweeper reading persisted fields rather than regenerating them. This is the single most fragile implementation detail in the system — one line of convenience code in the sweeper silently forks the chain, and the verifier will report the forked chain as valid.

**#11** is prevented only by deployment configuration. There is no runtime defence. Two closers means two chain heads, two valid-looking chains, and a fork that cannot be reconciled without operator intervention. **Add a startup assertion that the closer holds a leader lease before it processes anything.**

---

## 14. Acceptance Criteria

### 14.1 Ledger integrity

- [ ] Given any altered field in a hashed entry, chain verification fails at that entry and every subsequent link
- [ ] Given any deleted or reordered entry, chain verification fails at the divergence point
- [ ] Given a full ledger replay in chain order, the final hash equals `chain_head.current_hash`
- [ ] No `UPDATE` or `DELETE` statement exists against `ledger_entries` in application code

### 14.2 Atomicity & concurrency

- [ ] Two concurrent transfers each for the full wallet balance: **at most one succeeds**
- [ ] A fault injected between debit and credit insert produces **zero** ledger entries
- [ ] A fault injected after posting commit but before queue emit leaves the transaction `CHAIN_PENDING`, recoverable by sweeper
- [ ] A paused worker resuming with a stale `lock_epoch` has its write **rejected at commit**

### 14.3 Idempotency

- [ ] The same `(user_id, idempotency_key)` submitted 10× concurrently produces exactly **one** transfer
- [ ] Replay of a `FAILED` transaction returns the failure and does **not** clear the key
- [ ] Replay of an in-flight transaction returns current status without side effects

### 14.4 Chain finality

- [ ] Chain order matches `account_sequence_number` order for every account, verified over 24h of production traffic
- [ ] A closer crash after commit causes no duplicate chain entries
- [ ] The sweeper recovers any `CHAIN_PENDING` entry older than 30s
- [ ] Sweeper-processed entries have hashes identical to what the first closer computed

### 14.5 Reconciliation

- [ ] All three invariants pass on a clean dataset
- [ ] Injecting a balance drift is **detected** by Invariant 3 within one nightly cycle
- [ ] Injecting a field alteration is **detected** by Invariant 2 within one nightly cycle
- [ ] A deliberately removed entry is **detected** by Invariant 2 within one nightly cycle
- [ ] `transactions.status` is fully rebuildable from entries alone

### 14.6 Performance

- [ ] 500 TPS sustained for 1 hour with p99 <200ms API confirmation
- [ ] 1,000 TPS burst with p99 <1.5s chain finality
- [ ] Closer sustains required throughput with close commits <1ms
- [ ] Nightly reconciliation completes within its window at full data volume

### 14.7 Security & privacy

- [ ] A peer cannot read any balance other than their own (verified by attempting every API path)
- [ ] An unauthorized debit attempt is rejected before the ledger path and raises a security alert
- [ ] A non-`KYC_VERIFIED` user cannot initiate any transfer
- [ ] Ledger chain compromise does not permit undetected Audit Service tampering, and vice versa

---

## 15. Glossary

| Term | Meaning |
|---|---|
| **Append-only** | Records are never updated or deleted; corrections are new compensating entries |
| **Chain head** | Singleton record holding the hash of the most recently closed entry |
| **CHAIN_PENDING** | Entries committed and durable; chain not yet advanced. Money moved, not yet provable |
| **Chain Closer** | Singleton worker that advances the global chain head. **Scaling it breaks the ledger** |
| **Double-entry** | Every transfer produces one DEBIT and one equal CREDIT; sum is always zero |
| **Epoch (lock_epoch)** | Monotonic fencing token; rejects writes from workers whose lease was reclaimed |
| **Idempotency key** | Client-supplied unique token preventing duplicate transfer execution |
| **Projection** | Derived, rebuildable cache (`transactions.status`). Not authoritative |
| **Reaper** | Worker that reclaims `LOCKED` transactions past lease TTL |
| **Sweeper** | Worker that re-enqueues `CHAIN_PENDING` entries stuck past TTL |
| **TOCTOU** | Time-of-check-to-time-of-use race; eliminated here by conditional `UPDATE ... WHERE balance >= X` |
| **Two-phase chain close** | Entries commit first; chain advancement happens asynchronously |
| **Wallet ownership** | Verified at the Transaction Service before any ledger path is touched |

---

## Design Decision Record (summary)

| # | Decision | Rationale |
|---|---|---|
| 1 | Global hash chain, chain head in Ledger Service | Total ordering for reconciliation; per-account chains can't derive cross-account order |
| 2 | Single ACID txn; conditional `UPDATE` for balance | Eliminates TOCTOU window; no orphan entries |
| 3 | Lease + `lock_epoch` fencing; DB-enforced transitions | Survives worker death and GC pauses; data guarantees at the data layer |
| 4 | Ledger entries are sole authority; `status` is a projection | Eliminates dual sources of truth |
| 5 | `balance_after` excluded from hashed body | An unverifiable assertion inside a tamper-evident structure guarantees nothing |
| 6 | User-scoped idempotency, 24h TTL, no failure clearing | Preserves duplicate-detection evidence |
| 7 | Two-phase chain close via queue | Removes global lock convoy from wallet path; preserves ordering |
| 8 | Explicit `CHAIN_PENDING` state + sweeper | Makes "is this money provable?" answerable |
| 9 | Per-account sequence + 3 invariants | Enables true balance reconstruction — reconciles money, not just hashes |
| 10 | At-least-once, commit-then-ack, singleton closer | Broker-agnostic correctness; sweeper as safety net not critical path |
| 11 | Unique-constraint idempotency; no third lease | Hard DB guarantee beats time-based heuristic that a paused process defeats |
| 12 | 200–500/1,000 TPS targets | Locates the capacity cliff in the closer's single-writer design |
| 13 | P2P negotiation, centralized settlement | Real distributed ledger = blockchain; not justified by requirements |