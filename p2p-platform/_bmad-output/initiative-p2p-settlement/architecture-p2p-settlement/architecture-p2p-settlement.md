---
type: architecture
title: P2P Settlement — Architecture Spine and MySQL Data Model
status: draft
created: 2026-10-26
updated: 2026-10-26
sources:
  - ../initiative-p2p-settlement.md
  - ../../planning-artifacts/prds/prd-p2p-platform-2026-10-26/prd.md
  - ../../planning-artifacts/grill-me-decision-register.md
  - ../../../P2P_SPECIFICATION.md
companions: []
---

# Architecture Spine — P2P Settlement

> **Approval ceiling — read first.**
> This spine records **architecture decisions** made to make the implementation safe and
> consistent. Every decision carries an `A<n>` id and a status:
>
> - 🟡 **Provisional** — follows a user-approved (but not finally approved) requirement decision.
> - ⚙️ **Architecture default** — a technical choice this spine makes that the requirement layer
>   did not fix. It is **not a requirement approval**; it needs the user's or the team's sign-off.
> - ⚪ **Open** — genuinely unresolved. **Named, not invented.**
>
> **No requirement decision was promoted by this spine.** `P2P_SPECIFICATION.md` is unmodified.
> **No real-money production enablement is authorised.**

---

## 0. Environment verified

| Fact | Value | How verified |
|---|---|---|
| Framework | Laravel 13.38.0 (PHP 8.3) | `laravel-boost` application-info |
| Frontend | React 19.3.1 + Inertia 3.9.1 + Vite 8 | `laravel-boost` application-info |
| Database engine | **MySQL 8.0.46**, InnoDB | Direct server query |
| `sql_mode` | `ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION` | Direct server query |
| App DB user | `p2p_app@localhost` with `ALL PRIVILEGES ON p2p_platform_dev.*` — **trigger and constraint DDL permitted** | `SHOW GRANTS` |
| Current schema | **0 tables.** The dev database is empty; no settlement schema exists yet | `information_schema.tables` |
| Existing app code | Starter kit only — `User` model (Fortify, passkeys, 2FA), 5 migrations (users, cache, jobs, passkeys, 2FA columns). No settlement code | filesystem |

**Why this matters.** Two load-bearing facts follow from the environment, and they shape §2 and §5:

1. **MySQL 8.0.46 with `STRICT_TRANS_TABLES` rejects out-of-range and unsigned-overflow values at
   the storage layer.** This means the money columns' `UNSIGNED`/`BIGINT` types are not decorative —
   they are the first line of defence that makes a negative wallet physically unrepresentable.
2. **The app user has full privileges on the dev schema**, so the state-machine CHECK constraints
   and triggers that SPEC §6.2 demands ("legality is enforced in the database via CHECK
   constraints and triggers") can actually be created. This is not aspirational; it is available.

---

## 1. Spine — the decisions that keep separately built parts consistent

*Terse. Each decision states what is settled, what it binds, and what it deliberately leaves open.
These ids are cited by epics and stories.*

### A1 — Single MySQL schema, InnoDB, no partitioning *(A1)*

**Status:** ⚙️ Architecture default
**Binds:** every epic.

The whole system of record is one MySQL 8.0 InnoDB schema. Tables are organised by bounded
context, not by service — the specification's "services" (§2.1) are **logical boundaries inside
one schema**, not separate databases. Laravel serves all of them; a service boundary is enforced
in application code and by table ownership, not by a network hop.

**Why.** The specification requires a *single global hash chain* with a *singleton chain head*.
A global total order across all accounts cannot be maintained across independent databases without
a distributed consensus protocol — which the specification explicitly rejects (§1.2). Splitting the
schema would force the ledger into exactly the blockchain problem the design avoids.

**Deliberately open:** M-1 partitioning (🟠 proposed — defer pending benchmarks), M-6 cold archive
(⏸️ deferred pending legal review).

**Do not claim:** that partitioning inherently destroys sequence uniqueness in all architectures.
The scoped, accurate statement is: **MySQL InnoDB does not support foreign keys on partitioned
tables** (a documented engine limitation), so partitioning the `ledger_entries` table would
require removing its foreign keys — which would weaken the very guarantees this spine relies on.
Deferral is therefore the safe answer on the merits, not merely a convenience.

### A2 — Money is `BIGINT UNSIGNED` in integer minor units; no floats, ever *(A2)*

**Status:** ⚙️ Architecture default · **Binds:** Epics 1, 3, 4, 5.

Every monetary column is `BIGINT UNSIGNED`, stored in **Poisha** (1 BDT = 100 Poisha). No
`DECIMAL`, no `FLOAT`, no `DOUBLE` anywhere in the money path. `currency` is `CHAR(3)` ISO-4217,
default `'BDT'`, with a CHECK constraint (M-8, ✅ unconfirmed — this spine adopts the default and
flags it for confirmation).

**Why `UNSIGNED`.** Under `STRICT_TRANS_TABLES`, MySQL rejects an out-of-range value at write
time. An `UNSIGNED BIGINT` column therefore makes a negative balance **physically
unrepresentable**, not merely forbidden by application logic. That directly serves H-2's
requirement that no user wallet may ever become negative, and R4's
`0 <= reserved_minor <= balance_minor`.

**Ceiling.** `BIGINT UNSIGNED` holds 18,446,744,073,709,551,615 Poisha ≈ 184.4 billion BDT. At
1,000 TPS the ledger adds ~2 entries/second. This ceiling is not a practical constraint and needs
no monitoring.

**Deliberately open:** C-3 business limits (🟠 proposed, values open), M-8 currency representation
(✅ unconfirmed).

### A3 — One Ledger Account per Wallet, immutable, with the account id inside the hash *(A3)*

**Status:** 🟡 Provisional (follows C-2, Option B2) · **Binds:** Epics 1, 2, 3, 4, 5.

`wallets.account_id` is a `NOT NULL` foreign key to `ledger_accounts.id`. The mapping is created
once, in the same transaction that creates the wallet, and is **never updated**. There is no
`ON UPDATE CASCADE` and no `ON DELETE` action that would permit a re-point.

**Why the indirection is permanent.** `account_id` is inside the hashed body of every ledger entry
(SPEC §5.1). It cannot be renamed or re-pointed without invalidating every hash in the chain. The
schema is therefore designed as if the column name can never change — because it cannot.

**The 1:1 rule is enforced two ways, not one.** A `UNIQUE` constraint on
`wallets.account_id` makes "one account per wallet" impossible to violate. That is the constraint
that actually matters — C-2's risk is a second account pointing at the same wallet, and a unique
index is the only thing that makes that impossible at the data layer, no matter which writer
attempts it.

**SYSTEM accounts.** H-1 (🟠 **proposed, not approved**) introduces explicitly typed SYSTEM
accounts as an exception to the 1:1 mapping — a wallet may have no account, and an account may
have no wallet. **This spine designs for that exception** so that adopting H-1 later is a data
change, not a redesign:

- `ledger_accounts.owner_user_id` is **nullable**. `NULL` ⇒ SYSTEM account.
- `ledger_accounts.account_type` is `ENUM('USER','SYSTEM')`.
- `ledger_accounts.system_role` is nullable `ENUM('FUNDING','TREASURY')`, `NOT NULL` only when
  `account_type = 'SYSTEM'`.

**This is the one place where a not-yet-approved proposal shapes the schema**, and it is called
out here explicitly rather than buried: if H-1 is rejected, `owner_user_id` becomes `NOT NULL`,
`account_type` collapses to a constant, and the SYSTEM funding path disappears. No ledger entry,
hash, or invariant changes — so the cost of being wrong is a migration, not a rewrite.

**Deliberately open:** H-1 itself (🟠 proposed). OQ-14 (Phase 2 marketplace entities vs this 1:1
mapping — ⏸️ deferred by H-4).

### A4 — `transactions` is a projection table; the ledger is the truth *(A4)*

**Status:** 🟡 Provisional (follows SPEC §4.3 and R10) · **Binds:** Epics 1, 3, 4, 5.

`transactions.status` is a rebuildable cache. It is written for query performance and is **never**
consulted as authority for a money decision. Every state change to a transaction is accompanied by
the ledger mutation that justifies it, in one ACID transaction.

**Consequences that shape the schema.** Because `transactions` is a projection, its `status`
column is `ENUM`, not a foreign key, and nothing may `SET NULL` or cascade off it. A `reversed_by`
reference is **not** a column on `transactions` — it is derived from `reversals` (see A7).

### A5 — Ledger entries are append-only, and the database refuses to prove otherwise *(A5)*

**Status:** 🟡 Provisional (follows SPEC §4.2, H-6) · **Binds:** Epics 1, 5.

`ledger_entries` has **no `status` column** — this adopts H-6's elaboration (🟠 **proposed, not
approved**). The specification's §3.1 entity lists `status` and §9.4's sweeper query filters on
`status = 'CHAIN_PENDING'`; H-6 replaces that with durable chain-close records as the evidence of
finality.

**The status of an entry is derived, not stored:**

```
entry is chain-closed  ⟺  a row exists in chain_close_records for that entry_id
```

**Why this is better, not just different.** A `status` column on an append-only table is a
mutable field that must be updated — which is exactly the kind of "authoritative mutable money
state" that C-1's rationale rejects. Worse, SPEC §5.1 lists `status` inside the **hashed body**,
so a mutable field inside a hash chain is a contradiction: either the hash must change on every
status transition (rewriting history, forbidden by §4.2), or the chain is broken. Removing
`status` from `ledger_entries` resolves the contradiction cleanly.

**The append-only guarantee is enforced by three database-level mechanisms**, because application
code alone can be bypassed by a migration, an operator script, or a future service:

1. **No `UPDATE`/`DELETE` privilege.** The application connects with a role that holds
   `INSERT` and `SELECT` on `ledger_entries` but **not** `UPDATE` or `DELETE`. SPEC §14.1 asks for
   "a static scan of application code finds no UPDATE or DELETE" — a privilege grant is strictly
   stronger than a scan.
2. **BEFORE UPDATE / BEFORE DELETE triggers** on `ledger_entries` that `SIGNAL SQLSTATE '45000'`.
   This is the belt to mechanism 1's braces, and it covers a privileged operator session.
3. **No `ON DELETE CASCADE` / `ON UPDATE CASCADE` on any FK referencing `ledger_entries`.**

> **A-001 — the audit-chain trigger dependency.** The audit chain (§11.5) must be append-only on
> the same terms, and H-8's "separate genesis" means it cannot reuse the ledger's canonical form.
> The same trigger mechanism applies, with its own canonicalization and its own genesis literal.
> **Trigger DDL is created by the app's migration role.** The app user has
> `ALL PRIVILEGES ON p2p_platform_dev.*`, which covers `TRIGGER`. If a production deployment
> separates the migration role from the runtime role, the runtime role must still be denied
> `UPDATE`/`DELETE` on both chain tables.

**Consequence for the chain-close record.** `chain_close_records` is immutable too, and its
uniqueness constraint (§9.2) is what makes the closer safe under at-least-once delivery.

**Deliberately open:** H-6 (🟠 proposed — if rejected, `status` returns to `ledger_entries` and
the hashed-body contradiction returns with it). M-2 (⚪ blocked — the exact canonicalization rules
and cross-language test vectors). M-3 (⚪ open — the genesis literal).

### A6 — Per-account sequence numbers are allocated inside the posting transaction *(A6)*

**Status:** 🟡 Provisional (follows SPEC §4.4, R8) · **Binds:** Epics 1, 3, 5.

Every ledger entry carries `account_sequence_number`, unique and **gapless** per `account_id`,
assigned inside the posting transaction.

**Mechanism.** A per-account sequence counter on `ledger_accounts.next_sequence_number`, updated
by a conditional `UPDATE ... SET next_sequence_number = next_sequence_number + 1 WHERE id = ?`,
inside the posting transaction, on a row already locked `FOR UPDATE`. The allocated number is
then written onto the entry.

**Why a counter column rather than `MAX(sequence) + 1`.** `MAX(...) + 1` is not safe under
concurrency: two transactions can read the same maximum and compute the same next value. The
counter update is atomic and serialised by the row lock, so the sequence is gapless by
construction.

**Why gaplessness matters.** Invariant 3 reconstructs an account's balance by summing entries in
sequence order. If a number were skipped, a naive reconstruction would silently omit an entry's
effect. Gaplessness makes "the sequence is complete" checkable — it becomes the mechanism by
which a **deleted entry** is detected, not only a modified one.

**Lock ordering is a deadlock hazard, and the rule is fixed here.** A posting transaction touches
two accounts (payer and payee). If two concurrent transfers run A→B and B→A, each locking its
payer first, they deadlock. The rule: **always lock accounts in ascending `account_id` order,
never in transfer direction.** This must be encoded in a single shared helper, not re-derived per
call site.

**Why the counter lives on `ledger_accounts` and not on `wallets`.** C-2 forbids a second
independently mutable balance field on the Account, and `next_sequence_number` is not a balance —
it is a monotonic allocation counter, like an auto-increment. It is reconciled by the invariant that
sequence numbers are contiguous per account, not by an Invariant-3-style balance comparison. This
distinction is stated explicitly so it is not later mistaken for a second balance.

### A7 — Reversal: a first-class record with its own transaction id *(A7)*

**Status:** ⚙️ Architecture default, resolving **OQ-8** · **Binds:** Epics 1, 3, 4, 5.
**Requires user confirmation.**

This is the decision the user asked to be resolved at implementation level. It is settled here as
an **architecture default**, and flagged for sign-off, because it is a schema-level choice rather
than a requirement-level one — H-2 fixed the *behaviour* (one-shot, full-amount, terminal) but
not the *storage shape*.

**The resolution: the reversal is its own transaction, and it owns its own `transaction_id`.**

```
transactions
  id = 101   original transfer, Alice → Bob, 500 BDT
      ledger_entries:  DEBIT  Alice  50000   (txn 101, seq 11)
                       CREDIT Bob    50000   (txn 101, seq 7)

reversals
  id = 1
  original_transaction_id = 101     ← UNIQUE. This is the "once" constraint.
  reversal_transaction_id  = 202     ← a real transactions row, FK
  status = POSTED

transactions
  id = 202   reversal, Bob → Alice, 500 BDT
      ledger_entries:  DEBIT  Bob    50000   (txn 202, seq 8)
                       CREDIT Alice  50000   (txn 202, seq 12)
```

**Why this resolves OQ-8 cleanly — Invariant 1 survives intact.** SPEC §10.2 states: *"For every
`transaction_id`: SUM(DEBIT) = SUM(CREDIT), and exactly one of each."* If the compensating pair
were written under the original `transaction_id = 101`, that transaction would have **two** DEBITs
and **two** CREDITs, and Invariant 1 would report a violation for a perfectly correct reversal.

By giving the reversal its own `transaction_id = 202`, both transactions independently satisfy
"exactly one DEBIT and one CREDIT". **Invariant 1 needs no exception clause.** The reversal is
simply another transaction — which is semantically exactly right: it *is* another movement of
money, and the ledger records history.

**The "once" constraint is a unique index, not application logic.** `reversals.original_transaction_id`
carries a `UNIQUE` constraint. A second reversal against the same transaction is a **database
error**, not a failed application check. This satisfies H-2's "enforced at the data layer" and
makes concurrent double-refunds structurally impossible rather than merely unlikely.

**The reversal's own transaction row is required, not optional.** The compensating entries are
ordinary ledger entries and need ordinary per-account sequence numbers, a chain position, and a
place in the chain order. Reusing the original transaction's id would make the reversal's entries
invisible to every per-account and per-transaction query, the chain closer's ordering, and the
reconciliation engine.

**Resolution of OQ-9 — how `REVERSED` is derived.** The ledger wins (A4), so the derivation is a
pure function of the ledger plus the `reversals` table:

```
For a transaction T:
  entries(T) = ledger entries with transaction_id = T

  derived_state(T):
    if |entries(T)| == 0                          → INITIATED / PENDING / LOCKED (pre-post)
    if |entries(T)| == 2 and one DEBIT + one CREDIT → POSTED   (subject to chain-closed check)
    if a row exists in reversals
       where reversal_transaction_id = T          → REVERSED
    otherwise                                    → CHAIN_PENDING
```

The key point: **`REVERSED` is derived from the existence of a `reversals` row pointing at this
transaction, not from the entry count.** The entry count for a reversed transaction is two (the
reversal's own pair) — the original's two entries live under a different id. So there is no
four-entry ambiguity to resolve at all, and the `REVERSED` derivation does not depend on how many
entries exist.

**The `transactions.status` projection for the original transaction.** The original transaction's
status becomes `REVERSED` because a `reversals` row exists with
`original_transaction_id = T`. That is a projection write, allowed and expected — it is
rebuildable from the ledger plus `reversals`, so A4's "ledger wins" rule is preserved.

**Separate reversal lifecycle (R20), concretely.** `reversals.status` is its own
`ENUM('PENDING','CHAIN_PENDING','POSTED','REJECTED')`, tracked independently of the original
transaction's status:

| `reversals.status` | Meaning | Money location |
|---|---|---|
| `PENDING` | Authorized, awaiting funds check and posting | Unchanged |
| `CHAIN_PENDING` | Compensating entries committed, chain not advanced | Moved, not provable |
| `POSTED` | Compensating pair chain-closed; reversal final | Final |
| `REJECTED` | Recipient lacked sufficient funds → manual dispute review | Unchanged |

The original transaction's status is derived as: `POSTED` → `REVERSED` **only when
`reversals.status = 'POSTED'`**. While the reversal is `CHAIN_PENDING`, the original remains
`POSTED` — the money has not yet provably returned. A reversal is therefore not "committed" from
the user's perspective until it is chain-closed, exactly like a transfer.

**What happens to the original transaction's `status` when a reversal is rejected.** It remains
`POSTED`. Nothing changed. This is the correct and reassuring answer: a rejected reversal leaves
the original transfer exactly as it was.

### A8 — Audit writes are ordered after the ledger commit, and recorded as durable outbox rows *(A8)*

**Status:** ⚙️ Architecture default, resolving **OQ-10** · **Binds:** Epics 2, 3, 4, 5.
**Requires user confirmation.**

This is the second implementation-level decision the user asked to be resolved. The question was:
does the audit write join the ledger transaction, or follow it?

**The resolution: the audit write does NOT block the money path, but it is never lost.**

Two mechanisms, in one pattern:

**1. Outbox row in the same transaction.** For any action that must be audited, the same ACID
transaction that performs the money movement also inserts an `audit_outbox` row. The outbox row is
durable the instant the ledger commit succeeds — it cannot be lost, because it committed with the
money. **This is what makes the ordering safe:** the audit *intent* is atomic with the money.

**2. An Audit Service worker drains the outbox** into the immutable `audit_log` chain, appending
entries with their own `prev_hash`/`entry_hash` and its own genesis (H-8). The worker is
idempotent on `outbox.id`.

**Why not put the audit write in the same transaction as the ledger.** If the Audit Service were
unavailable, every transfer would fail. That converts an observability dependency into a money
availability dependency — a strictly worse failure mode. Rejections are still never silent
(the outbox row exists for them too), but an audit outage must not stop the ledger.

**Why this satisfies §14.7's "compromise does not permit undetected Audit Service tampering".**
The audit chain is independent (its own canonical form, its own genesis), so a ledger-chain
compromise cannot forge audit entries. And because the outbox row commits atomically with the
money, an attacker who could suppress the ledger write would also have to suppress the audit
*intent* — which requires compromising the same database write, not merely the audit chain.

**The honest residual risk, stated rather than hidden.** Between the ledger commit and the
outbox drain, there is a window in which the audit chain does not yet contain the record. The
window is bounded by the outbox worker's poll interval, and the backlog depth is a monitored
metric. This is a deliberate trade: *availability of money movement* is preferred over
*immediate* audit presence, because the alternative lets an audit-component outage freeze all
transfers. **The user should confirm this trade-off explicitly** — it is the substantive part of
OQ-10, and it is not a decision this spine should make silently on their behalf.

**For security alerts specifically (R12, R21, R25).** An unauthorized debit attempt or a rejected
reversal attempt produces an audit record *and* a security alert. The alert is raised from the
outbox drain (not from the request path), so an alert storm cannot amplify into a synchronous
failure. The **audit record** is durable-with-the-money; the **alert** is asynchronous.

**Audit chain independence (H-8, ✅ unconfirmed).** Separate table, separate genesis, separate
canonicalization. This spine adopts all three. Whether the audit chain lives in the same schema
or a different one is A-013 below.

### A9 — Reservations are durable records, and the counter is a cache of them *(A9)*

**Status:** 🟡 Provisional (follows C-1) · **Binds:** Epics 1, 3, 5.

C-1's Option A3 is adopted exactly as approved: a `reserved_minor` counter column on `wallets`,
**subordinate to** durable `reservations` records, plus a fourth reconciliation invariant.

**`reserved_minor` is a derived counter, not authority.** The `reservations` table is the
authority for in-flight holds; `wallets.reserved_minor` is a cache of it, exactly as
`transactions.status` is a cache of the ledger. Invariant 4 compares them nightly and **never
auto-repairs**.

**The `0 <= reserved_minor <= balance_minor` invariant is not expressible as a MySQL CHECK.**
A MySQL CHECK constraint may reference columns of its own row, but `balance_minor` and
`reserved_minor` are both on `wallets` — so this one *is* expressible:

```sql
CONSTRAINT ck_wallets_reserved_within_balance
  CHECK (reserved_minor <= balance_minor)
```

**This works because both columns live on the same row.** The constraint is evaluated on every
`UPDATE`, so it also catches the transitional state *during* the posting transaction. The posting
transaction must therefore order its writes so that no intermediate statement violates it: debit
the payer's `balance_minor` and decrement `reserved_minor` in a single statement (or in an order
that keeps the constraint satisfied at every step), and credit the payee independently.

**The reservation lifecycle and the fencing rule (C-1 condition 5).** Reservation and posting are
mutually exclusive. The mechanism is the `transactions.lock_epoch` fencing token (A10): every
mutation of a reservation row and of the wallet's `reserved_minor` is guarded by a conditional
update carrying the expected `lock_epoch` and the expected reservation state. Zero rows affected
means the lease was reclaimed; the worker rolls back immediately. This is the same fencing
primitive as the posting path, not a second mechanism.

**Reaper release.** The Reaper reclaims an expired `LOCKED` lease, increments `lock_epoch`, and
releases the reservation — but only via a conditional update on the *expected* `lock_epoch`. If
the posting worker has already committed, the Reaper's update affects zero rows and it does
nothing. The reservation is therefore never released twice and never released after a successful
posting, by construction rather than by convention.

**Accounting for a released reservation.** Releasing decrements `reserved_minor` and moves the
reservation row to `RELEASED`. `balance_minor` was never touched by a reservation, so a release
does not touch it either. This is the whole point of the reservation design: the money path only
ever moves `balance_minor`, once.

### A10 — `lock_epoch` is a per-transaction fencing token *(A10)*

**Status:** ⚙️ Architecture default (follows H-7, ✅ unconfirmed) · **Binds:** Epics 3, 4.

`transactions.lock_epoch` is a per-transaction monotonic counter, incremented on every lock
acquisition and every reclamation. **Not global.** A global epoch would serialise unrelated
transactions and defeat the concurrency the design depends on.

Every guarded write follows the specification's pattern exactly:

```sql
UPDATE transactions
   SET status = 'CHAIN_PENDING', lock_epoch = :new_epoch, updated_at = now()
 WHERE id = :id
   AND status = 'LOCKED'
   AND lock_epoch = :expected_epoch;
```

**Zero rows affected ⇒ this worker lost the lock ⇒ roll back immediately.** This is the
anti-stale-worker primitive from SPEC §6.3 and failure modes 3 and 4.

**Lock acquisition order (deadlock avoidance).** When a transaction touches more than one row,
locks are taken in a fixed global order. The rule, encoded once in a shared helper:

1. `transactions` row — by `id`, ascending.
2. `ledger_accounts` rows — by `account_id`, ascending (**never** in transfer direction; see A6).
3. `wallets` rows — by `wallet_id`, ascending.

Taking `transactions` before accounts before wallets is the reverse of the FK dependency
direction, so a lock can never wait on a row that is itself waiting.

### A11 — The chain closer commits the close record and the head together, and acknowledges after *(A11)*

**Status:** 🟡 Provisional (follows SPEC §7.4, §9.2) · **Binds:** Epics 1, 3, 5.
**Resolves OQ-13 — requires user confirmation.**

**The chain-close transaction contains exactly two writes:**

```sql
BEGIN;
  INSERT INTO chain_close_records (transaction_id, entry_id, chain_position, closed_at)
    → UNIQUE VIOLATION ⇒ already closed ⇒ ROLLBACK, log an audit trace, ACK, continue

  UPDATE ledger_chain_head
     SET current_hash = :next_hash,
         last_entry_id = :entry_id,
         last_sequence = :last_sequence,
         updated_at = now()
   WHERE chain_id = 1;              -- the singleton row
COMMIT;

ACK to broker        ← strictly AFTER commit
```

**Commit-then-ack, always.** Acknowledging first means a crash loses the event and the transfer
hangs in `CHAIN_PENDING`, making the sweeper a correctness dependency for *every* transaction.
Committing first means a crash causes redelivery, which the `UNIQUE (transaction_id, entry_id)`
constraint absorbs harmlessly. This is SPEC §7.4 verbatim and it is not negotiable.

**Resolution of OQ-13 — where the `POSTED` status write happens.** It is **outside** the
chain-close ACID unit and **after** the broker acknowledgement, as SPEC §7.4's ordering implies.
The consequence the user asked to have stated explicitly:

> A crash between the chain-close commit and the `POSTED` status write leaves a **chain-closed
> entry whose transaction still reads `CHAIN_PENDING`**. This is tolerated for exactly one
> reason: `transactions.status` is a rebuildable projection (A4, SPEC §4.3), so the reconciliation
> engine diffs it against the ledger-derived truth and **the ledger wins**. The stale projection
> is corrected on the next reconciliation run.

**This is why the status write is outside the transaction, and not an oversight.** Putting it
inside would mean the chain head and the projection commit atomically — which sounds better but
is *worse*: it puts a second row write inside the hottest, most latency-constrained transaction
in the system (SPEC §12.3 requires close commits under 1ms), and it buys nothing, because the
projection is rebuildable anyway. The specification's own §13 failure table lists no failure mode
for this window precisely because it is not a money-impacting failure.

**The safer variant, and why it is not chosen.** Writing `POSTED` inside the same transaction would
eliminate the stale-projection window. It is rejected because the cost is a guaranteed extra row
write inside a transaction with a hard 1ms budget, and the benefit is nil (the projection is
rebuildable and the ledger already wins). **The user should confirm this trade-off.**

**The `chain_position` column.** `chain_close_records.chain_position` is the entry's position in
the global chain, assigned monotonically by the closer. It makes "the Nth entry in the chain" a
direct query rather than a chain walk, and it lets the sweeper and the verifier detect ordering
violations cheaply.

**The chain head singleton.** `ledger_chain_head` holds **exactly one row**, enforced by a
`CHECK (chain_id = 1)` constraint. It is not a "row with id 1" that a bad migration could
duplicate — the CHECK makes a second row impossible.

### A12 — The chain head's hash and the chain head's row are the same thing *(A12)*

**Status:** ⚙️ Architecture default · **Binds:** Epics 1, 3, 5.

`ledger_chain_head.current_hash` is the hash of the most recently closed entry. It is **never
recomputed from the entries table**; it is advanced only by the closer, in the same transaction
that writes the close record (A11).

**Why.** If the head were derived by scanning `ledger_entries`, a `DELETE` from the entries table
would silently move the derived head backwards and the chain would still verify — which is
precisely failure mode 8 in SPEC §13: *"Silent — chain forks, still verifies."* Deriving the head
means a deleted entry changes the head and breaks continuity detection.

**Consequence.** The head is a *stored* value advanced transactionally, and the entries table is
append-only and trigger-protected (A5). Together they make "an entry was deleted" detectable.

### A13 — Idempotency records are keyed by `(user_id, idempotency_key)` and never cleared on failure *(A13)*

**Status:** 🟡 Provisional (follows SPEC §8) · **Binds:** Epic 3.

`UNIQUE (user_id, idempotency_key)` — the composite unique constraint is the entire mechanism.
The same key submitted 10× concurrently produces exactly one transfer because the second insert
fails on the unique index.

**`request_fingerprint`** holds a SHA-256 of the canonical request payload. A replay with a
*different* payload under the same key is a client bug, and the spine adopts **HTTP 409 Conflict**
as the response (M-5, ✅ unconfirmed — flagged for confirmation).

**Retention.** The specification's 24-hour TTL with nightly deletion is the current behaviour.
M-4 (🟠 **proposed, not approved**) would replace it with tiered retention separating key
validity, duplicate-financial-operation prevention, failed/in-flight retry handling, and audit
retention, with **no unconditional nightly deletion**.

**The spine does not adopt M-4.** The schema is designed so M-4 can be adopted without a schema
change: the idempotency table carries `expires_at` **and** `created_at` as separate columns, so
"key validity" and "duplicate-prevention evidence" can later be given different lifetimes. **No
row is deleted by the schema** — retention is a job, not a constraint.

**`created_at` precision and TTL semantics.** A 24-hour TTL computed from `created_at` means a key
is reusable at exactly 24 hours. Per SPEC §8.3, *"a client reusing one key across a multi-day
outage will execute once per 24-hour window — this is by design."* This is a documented client
contract, not a defect.

### A14 — Four invariants, run nightly, never auto-repaired *(A14)*

**Status:** 🟡 Provisional (follows SPEC §10, R23, R24) · **Binds:** Epic 5.

| # | Invariant | Statement | Detection |
|---|---|---|---|
| 1 | Transaction balance | For every `transaction_id`: exactly one DEBIT and one CREDIT of equal magnitude | SQL group-by over entries |
| 2 | Cryptographic continuity | Each entry's `prev_hash` equals its predecessor's `entry_hash`; the final entry's hash equals `chain_head.current_hash` | Chain walk from genesis |
| 3 | Balance reconstruction | Per account: `opening_balance + credits − debits` over sequence ≤ N equals the stored balance | Sequence-ordered replay |
| 4 | Reservation reconciliation | Per wallet: `reserved_minor` equals the sum of valid active reservations | Join against `reservations` |

**Invariant 3's `opening_balance` is derived, never seeded.** It is the sum of credits minus
debits over an account's entries *before* the first user transfer — which, under H-1 (🟠
proposed), is the balance created by a funding entry pair from a SYSTEM account. **If H-1 is
rejected**, there is no defined funding source, `opening_balance` is undefined, and **Invariant 3
cannot be evaluated for a funded wallet**. This coupling is stated explicitly because it makes
H-1 a *data-modeling* dependency, not merely a business-process one.

**"Valid active reservation" is defined.** For Invariant 4, a reservation is *active* when its
status is `HELD` **or** `RELEASED` is false and it has not been consumed by a posting. Concretely:
active = `status IN ('HELD','POSTING')` and `released_at IS NULL`. The exact predicate must be
written once, in the reconciliation code, and reused by both the invariant check and the
`reserved_minor` maintenance path — otherwise the two can drift, which is the very thing
Invariant 4 exists to detect.

**No auto-repair.** A violation is a page, not a job. The reconciliation engine reports; it never
writes to `wallets`, `ledger_accounts`, `ledger_entries`, or `reservations`.

### A15 — Audit chain: separate table, separate genesis, separate canonicalization *(A15)*

**Status:** ⚙️ Architecture default (follows H-8, ✅ unconfirmed) · **Binds:** Epics 2, 3, 4, 5.
**Resolves OQ-11.**

The audit chain lives in the **same MySQL schema**, in its own table `audit_log`, with:

- its own `prev_hash` / `entry_hash` columns,
- **its own genesis literal** (never the ledger's),
- **its own canonicalization function** (never the ledger's),
- its own append-only trigger protection (A5),
- its own monotonically increasing `audit_sequence_number` for global ordering.

**Same schema, not a separate schema or a separate database.** The reasons:

1. The audit write happens in the same transaction as the money movement (A8's outbox is
   same-transaction), and cross-schema writes in MySQL are not atomic with the rest of a
   transaction in a way that avoids the same cost.
2. A separate *database* would mean the audit chain is not covered by the same backup, replica,
   and restore procedure — and a financial audit trail that can be restored to a different point
   in time than the ledger it audits is worse than one that cannot.
3. The independence that SPEC §11.5 actually requires is **cryptographic** independence — a
   separate hash chain with a separate genesis, so that compromising one chain's computation
   does not let an attacker forge entries in the other. A separate table with a separate
   canonicalization and genesis delivers that. A separate *schema* adds operational complexity
   without adding cryptographic independence.

**The "compromise" property is preserved.** An attacker with full write access to `ledger_entries`
still cannot forge `audit_log` entries, because the audit chain's hashes are computed over a
different canonical form starting from a different genesis. The attacker would need the audit
chain's canonicalization *and* its genesis *and* the full chain up to the point of forgery.

**What this spine cannot do.** It cannot protect against an attacker with write access to the
whole database at the same instant. That is out of scope for an application-level design and
belongs to infrastructure controls (append-only storage, restricted grants, external anchoring of
the head hash). Worth naming rather than implying.

### A16 — The closer is a singleton, enforced by a lease with a startup assertion *(A16)*

**Status:** 🟡 Provisional (follows SPEC §2.2, §13.1) · **Binds:** Epics 1, 3, 5.

Failure mode 11 is the one failure the specification calls **irrecoverable**: two closers, two
chain heads, a fork that cannot be reconciled without operator intervention. It has **no runtime
defence** — only deployment configuration and a startup assertion.

**The spine makes the singleton a schema-enforced property.** The chain head is a single row
(A12). Two closers cannot both advance it *correctly* — but they can both advance it
*incorrectly*, which is the fork. So the defence is:

1. **A leader lease** acquired via `SELECT ... FOR UPDATE` on the singleton head row, or via the
   cache's atomic lock. The closer holds it for its lifetime.
2. **A startup assertion**: before processing anything, the closer verifies it holds the lease.
   If it does not, it **refuses to start**. This is a hard fail, not a warning.
3. **The chain-advance update is conditional on the lease epoch**, so a closer that lost its lease
   affects zero rows and rolls back.

**Deployment.** Exactly one closer process. This is stated in the runbook as a hard constraint, not
a default.

**Scale ceiling.** The closer is the architectural ceiling (SPEC §12.3): single-writer max TPS =
1 ÷ close-commit latency. The 1,000 TPS burst target requires close commits **under 1ms**.
**Load-test the closer before building the wallet path** — every other component's design is
waiting on this number.

### A17 — Ledger partitioning is deferred; the reason is engine-level *(A17)*

**Status:** 🟡 Provisional (follows M-1, 🟠 proposed) · **Binds:** Epic 5.

No partitioning now. The **scoped, accurate** rationale — stated so it is not overclaimed:

> **MySQL InnoDB does not support foreign keys on partitioned tables.** `ledger_entries` carries
> foreign keys to `transactions` and `ledger_accounts`; partitioning it would require removing
> them, weakening referential integrity at the data layer. Partitioning is therefore deferred
> pending benchmark results, and revisited without weakening account-sequence, foreign-key, or
> chain invariants.

This is **not** a claim that partitioning inherently destroys sequence uniqueness in all
architectures. It is a claim about this engine, this table, and these constraints.

**The alternative if reconciliation becomes too slow** is a separate archive/replica path for the
nightly scan — not partitioning the live table.

### A18 — Reconciliation partitioning by year is a *scan strategy*, not table partitioning *(A18)*

**Status:** ⚙️ Architecture default · **Binds:** Epic 5.

SPEC §12.4's "reconciliation partitioning by year" is satisfied by **filtering the nightly scan by
`created_at` year** over an unpartitioned table with a proper index — *not* by MySQL table
partitioning. The two are independent; the spec's phrase is ambiguous, and this reading preserves
A17's deferral without weakening the nightly-scan bound.

**Index needed:** a composite index supporting the scan — `(account_id, account_sequence_number)`
for Invariant 3, and `(transaction_id, direction)` for Invariant 1.

---

## 2. Data model — the MySQL ERD

### 2.1 Entity relationship overview

```
                          ┌──────────────────┐
                          │      users       │
                          │  id, handle,     │
                          │  kyc_status,     │
                          │  auth_subject    │
                          └────────┬─────────┘
                                   │ 1
                    ┌──────────────┼──────────────┐
                    │ N            │ N            │ N
          ┌─────────▼───────┐  ┌───▼────────────┐ └──▼──────────────┐
          │    wallets      │  │  transactions  │   kyc_transitions │
          │ id, owner_user, │  │ id, initiator, │   (audit trail)   │
          │ currency,       │  │ counterparty,  │                  │
          │ balance_minor,  │  │ payer/payee    │                  │
          │ reserved_minor  │  │ wallet, amount,│                  │
          └────┬────────┬───┘  │ status, epoch, │                  │
               │ 1      │ 1     │ idempotency... │                  │
               │        │       └───┬────────┬───┘                  │
      ┌────────▼──────┐ │           │ 1      │ 1                    │
      │ledger_accounts│ │      ┌────▼─────┐  │                      │
      │ id, owner_user│ │      │reservatio│  │                      │
      │ (NULL=SYSTEM), │ │      │ns       │  │                      │
      │ type, role,    │ │      │ id, txn, │  │                      │
      │ next_sequence  │ │      │ amount,  │  │                      │
      └────────┬───────┘ │      │ status,  │  │                      │
               │ 1       │      │ epoch    │  │                      │
               │         │      └──────────┘  │                      │
      ┌────────▼─────────▼─────────────────────▼──────────┐          │
      │              ledger_entries                       │          │
      │  entry_id, transaction_id, account_id,            │          │
      │  direction, amount_minor, currency,               │          │
      │  account_sequence_number, prev_hash, entry_hash   │          │
      └───────────────────────┬───────────────────────────┘          │
                              │ 1                                    │
                    ┌─────────▼──────────┐                          │
                    │ chain_close_records│  (immutable)              │
                    │ transaction_id,    │                          │
                    │ entry_id,          │                          │
                    │ chain_position     │                          │
                    └────────────────────┘                          │
                                                               ┌────▼─────┐
                    ┌──────────────────┐                          │audit_out │
                    │ ledger_chain_head│ (singleton, chain_id=1)   │ box      │
                    │ current_hash,     │                          └────┬─────┘
                    │ last_entry_id,    │                               │
                    │ last_sequence     │                          ┌────▼─────┐
                    └──────────────────┘                          │ audit_log│
                                                                │(separate│
                                                                │ chain)  │
                                                                └──────────┘

  ┌────────────────┐        ┌────────────────────────┐
  │ idempotency_   │        │ reversal_reason_codes  │
  │ records        │        │ (closed set, seed data) │
  │ user_id, key,  │        └────────────────────────┘
  │ transaction_id,│
  │ fingerprint,   │        ┌────────────────────────┐
  │ expires_at     │        │ reversals              │
  └────────────────┘        │ id, original_txn_id    │
                            │ (UNIQUE), reversal_txn_id│
                            │ status, reason_code,    │
                            │ actor_id, audit_id      │
                            └────────────────────────┘
```

### 2.2 Table definitions

*MySQL 8.0.46, InnoDB, `utf8mb4`, UTC. Column types are normative.*

---

#### `users` — extends the existing starter-kit table

```sql
ALTER TABLE users
  ADD COLUMN handle              VARCHAR(32)  NOT NULL,
  ADD COLUMN auth_subject        VARCHAR(191) NOT NULL,
  ADD COLUMN kyc_status          ENUM('PENDING','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING',
  ADD COLUMN public_profile      JSON         NULL,
  ADD COLUMN kyc_verified_at     DATETIME(6)  NULL,
  ADD COLUMN kyc_rejected_at     DATETIME(6)  NULL;

CREATE UNIQUE INDEX uq_users_handle        ON users (handle);
CREATE UNIQUE INDEX uq_users_auth_subject  ON users (auth_subject);
CREATE INDEX        ix_users_kyc_status    ON users (kyc_status);
```

**Notes.**
- `kyc_status` is `NOT NULL DEFAULT 'PENDING'` — a new user begins `PENDING` (H-5).
- The enum's stored value is `VERIFIED`, not the specification's literal `KYC_VERIFIED`. The §7.1
  check is reconciled to `kyc_status = 'VERIFIED'` (PRD delta D-6).
- `kyc_verified_at` / `kyc_rejected_at` are **projections** of the transition history in
  `kyc_transitions`, consistent with A4's "status is a cache" principle.
- The enum is a *CHECK* in disguise. A `CHECK (kyc_status IN (...))` is equivalent and marginally
  more portable; MySQL 8.0.16+ supports CHECK natively. The ENUM is used because it renders
  self-documenting in the schema and because Laravel's `enum()` cast maps to it directly.

---

#### `kyc_transitions` — the KYC audit trail (H-5)

```sql
CREATE TABLE kyc_transitions (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED  NOT NULL,
  from_status     ENUM('PENDING','VERIFIED','REJECTED') NOT NULL,
  to_status       ENUM('PENDING','VERIFIED','REJECTED') NOT NULL,
  reason          VARCHAR(1024)    NOT NULL,
  actor_user_id   BIGINT UNSIGNED  NOT NULL,
  audit_log_id    BIGINT UNSIGNED  NULL,
  created_at      DATETIME(6)      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_kyc_transitions_user (user_id, created_at),
  CONSTRAINT fk_kyc_transitions_user
    FOREIGN KEY (user_id)       REFERENCES users(id),
  CONSTRAINT fk_kyc_transitions_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
  CONSTRAINT ck_kyc_transitions_change CHECK (from_status <> to_status)
) ENGINE=InnoDB;
```

**Notes.**
- `actor_user_id` is **NOT NULL** — every transition has an actor. Combined with "users cannot
  self-verify", the application layer must reject `actor_user_id = user_id` for a
  `→ VERIFIED` transition. A CHECK cannot express that (it would need a subquery), so it is
  enforced in the service layer and asserted by an integration test.
- `from_status <> to_status` prevents no-op transitions.
- `audit_log_id` links to the audit chain entry (A8/A15).

---

#### `ledger_accounts` — the ledger-side account (C-2 / A3)

```sql
CREATE TABLE ledger_accounts (
  id                      BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  owner_user_id           BIGINT UNSIGNED  NULL,
  account_type            ENUM('USER','SYSTEM') NOT NULL DEFAULT 'USER',
  system_role             ENUM('FUNDING','TREASURY') NULL,
  currency                CHAR(3)          NOT NULL DEFAULT 'BDT',
  next_sequence_number    BIGINT UNSIGNED  NOT NULL DEFAULT 1,
  created_at              DATETIME(6)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ledger_accounts_owner (owner_user_id),
  KEY ix_ledger_accounts_system_role (system_role),
  CONSTRAINT fk_ledger_accounts_owner
    FOREIGN KEY (owner_user_id) REFERENCES users(id),
  CONSTRAINT ck_ledger_accounts_type
    CHECK (
      (account_type = 'USER'    AND owner_user_id IS NOT NULL AND system_role IS NULL) OR
      (account_type = 'SYSTEM'  AND owner_user_id IS NULL     AND system_role IS NOT NULL)
    ),
  CONSTRAINT ck_ledger_accounts_currency CHECK (currency = 'BDT')
) ENGINE=InnoDB;
```

**Notes.**
- `owner_user_id` is nullable **only** to accommodate H-1 (🟠 proposed). Under the current
  (rejected-by-default) reading it would be `NOT NULL`.
- `uq_ledger_accounts_owner` is a unique index on a nullable column: MySQL permits multiple
  `NULL` values in a unique index, so **multiple SYSTEM accounts are allowed** while each user
  gets at most one account. This is the precise mechanism that makes "one account per user" and
  "system accounts have no owner" both true at once.
- **There is deliberately no balance column.** C-2 forbids a second independently mutable balance
  field on the Account. The balance lives on `wallets` as a projection of the account's entries.
- `next_sequence_number` is a **sequence allocator**, not a balance (A6). It is not reconciled by
  Invariant 3.
- `ck_ledger_accounts_currency CHECK (currency = 'BDT')` enforces single-currency Phase 1
  (SPEC §1.4) while keeping the column ISO-4217-shaped for the future (M-8).

---

#### `wallets` — the user-facing balance container (C-1 / A2 / A9)

```sql
CREATE TABLE wallets (
  id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  owner_user_id     BIGINT UNSIGNED  NOT NULL,
  account_id        BIGINT UNSIGNED  NOT NULL,
  currency          CHAR(3)          NOT NULL DEFAULT 'BDT',
  balance_minor     BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  reserved_minor    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  created_at        DATETIME(6)      NOT NULL,
  updated_at        DATETIME(6)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallets_owner_currency (owner_user_id, currency),
  UNIQUE KEY uq_wallets_account (account_id),
  KEY ix_wallets_owner (owner_user_id),
  CONSTRAINT fk_wallets_owner
    FOREIGN KEY (owner_user_id) REFERENCES users(id),
  CONSTRAINT fk_wallets_account
    FOREIGN KEY (account_id)    REFERENCES ledger_accounts(id),
  CONSTRAINT ck_wallets_reserved_within_balance
    CHECK (reserved_minor <= balance_minor),
  CONSTRAINT ck_wallets_currency CHECK (currency = 'BDT')
) ENGINE=InnoDB;
```

**Notes.**
- `uq_wallets_account` is the **hard 1:1 enforcement** — one account maps to at most one wallet.
  Together with `uq_ledger_accounts_owner` (one user → at most one account) and
  `uq_wallets_owner_currency`, the mapping is exactly 1:1 per user per currency.
- `balance_minor` and `reserved_minor` are `BIGINT UNSIGNED`. A negative wallet is not merely
  forbidden by application logic — **it is unrepresentable** (A2).
- `ck_wallets_reserved_within_balance` is R4's invariant at the storage layer. Both columns are
  on the same row, so the CHECK is expressible and is re-evaluated on every update.
- `account_id` is `NOT NULL` and immutable. There is no `ON UPDATE CASCADE` on
  `fk_wallets_account`, so a re-point would have to be an explicit `UPDATE` — which is forbidden
  by convention and detectable by the reconciliation engine.
- **No `ON DELETE CASCADE` anywhere.** Deleting a wallet or an account with history must fail,
  not silently orphan ledger entries.

---

#### `transactions` — the transfer projection (A4 / A11)

```sql
CREATE TABLE transactions (
  id                      BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  initiator_user_id       BIGINT UNSIGNED  NOT NULL,
  counterparty_user_id    BIGINT UNSIGNED  NOT NULL,
  payer_wallet_id         BIGINT UNSIGNED  NOT NULL,
  payee_wallet_id         BIGINT UNSIGNED  NOT NULL,
  amount_minor            BIGINT UNSIGNED  NOT NULL,
  currency                CHAR(3)          NOT NULL DEFAULT 'BDT',
  status                  ENUM('INITIATED','PENDING','LOCKED','CHAIN_PENDING',
                                'POSTED','FAILED','REVERSED') NOT NULL DEFAULT 'INITIATED',
  idempotency_key         VARCHAR(191)    NOT NULL,
  lock_epoch              BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  locked_at               DATETIME(6)      NULL,
  chain_pending_at        DATETIME(6)      NULL,
  posted_at               DATETIME(6)      NULL,
  failure_reason          VARCHAR(512)    NULL,
  created_at              DATETIME(6)      NOT NULL,
  updated_at              DATETIME(6)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_idempotency (initiator_user_id, idempotency_key),
  KEY ix_transactions_payer_status   (payer_wallet_id, status),
  KEY ix_transactions_payee_status   (payee_wallet_id, status),
  KEY ix_transactions_chain_pending (status, chain_pending_at),
  KEY ix_transactions_initiator      (initiator_user_id, created_at),
  CONSTRAINT fk_transactions_initiator
    FOREIGN KEY (initiator_user_id)    REFERENCES users(id),
  CONSTRAINT fk_transactions_counterparty
    FOREIGN KEY (counterparty_user_id) REFERENCES users(id),
  CONSTRAINT fk_transactions_payer_wallet
    FOREIGN KEY (payer_wallet_id)      REFERENCES wallets(id),
  CONSTRAINT fk_transactions_payee_wallet
    FOREIGN KEY (payee_wallet_id)      REFERENCES wallets(id),
  CONSTRAINT ck_transactions_amount   CHECK (amount_minor > 0),
  CONSTRAINT ck_transactions_distinct CHECK (payer_wallet_id <> payee_wallet_id),
  CONSTRAINT ck_transactions_currency  CHECK (currency = 'BDT')
) ENGINE=InnoDB;
```

**Notes.**
- `uq_transactions_idempotency` is a **belt-and-braces** duplicate of `idempotency_records`'s
  unique constraint. Two independent unique indexes on the same logical key means a bug in one
  write path cannot create a duplicate transfer. This is cheap insurance for the one table where
  a duplicate means duplicated money.
- `status` is the ENUM projection. Note the specification's §6.2 diagram shows
  `LOCKED → FAILED` and `PENDING → FAILED`; the ENUM permits all seven states and the
  transition legality is enforced by triggers (see 2.3).
- `lock_epoch` is per-transaction (A10).
- **No `reversed_by` column.** The reversal relationship lives in `reversals` (A7).

---

#### `reservations` — durable in-flight holds (C-1 / A9)

```sql
CREATE TABLE reservations (
  id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  transaction_id    BIGINT UNSIGNED  NOT NULL,
  wallet_id         BIGINT UNSIGNED  NOT NULL,
  amount_minor      BIGINT UNSIGNED  NOT NULL,
  currency          CHAR(3)          NOT NULL DEFAULT 'BDT',
  status            ENUM('HELD','POSTING','POSTED','RELEASED') NOT NULL DEFAULT 'HELD',
  lock_epoch        BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  leased_at         DATETIME(6)      NOT NULL,
  lease_expires_at  DATETIME(6)      NOT NULL,
  released_at       DATETIME(6)      NULL,
  posted_at         DATETIME(6)      NULL,
  created_at        DATETIME(6)      NOT NULL,
  updated_at        DATETIME(6)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reservations_transaction (transaction_id),
  KEY ix_reservations_wallet_status (wallet_id, status),
  KEY ix_reservations_lease (status, lease_expires_at),
  CONSTRAINT fk_reservations_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_reservations_wallet
    FOREIGN KEY (wallet_id)      REFERENCES wallets(id),
  CONSTRAINT ck_reservations_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB;
```

**Notes.**
- `uq_reservations_transaction` — one reservation per transaction. The reservation is the durable
  record C-1 condition 2 requires.
- `status` transitions: `HELD → POSTING → POSTED` (consumed by a successful post), or
  `HELD → RELEASED` (Reaper reclaimed an expired lease). **`POSTED` and `RELEASED` are both
  terminal.** A reservation is never released after posting and never released twice — enforced by
  conditional updates carrying the expected `lock_epoch` and expected status (A9, A10).
- `lock_epoch` on the reservation mirrors the transaction's, so the fencing token travels with the
  hold. The posting worker and the Reaper race on the same token; exactly one wins.
- `lease_expires_at` is the Reaper's target. Its value is **open** (OQ-5; a 30s default is
  recommended but unconfirmed).
- `released_at IS NULL` is part of the "active reservation" predicate used by Invariant 4 (A14).

---

#### `ledger_entries` — the immutable double-entry record (A5 / A6)

```sql
CREATE TABLE ledger_entries (
  entry_id                   CHAR(26)         NOT NULL,
  transaction_id             BIGINT UNSIGNED  NOT NULL,
  account_id                 BIGINT UNSIGNED  NOT NULL,
  direction                  ENUM('DEBIT','CREDIT') NOT NULL,
  amount_minor               BIGINT UNSIGNED  NOT NULL,
  currency                   CHAR(3)          NOT NULL DEFAULT 'BDT',
  account_sequence_number    BIGINT UNSIGNED  NOT NULL,
  balance_after              BIGINT UNSIGNED  NULL,
  prev_hash                  CHAR(64)         NOT NULL,
  entry_hash                 CHAR(64)         NOT NULL,
  created_at                 DATETIME(6)      NOT NULL,
  PRIMARY KEY (entry_id),
  UNIQUE KEY uq_ledger_entries_account_sequence (account_id, account_sequence_number),
  UNIQUE KEY uq_ledger_entries_entry_hash (entry_hash),
  KEY ix_ledger_entries_transaction (transaction_id, direction),
  KEY ix_ledger_entries_account_created (account_id, created_at),
  CONSTRAINT fk_ledger_entries_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_ledger_entries_account
    FOREIGN KEY (account_id)    REFERENCES ledger_accounts(id),
  CONSTRAINT ck_ledger_entries_amount   CHECK (amount_minor > 0),
  CONSTRAINT ck_ledger_entries_currency  CHECK (currency = 'BDT'),
  CONSTRAINT ck_ledger_entries_balance   CHECK (balance_after IS NULL OR balance_after >= 0)
) ENGINE=InnoDB;
```

**Notes.**
- **`entry_id` is `CHAR(26)`, a ULID.** Not an auto-increment. Two reasons: (a) the spec §5.1
  hashes `entry_id` into the entry body, and an auto-increment id is a *revealing* sequence —
  an observer can infer transfer volume and timing from it. (b) A ULID is sortable by creation
  time, which gives a stable secondary ordering without a separate column. **This is a spine
  choice (A-019) and needs confirmation.**
- **`uq_ledger_entries_account_sequence`** is the gapless-per-account guarantee (A6). It is a
  unique index, so a duplicate sequence number is impossible at the data layer — and because the
  allocator increments a counter inside the posting transaction, a *skipped* number is impossible
  too. Together they make the sequence complete, which is what lets a deleted entry be detected
  rather than merely a modified one.
- **No `status` column.** H-6's elaboration, adopted (A5). Finality is the existence of a
  `chain_close_records` row.
- **`balance_after` is nullable and excluded from the hash.** SPEC §5.2 is explicit that it is not
  independently verifiable and that including it would make the chain's guarantee illusory. It is
  kept as a display/debugging column only.
- `prev_hash` and `entry_hash` are `CHAR(64)` — lowercase hex SHA-256, 32 bytes.
- `uq_ledger_entries_entry_hash` — a duplicate hash is a hard error. In practice it would mean a
  collision or a duplicated insert; both are bugs worth failing loudly on.
- `created_at` is `DATETIME(6)` — microsecond precision, because it is inside the hashed body and
  must not be truncated between write and verify.
- **Append-only enforcement** (A5): no `UPDATE`/`DELETE` privilege for the runtime role, plus
  BEFORE UPDATE / BEFORE DELETE triggers that `SIGNAL SQLSTATE '45000'`.

---

#### `chain_close_records` — immutable evidence of finality (A5 / A11)

```sql
CREATE TABLE chain_close_records (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  transaction_id  BIGINT UNSIGNED  NOT NULL,
  entry_id        CHAR(26)         NOT NULL,
  chain_position  BIGINT UNSIGNED  NOT NULL,
  closed_at       DATETIME(6)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_chain_close_transaction_entry (transaction_id, entry_id),
  UNIQUE KEY uq_chain_close_entry (entry_id),
  UNIQUE KEY uq_chain_close_position (chain_position),
  KEY ix_chain_close_transaction (transaction_id),
  CONSTRAINT fk_chain_close_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_chain_close_entry
    FOREIGN KEY (entry_id)       REFERENCES ledger_entries(entry_id)
) ENGINE=InnoDB;
```

**Notes.**
- `uq_chain_close_transaction_entry` is SPEC §9.2's constraint verbatim — it is what makes the
  closer safe under at-least-once delivery. A duplicate close is a **normal, expected branch**,
  not an error path.
- `uq_chain_close_entry` is slightly stronger (one close per entry) and is implied by the above
  given a transaction has exactly two entries. It is stated separately so an accidental
  third entry cannot be closed twice under a different transaction id.
- `uq_chain_close_position` makes chain position unique — a total order with no ties.
- This table is immutable too (A5): a close record is never updated or deleted.

---

#### `ledger_chain_head` — the singleton chain head (A11 / A12)

```sql
CREATE TABLE ledger_chain_head (
  chain_id       TINYINT UNSIGNED  NOT NULL,
  current_hash   CHAR(64)         NOT NULL,
  last_entry_id  CHAR(26)         NULL,
  last_sequence  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  updated_at     DATETIME(6)      NOT NULL,
  PRIMARY KEY (chain_id),
  CONSTRAINT ck_chain_head_singleton CHECK (chain_id = 1)
) ENGINE=InnoDB;
```

**Notes.**
- **Exactly one row, enforced.** `CHECK (chain_id = 1)` makes a second row impossible — a bad
  migration cannot create a second head.
- `current_hash` is advanced **only** by the closer, in the same transaction as the close record
  (A11). It is never recomputed by scanning entries (A12).
- `last_sequence` is a diagnostic convenience — the global chain has no sequence requirement
  (per-*account* sequences are the invariant), so this column exists to make the head's position
  queryable without a chain walk.
- Seeded with the genesis literal — **M-3, ⚪ open. The value is not chosen.**

---

#### `reversals` — the one-shot reversal record (A7)

```sql
CREATE TABLE reversals (
  id                        BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  original_transaction_id   BIGINT UNSIGNED  NOT NULL,
  reversal_transaction_id   BIGINT UNSIGNED  NOT NULL,
  status                    ENUM('PENDING','CHAIN_PENDING','POSTED','REJECTED') NOT NULL DEFAULT 'PENDING',
  reason_code               VARCHAR(64)      NOT NULL,
  reason_detail             VARCHAR(1024)    NULL,
  actor_user_id             BIGINT UNSIGNED  NOT NULL,
  audit_log_id              BIGINT UNSIGNED  NULL,
  shortfall_amount_minor    BIGINT UNSIGNED  NULL,
  created_at                DATETIME(6)      NOT NULL,
  updated_at                DATETIME(6)      NOT NULL,
  posted_at                 DATETIME(6)      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reversals_original (original_transaction_id),
  UNIQUE KEY uq_reversals_reversal_txn (reversal_transaction_id),
  KEY ix_reversals_status (status),
  CONSTRAINT fk_reversals_original_txn
    FOREIGN KEY (original_transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_reversals_reversal_txn
    FOREIGN KEY (reversal_transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_reversals_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
  CONSTRAINT ck_reversals_distinct_txn
    CHECK (original_transaction_id <> reversal_transaction_id)
) ENGINE=InnoDB;
```

**Notes.**
- **`uq_reversals_original` is the "once" constraint** (A7). A second reversal against the same
  transaction is a database error, not a failed application check. This is H-2's "enforced at the
  data layer" requirement, satisfied structurally.
- **`uq_reversals_reversal_txn`** — a reversal transaction cannot itself be reversed *as a
  reversal*: only one `reversals` row may point at any given transaction as its original. Because
  the reversal's own `transactions` row exists, attempting to reverse *it* would need a
  `reversals` row with `original_transaction_id = reversal_transaction_id` — which conflicts with
  the existing row and fails. **This makes "a reversal cannot be reversed" a data-layer property,
  not an application check.**
- `status` is the **separate reversal lifecycle** (R20): `PENDING → CHAIN_PENDING → POSTED`, or
  `PENDING → REJECTED`.
- `shortfall_amount_minor` records *how much* the recipient was short by on a rejected reversal.
  This is diagnostic data that makes a dispute-review item actionable without re-deriving it.
- `reason_code` references a closed set (§2.4). The FK to `reversal_reason_codes` makes the closed
  set a **data-layer** constraint, satisfying R21's "reason code drawn from a closed set".
- `audit_log_id` links to the audit chain entry recording the attempt — granted or rejected
  (R21).

---

#### `reversal_reason_codes` — the closed reason set (R21)

```sql
CREATE TABLE reversal_reason_codes (
  code            VARCHAR(64)   NOT NULL,
  label           VARCHAR(255)  NOT NULL,
  description     VARCHAR(1024) NOT NULL,
  requires_second_approval BOOLEAN NOT NULL DEFAULT FALSE,
  is_active       BOOLEAN       NOT NULL DEFAULT TRUE,
  created_at      DATETIME(6)   NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB;
```

**Notes.**
- Seed data only. `requires_second_approval` is the **hook** for H-2's higher-value approval
  threshold (OQ-3) — the threshold *value* stays open, but the mechanism for expressing it
  exists and needs no schema change when it is set (R22).
- `is_active` allows a code to be retired without losing the FK integrity of historical rows.

---

#### `idempotency_records` (A13)

```sql
CREATE TABLE idempotency_records (
  id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED  NOT NULL,
  idempotency_key     VARCHAR(191)    NOT NULL,
  transaction_id      BIGINT UNSIGNED  NULL,
  request_fingerprint CHAR(64)        NOT NULL,
  response_status     SMALLINT UNSIGNED NULL,
  response_body       JSON            NULL,
  created_at          DATETIME(6)     NOT NULL,
  expires_at          DATETIME(6)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_user_key (user_id, idempotency_key),
  KEY ix_idempotency_expires (expires_at),
  CONSTRAINT fk_idempotency_user
    FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_idempotency_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;
```

**Notes.**
- `uq_idempotency_user_key` is the primary mechanism (SPEC §8.1).
- `request_fingerprint` — a SHA-256 of the canonical request payload. A replay with a different
  payload under the same key returns **409 Conflict** (M-5, ✅ unconfirmed — flagged).
- `response_status` / `response_body` cache the verbatim replay response, so a replay returns
  *the same* bytes, not a re-rendered approximation. This matters for the specification's
  "verbatim" requirement.
- `created_at` and `expires_at` are **separate columns**, so M-4's tiered retention can be
  adopted without a schema change (A13).
- **Retention.** Until M-4 is decided, the specification's behaviour stands: a nightly job deletes
  rows past `expires_at`, **never** rows for failed or in-flight transactions.

---

#### `audit_outbox` — the atomic audit intent (A8)

```sql
CREATE TABLE audit_outbox (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  event_type      VARCHAR(64)     NOT NULL,
  actor_user_id   BIGINT UNSIGNED  NULL,
  target_type     VARCHAR(64)     NULL,
  target_id       VARCHAR(64)     NULL,
  outcome         ENUM('GRANTED','REJECTED') NOT NULL,
  metadata        JSON            NULL,
  security_alert  BOOLEAN         NOT NULL DEFAULT FALSE,
  created_at      DATETIME(6)     NOT NULL,
  drained_at      DATETIME(6)     NULL,
  PRIMARY KEY (id),
  KEY ix_audit_outbox_pending (drained_at, id),
  KEY ix_audit_outbox_alert (security_alert, drained_at),
  CONSTRAINT fk_audit_outbox_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
```

**Notes.**
- The row is inserted **in the same transaction** as the money movement, so the audit *intent* is
  atomic with the money (A8).
- `drained_at IS NULL` means "not yet in the audit chain" — the backlog-depth metric that bounds
  the window between the ledger commit and the audit chain entry.
- `security_alert` marks events that must raise an alert (unauthorized debit attempt, rejected
  reversal attempt, KYC transition).
- The outbox is drained by the Audit Service worker, which appends to `audit_log` idempotently on
  `audit_outbox.id`.

---

#### `audit_log` — the independent audit chain (A15)

```sql
CREATE TABLE audit_log (
  id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  audit_sequence_number BIGINT UNSIGNED NOT NULL,
  actor               VARCHAR(191)    NOT NULL,
  action              VARCHAR(64)     NOT NULL,
  target              VARCHAR(191)    NULL,
  outcome             ENUM('GRANTED','REJECTED') NOT NULL,
  metadata            JSON            NULL,
  prev_hash           CHAR(64)         NOT NULL,
  entry_hash          CHAR(64)         NOT NULL,
  created_at          DATETIME(6)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audit_log_sequence (audit_sequence_number),
  UNIQUE KEY uq_audit_log_entry_hash (entry_hash),
  KEY ix_audit_log_created (created_at)
) ENGINE=InnoDB;
```

**Notes.**
- **Its own canonicalization and its own genesis literal** (H-8, ✅ unconfirmed). Never the
  ledger's.
- Append-only, with the same trigger protection as `ledger_entries` (A5).
- `audit_sequence_number` gives the audit chain its own global order — it does **not** share the
  ledger's chain or its head.

---

### 2.3 State-machine enforcement (SPEC §6.2)

*"Legality is enforced in the database via CHECK constraints and triggers — not only in
application code. Application-level enforcement can be bypassed by any other writer: a migration,
an operator script, a future service."*

**BEFORE UPDATE trigger on `transactions`** that raises `SIGNAL SQLSTATE '45000'` on an illegal
transition. The legal set:

| From | Allowed to |
|---|---|
| `INITIATED` | `PENDING`, `FAILED` |
| `PENDING` | `LOCKED`, `FAILED` |
| `LOCKED` | `CHAIN_PENDING`, `FAILED` |
| `CHAIN_PENDING` | `POSTED`, `REVERSED` *(only via the reversal path)* |
| `POSTED` | `REVERSED` |
| `FAILED` | — terminal |
| `REVERSED` | — terminal |

**The `CHAIN_PENDING → REVERSED` edge deserves care.** Under A7, a reversal never sets the
original transaction to `REVERSED` directly — the reversal posts under its own transaction id and
then the original's projection is updated. The trigger therefore permits `CHAIN_PENDING → REVERSED`
**only** when a `reversals` row exists with `original_transaction_id = NEW.id` and
`reversals.status = 'POSTED'`. A trigger *can* check a related table's contents with a `SELECT`,
so this is expressible. Without that condition, an application bug could mark a transfer
`REVERSED` with no compensating entry — the exact failure the ledger-wins rule exists to prevent.

**The `POSTED → REVERSED` edge has the same guard.**

**A second BEFORE UPDATE trigger on `reservations`** enforces `HELD → {POSTING, RELEASED}`,
`POSTING → {POSTED, RELEASED}`, and makes `POSTED`/`RELEASED` terminal. This is what makes
C-1 condition 5 — "a reservation must never be released twice, nor released after successful
posting" — a data-layer property rather than a convention.

### 2.4 Seed data

```sql
INSERT INTO ledger_chain_head (chain_id, current_hash, last_entry_id, last_sequence, updated_at)
VALUES (1, :ledger_genesis_literal, NULL, 0, UTC_TIMESTAMP(6));
-- :ledger_genesis_literal — M-3, ⚪ OPEN. The value is not chosen.

INSERT INTO reversal_reason_codes (code, label, description, requires_second_approval, created_at) VALUES
  ('DUPLICATE_TRANSFER', 'Duplicate transfer', 'The transfer was recorded twice.', FALSE, UTC_TIMESTAMP(6)),
  ('WRONG_RECIPIENT',   'Wrong recipient', 'The transfer went to the wrong counterparty.', FALSE, UTC_TIMESTAMP(6)),
  ('AMOUNT_ERROR',      'Amount error', 'The transferred amount was incorrect.', FALSE, UTC_TIMESTAMP(6)),
  ('UNAUTHORIZED',      'Unauthorized transfer', 'The transfer was not authorized by the payer.', FALSE, UTC_TIMESTAMP(6)),
  ('DUPLICATE_PAYMENT', 'Duplicate payment', 'The payer paid twice for one obligation.', FALSE, UTC_TIMESTAMP(6));
```

**The audit chain's genesis literal is separate and also open** (OQ-6 / H-8).

---

## 3. Index inventory and the queries they serve

| Index | Serves |
|---|---|
| `uq_ledger_accounts_owner (owner_user_id)` | Wallet → account resolution; 1:1 enforcement |
| `uq_wallets_account (account_id)` | 1:1 enforcement; account → wallet lookup |
| `uq_wallets_owner_currency (owner_user_id, currency)` | One wallet per user per currency |
| `uq_ledger_entries_account_sequence (account_id, account_sequence_number)` | **Gapless per-account sequence**; Invariant 3 replay; chain-order-vs-sequence verification |
| `ix_ledger_entries_transaction (transaction_id, direction)` | **Invariant 1**; per-transaction entry reads; reversal derivation |
| `ix_ledger_entries_account_created (account_id, created_at)` | Per-account timeline |
| `uq_chain_close_transaction_entry` | **Idempotent closing** (SPEC §9.2) |
| `uq_chain_close_position (chain_position)` | Total order; chain-position reads |
| `ix_transactions_chain_pending (status, chain_pending_at)` | **Sweeper** query |
| `ix_reservations_lease (status, lease_expires_at)` | **Reaper** query |
| `ix_reservations_wallet_status (wallet_id, status)` | **Invariant 4** — per-wallet active reservation sum |
| `uq_transactions_idempotency` + `uq_idempotency_user_key` | Duplicate-transfer prevention (two independent guards) |
| `uq_reversals_original` | **One-shot reversal** (A7) |
| `uq_reversals_reversal_txn` | **A reversal cannot be reversed** (A7) |
| `ix_audit_outbox_pending (drained_at, id)` | Outbox drain |
| `uq_users_handle`, `uq_users_auth_subject` | Handle lookup; auth subject lookup |

---

## 4. Transaction and concurrency boundaries

| Operation | Transaction scope | Locks taken (in order) |
|---|---|---|
| **Reserve** (Phase 2) | One ACID txn | `transactions` → `ledger_accounts` (payer, ascending) → `wallets` (payer) |
| **Post** (Phase 3) | One ACID txn — debit, credit, sequences, both entries, status | `transactions` → `ledger_accounts` (both, ascending) → `wallets` (both, ascending) |
| **Release reservation** (Reaper) | One ACID txn | `transactions` → `reservations` → `wallets` |
| **Post reversal** | One ACID txn — insufficient-funds check, debit, credit, sequences, entries, `reversals` status | `transactions` (both) → `reversals` → `ledger_accounts` (both, ascending) → `wallets` (both, ascending) |
| **Chain close** | One ACID txn — close record + head advance | `ledger_chain_head` (singleton row) |
| **Status write to `POSTED`** | Own transaction, after ack | `transactions` |

**The global lock-ordering rule** is: `transactions` → `reversals`/`reservations` →
`ledger_accounts` → `wallets` → `ledger_chain_head`. Every path acquires locks in this order.
This must be implemented in **one shared helper**, not re-derived at each call site — a second
ordering is a deadlock waiting to be found.

**Deadlock handling.** Laravel's `DB::transaction($callback, attempts: 5)` retries on deadlock.
The posting path uses it. The retry is safe because the transaction is idempotent under
rollback: a rolled-back attempt leaves no entries, and the idempotency key prevents a second
transfer.

**The conditional update remains the anti-TOCTOU primitive.** Even with row locks, the
specification's pattern is used:

```sql
UPDATE wallets
   SET balance_minor = balance_minor - :amount, updated_at = UTC_TIMESTAMP(6)
 WHERE id = :payer_wallet
   AND balance_minor - reserved_minor >= :amount;
-- 0 rows ⇒ insufficient funds ⇒ ROLLBACK
```

---

## 5. Deliberately left open

*Named, not invented. Each names what it blocks and who can settle it.*

### 5.1 Requirement-layer — from the initiative ticket

| # | Question | Blocks | Settled by |
|---|---|---|---|
| **OQ-1** | Reversal time window value | Production reversal execution | User policy |
| **OQ-2** | Reversal fee policy | Production reversal execution | User policy |
| **OQ-3** | Higher-value reversal approval threshold | Production reversal execution | User policy |
| **OQ-4** | Per-transaction ceiling, daily cap, regulatory ceiling | Production real-money transfers | User / legal counsel |
| **OQ-5** | Lease TTL and Reaper interval value | Reaper tuning | Config default (30s recommended) |
| **OQ-6** | Ledger genesis literal; audit genesis literal | Chain bootstrap | User / team |
| **OQ-7** | Exact canonicalization rules and cross-language SHA-256 test vectors | **Any hashing code** | Team — contract + vectors |
| **OQ-14** | Phase 2 marketplace vs the 1:1 wallet/account mapping | Phase 2 architecture | Deferred by H-4 |
| **OQ-15** | KYC admin: first-class role, permission, or operations role | Authorization implementation | User / team |
| **OQ-16** | Dispute-review queue: in-system or outside the system | Dispute scope | User / team |

### 5.2 Resolved here, but **require user confirmation**

*These are spine decisions (A7, A8, A11, A15) that answer OQ-8, OQ-9, OQ-10, OQ-11, OQ-13. They
are implementation-level choices the requirement layer left open. They are recorded as ⚙️
Architecture defaults, not approvals.*

> **Reconciled 2026-10-26.** The initiative ticket's §7 read each of these against
> `P2P_SPECIFICATION.md` and the decision register, and formed one recommended financially-safe
> resolution for each. The outcomes are in the table below and in
> `tickets/epic-ledger-core/approval-checklist-ledger-core.md`. **None of them blocks Epic 1.**
> **A11 was found to be spec-conformant rather than disputed** — see the note under the table.

| Spine id | Resolves | Decision | Reconciles to | Confirm? |
|---|---|---|---|---|
| **A7** | OQ-8, OQ-9 | The reversal owns its own `transaction_id`; `reversals.original_transaction_id` is UNIQUE; `REVERSED` is derived from the existence of a `reversals` row | **Recommended — keep.** SPEC §6.4 + §10.2 together settle it: reusing the original id would make the spec's own Invariant 1 fail on every successful reversal. | ☐ |
| **A8** | OQ-10 | Audit intent is written in the same transaction (outbox); the audit chain is appended asynchronously by a worker; the ledger never blocks on the audit sink | **Recommended — keep.** The audit *intent* commits with the money; the residual commit-to-drain window is bounded and monitored. | ☐ |
| **A11** | OQ-13 | The `POSTED` status write is outside the chain-close ACID unit and after the ack; a stale projection is corrected by reconciliation because the ledger wins | **Recommended — keep, and this is SPEC §7.4 verbatim.** No longer a disputed choice. | ☐ |
| **A15** | OQ-11 | The audit chain is a separate **table** in the same schema, with its own genesis and canonicalization | **Recommended — keep**, subject to H-8's separate-canonical-form default. | ☐ |

**A11's reclassification.** The spine originally flagged A11 as a user decision because OQ-13 was
framed as open. Reading SPEC §7.4 shows the specification *already* orders
`COMMIT` → `ACK to broker` → `UPDATE transactions SET status = 'POSTED'`. **A11 is the
specification's own ordering, not a deviation from it.** This is a finding about the spec, not an
approval of the spine, and it frees an approval slot — but the consequence (a crash leaving a
chain-closed entry with a stale `CHAIN_PENDING` projection) remains a thing the user should accept
explicitly, because SPEC §4.3 makes it tolerable only because the projection is rebuildable.

**A consequence of A11 that this spine did not previously state.** Because the `POSTED` write is
outside the chain-close transaction, finality is evidenced *only* by `chain_close_records`. The
reconciler must therefore derive chain-closedness from the **absence of a close record**, never
from `transactions.status` — which requires `chain_close_records` to be queryable by `entry_id`
alone, not only by `(transaction_id, entry_id)`.

### 5.3 Spine choices that are new, and need sign-off

| Spine id | Choice | Why it needs a look |
|---|---|---|
| **A3** | `ledger_accounts.owner_user_id` is nullable and `account_type` distinguishes USER from SYSTEM — designed for H-1, which is 🟠 proposed | If H-1 is rejected, this becomes `NOT NULL` and the column pair collapses. Cheap now, a migration later. |
| **A5** | `ledger_entries` has **no `status` column** — adopts H-6's elaboration, which is 🟠 proposed | This contradicts the specification's §3.1 and §9.4 literally. The spine argues it is *better* (§5 of A5), but the requirement is not approved. |
| **A-019** | `ledger_entries.entry_id` is a `CHAR(26)` ULID, not an auto-increment | Prevents the id sequence from leaking transaction volume through the hashed body. |
| **A5** | Append-only enforced by *privilege revocation* + triggers | The privilege model is an infrastructure concern, not just a migration. |
| **A6** | `next_sequence_number` lives on `ledger_accounts` | It is a sequence allocator, not a balance — worth confirming nobody reads it as one. |
| **A16** | The closer's singleton-ness is enforced by a leader lease + startup assertion + conditional update | SPEC §13.1 says failure 11 has *no runtime defence*. This adds one. Worth confirming the lease mechanism's choice. |
| **A18** | "Reconciliation partitioning by year" is read as a **scan filter**, not table partitioning | The spec's phrase is ambiguous. This reading preserves A17's deferral. |

### 5.4 Still at proposal stage — carried unchanged

H-1 (SYSTEM accounts) · H-6 (chain-close records as finality evidence) · M-1 (defer
partitioning) · M-4 (tiered idempotency retention) · C-3 (business-limit mechanism) ·
C-4, H-3, H-7, H-8, M-5, M-8 (unconfirmed technical defaults) · M-6 (cold archive, deferred) ·
M-7 (withdrawn).

**None of these is promoted by this spine.**

> **H-6 reconciled 2026-10-26 (D1).** The spine's A5 adopts H-6's Option B — `ledger_entries` has
> **no `status` column**. That is **🟠 Proposed (not approved)**, and it is worth recording *why* it
> is the recommended shape, because the specification is **self-contradictory** on this point:
> SPEC §4.2 and §14.1 say entries are *never updated* and that *no `UPDATE` statement exists
> against `ledger_entries`*, while SPEC §3.1, §7.3 and §9.4 rely on a `status` column — and
> SPEC §5.1 lists `status` **inside the hashed body**. A mutable column inside a hash is a
> contradiction: either the hash changes on every status transition (rewriting history, forbidden
> by §4.2) or the chain is broken.
>
> The sweeper's function and its 30s TTL are preserved under A5 — only the *predicate* changes,
> from "a column says so" to "a close record is absent", which cannot disagree with reality:
>
> ```sql
> SELECT e.* FROM ledger_entries e
>   LEFT JOIN chain_close_records c ON c.entry_id = e.entry_id
>  WHERE c.entry_id IS NULL
>    AND e.created_at < now() - INTERVAL 30 SECOND;
> ```
>
> **If the user rejects H-6, `status` returns to `ledger_entries` per SPEC §3.1 and the hashed-body
> field list follows the spec.** Neither outcome blocks an Epic 1 ticket: the migration is written
> to accommodate both shapes.

---

## 6. What this spine does **not** cover

- **UX.** No design system, screen flows, or interaction specs. `bmad-ux` has not been run.
  The React/Inertia surface is unspecified beyond "a peer never sees another balance".
- **API contract.** The HTTP surface, request/response shapes, error taxonomy, and the `409`
  idempotency-fingerprint response are not specified here. `ACCEPTED` vs `POSTED` semantics are
  specified by SPEC §7.5 and carried into R13/R15.
- **Queue and broker technology.** SPEC §2.1 names a Message Queue partitioned by `account_id`
  but does not name a broker. The spine's design assumes at-least-once delivery and
  commit-then-ack, which any standard broker provides. **Choosing the broker is not done.**
- **Deployment topology and hosting.** The closer must be a single instance; where it runs, how
  the leader lease is backed, and the infrastructure for a <1ms close commit are not decided.
- **Cold archive** (M-6) and **partitioning** (M-1) — deferred.
- **Dispute-review workflow and data model** (OQ-16) — the reversal records the shortfall and the
  rejection, but the queue that works those cases is not designed.
- **Marketplace** — Phase 2, not deleted (H-4).
- **Performance test plan** — R27 requires load-testing the closer before building the wallet
  path; the test plan itself is not written.

---

## 7. Readiness

| Question | Answer |
|---|---|
| Can the ERD be turned into migrations? | **Yes**, for everything except the two genesis literals (OQ-6). Migrations can be written with the genesis value as a parameterised seed. |
| Can the hashing code be written? | **No.** OQ-7 blocks it. The *tables* are unblocked; `canonical_json()` and the verifier are not. |
| Can the transfer flow be built? | **Yes**, with M-2/§3.1's `status` column absent (A5, H-6 🟠 proposed) and with the reservation design (A9). |
| Can the reversal be built? | **Yes**, with A7 confirmed. |
| Can production real-money transfers be enabled? | **No.** OQ-4 is open. R29 requires it. |
| Can production reversals be enabled? | **No.** OQ-1, OQ-2, OQ-3 are open. R22 requires it. |
| Is the marketplace in Phase 1? | **No.** H-4 defers it to Phase 2. |

---

*Architecture spine and MySQL data model prepared by the `bmad-architecture` workflow from the
initiative ticket. No specification file, application code, database schema, environment file, or
test configuration was modified. No migrations, tests, commits, or pushes were performed. The
development database was queried read-only (`SELECT VERSION()`, `SHOW GRANTS`,
`information_schema.tables`) and remains empty.*
