# Design

## Context

See `proposal.md` — Why, for the motivation. This section records only the current state and
constraints that shape the approach.

**Current state.** The Laravel 13 starter kit has a `users` table (plus cache, jobs, passkeys and
two-factor columns) and one Eloquent model. The development database `p2p_platform_dev` has
**zero tables**. There is no ledger schema of any kind.

**Constraints that shape the design:**

- **MySQL 8.0.46, InnoDB**, `sql_mode` includes `STRICT_TRANS_TABLES`. Verified against the
  running server, not assumed.
- **`CHECK` constraints are enforced natively** (MySQL 8.0.16+). The app role holds
  `ALL PRIVILEGES ON p2p_platform_dev.*`, so CHECK DDL is buildable.
- **`users.id` is `BIGINT UNSIGNED`** — the starter kit's `users` table. This fixes the FK column
  types for `owner_user_id`.
- **Laravel 13 / PHP 8.3.** Migrations are the Laravel schema builder; raw DDL is used only for
  `CHECK` constraints and triggers the builder cannot express.
- **The specification is authoritative and unmodified.** `../P2P_SPECIFICATION.md` §3.1 defines the
  `Wallet` entity and §1.3/§1.4 fix Phase 1 to a single currency (BDT).

**Two structural facts from the spine that this design must honour** (see
`_bmad-output/initiative-p2p-settlement/architecture-p2p-settlement/architecture-p2p-settlement.md`
§2.2):

- `account_id` is inside the hashed body of every ledger entry (C-2), so the column is **permanent
  in name and binding**. There is no `ON UPDATE CASCADE` and no `ON DELETE CASCADE` anywhere in
  this schema.
- The ledger account carries **no balance column**. The balance lives only on `wallets` (C-2
  forbids a second independently mutable balance field on the account).

## Goals / Non-Goals

**Goals:**

- Two tables that make the Epic 1 financial invariants true **at the data layer**, so that a
  migration, an operator script, or a future service cannot violate them (SPEC §6.2).
- A schema that accommodates H-1 (SYSTEM accounts) **without a migration**, so that approving H-1
  later does not touch the hashed-body contract.
- Constraints named and stable, because later stories and the reconciliation engine reference them
  by name.
- A model layer that cannot represent a negative balance, a float balance, or a wallet without an
  account.

**Non-Goals:**

- The ledger entry table, the hash chain, the chain head, the chain-close record — Epic 1 stories
  2, 3, 5.
- The per-account sequence *allocation mechanism* (the conditional increment and the lock order) —
  Epic 1 story 4. This change creates the counter column it will use; it does not implement the
  allocator.
- The `reservations` table and the reservation lifecycle — Epic 1 story 4 / Epic 3. This change
  creates only the `reserved_minor` cache column and its ceiling constraint.
- The API, controllers, and request/response shapes. Epic 1 is a substrate change; the read path
  is Epic 2 (authorization) and Epic 3 (transfer execution).
- Any SYSTEM account, any funding path, any transfer, any production enablement.

## Decisions

### D-1 — Money columns are `BIGINT UNSIGNED`

**Decision.** `balance_minor`, `reserved_minor`, and `next_sequence_number` are
`BIGINT UNSIGNED NOT NULL`.

**Rationale.** Under `STRICT_TRANS_TABLES`, MySQL rejects an out-of-range value at write time. An
`UNSIGNED BIGINT` therefore makes a negative balance **physically unrepresentable**, not merely
forbidden by application logic. A `BIGINT` (signed) column would let a negative value sit in the
table if any writer bypassed the service layer — the reconciliation engine would have to detect it
nightly instead of it being impossible.

**Alternatives considered.** `DECIMAL(18,2)` — rejected: it reintroduces a fractional type into a
system where the specification explicitly forbids decimal and float arithmetic in the money path
(SPEC §3.1), and it hides the integer-minor-unit contract. `BIGINT` signed with a CHECK — rejected:
a CHECK on `balance_minor >= 0` catches it, but only if the column type permits the value to
arrive; `UNSIGNED` makes it impossible at the storage layer, which is the stronger guarantee and
the one the spine commits to (A2).

**Derived constraint.** No explicit `CHECK (balance_minor >= 0)` is needed — the column type is
the constraint. This is stated explicitly so a later reader does not add a redundant CHECK.

### D-2 — The 1:1 mapping is enforced by three unique indexes, not by application code

**Decision.**

```sql
UNIQUE KEY uq_ledger_accounts_owner   (owner_user_id)          -- one account per user
UNIQUE KEY uq_wallets_account         (account_id)             -- one wallet per account
UNIQUE KEY uq_wallets_owner_currency  (owner_user_id, currency) -- one wallet per user per currency
```

**Rationale.** These three together express the full mapping: a user owns at most one account, an
account backs at most one wallet, and a user holds at most one wallet per currency. Each is a
**database error** on violation, not a failed application check — which is what SPEC §6.2 requires
and what C-2's "enforced at the data layer" means.

**The nullable-column trick.** `uq_ledger_accounts_owner` is a unique index on a **nullable**
column. MySQL permits multiple `NULL` values in a unique index, so **multiple SYSTEM accounts are
allowed** while each user gets at most one account. This is the precise mechanism that makes
"one account per user" and "system accounts have no owner" both true simultaneously, without a
partial index (which MySQL does not support).

**Alternatives considered.** Application-level checks — rejected: bypassable by a migration or an
operator script. A separate `account_owners` join table — rejected: it adds a table and an
indirection for no gain, and would complicate the hashed-body contract that binds `account_id`
directly.

### D-3 — `owner_user_id` is nullable, and `account_type` carries the USER/SYSTEM split

**Decision.**

```sql
owner_user_id BIGINT UNSIGNED NULL,
account_type  ENUM('USER','SYSTEM') NOT NULL DEFAULT 'USER',
system_role   ENUM('FUNDING','TREASURY') NULL,

CONSTRAINT ck_ledger_accounts_type CHECK (
  (account_type = 'USER'   AND owner_user_id IS NOT NULL AND system_role IS NULL) OR
  (account_type = 'SYSTEM' AND owner_user_id IS NULL     AND system_role IS NOT NULL)
)
```

**Rationale.** This is the one place where a **🟠 Proposed (not approved)** decision shapes the
schema, and it is shaped deliberately so that *either* outcome of H-1 costs nothing now.

- If **H-1 is approved**, the schema already supports SYSTEM accounts. No migration.
- If **H-1 is rejected**, `owner_user_id` becomes `NOT NULL` and `account_type`/`system_role` are
  dropped — a later migration that touches no hashed field.

**What this change does NOT do.** It creates no SYSTEM account, no funding path, and no mechanism
that moves money from a system account. The `SYSTEM` enum value exists in the schema and is
unused.

**Alternatives considered.** `NOT NULL` owner now (rejecting H-1's shape) — rejected: it would
force a schema migration the moment H-1 is approved, and a migration touching `ledger_accounts`
is riskier before the ledger has data than after. Two separate tables (`user_accounts`,
`system_accounts`) — rejected: it would make `account_id` polymorphic, and `account_id` is inside
the hashed body of every entry, so a polymorphic reference is exactly the kind of ambiguity that
must not enter the hash.

### D-4 — `ck_wallets_reserved_within_balance` is a `CHECK`, because both columns share a row

**Decision.**

```sql
CONSTRAINT ck_wallets_reserved_within_balance CHECK (reserved_minor <= balance_minor)
```

**Rationale.** A MySQL CHECK may reference columns of its own row. `balance_minor` and
`reserved_minor` are both on `wallets`, so R4's invariant `0 <= reserved_minor <= balance_minor`
is **expressible** — the lower bound comes from the column type (D-1), the upper bound from this
CHECK.

Because the constraint is evaluated on **every** `UPDATE`, it also catches a transitional state
*during* a posting transaction. This imposes an ordering constraint on Epic 3's posting
transaction: the payer's `balance_minor` debit and its `reserved_minor` decrement must be
written so that no intermediate statement violates the constraint. That ordering is Epic 3's
problem, not this change's — but it is recorded here because it is a consequence of this
constraint.

**Alternatives considered.** Enforcing in the service layer only — rejected: bypassable.
A trigger instead of a CHECK — rejected: a CHECK is declarative, is evaluated by the optimizer, and
cannot be forgotten by a code path that issues a raw UPDATE.

### D-5 — The sequence counter lives on `ledger_accounts`, not on `wallets`

**Decision.** `ledger_accounts.next_sequence_number BIGINT UNSIGNED NOT NULL DEFAULT 1`.

**Rationale.** The counter is a **per-account sequence allocator**, not a balance. It lives on the
account because the sequence is per `account_id` — the account is what entries are sequenced
against. It lives on `wallets` only if the mapping were 1:1 in the *other* direction.

**It is not a second balance.** This is stated explicitly so a later reader does not mistake it
for one. It is reconciled by the **sequence-continuity** invariant (gapless per account), not by
Invariant 3's balance reconstruction. C-2's prohibition is on a second *independently mutable
balance* field; this is a monotonic allocator, and its mutability class is different.

**Alternatives considered.** `MAX(account_sequence_number) + 1` at allocation time — rejected:
not safe under concurrency; two transactions can read the same maximum and compute the same next
value. An auto-increment column — rejected: auto-increment is global, not per-account, so it
cannot produce the per-account sequence the specification requires (SPEC §4.4).

**Status.** ⚙️ **Architecture default A6 / G2 — PENDING sign-off.** The placement is a reversible
migration; the atomicity and collision-freedom requirement is not pending (Epic 1 story 4
implements the allocator).

### D-6 — Currency is `CHAR(3)` with a single-currency CHECK

**Decision.**

```sql
currency CHAR(3) NOT NULL DEFAULT 'BDT',
CONSTRAINT ck_wallets_currency      CHECK (currency = 'BDT')
CONSTRAINT ck_ledger_accounts_currency CHECK (currency = 'BDT')
```

**Rationale.** The column is ISO-4217-shaped so a future multi-currency version does not need a
type change, but Phase 1 is single-currency (SPEC §1.4, H-4), and the CHECK enforces that rather
than merely documenting it. A `CHAR(3)` with a CHECK is the honest shape: it says "this is a
currency code" while refusing values the system cannot yet honour.

**Status.** ✅ **Technical default (unconfirmed)** — M-8.

### D-7 — No `ON DELETE` or `ON UPDATE` cascade on any foreign key in this schema

**Decision.** `fk_wallets_owner`, `fk_wallets_account`, and `fk_ledger_accounts_owner` are declared
with **no referential action**. Deleting a wallet or an account that has history **fails**.

**Rationale.** A cascade would silently delete or re-point rows that ledger entries reference. The
ledger is append-only and the account identifier is inside the hashed body of every entry — a
cascade that re-points `wallets.account_id` would change the meaning of historical hashes.
Deletion must fail loudly and be handled by a policy decision, not performed automatically.

This is the same reasoning that later requires `BEFORE UPDATE`/`BEFORE DELETE` triggers on
`ledger_entries` (Epic 1 story 3); it is applied here so the two tables agree.

**Alternatives considered.** `ON DELETE RESTRICT` — rejected as equivalent-but-less-explicit; the
MySQL default (RESTRICT) is the same behaviour, and writing the omission is clearer than writing
the default. `ON DELETE SET NULL` — rejected: it would leave an orphan wallet with no account.

### D-8 — Timestamps use `DATETIME(6)`, not `TIMESTAMP`

**Decision.** `created_at` and `updated_at` are `DATETIME(6) NOT NULL`.

**Rationale.** The spine fixes `DATETIME(6)` (microsecond precision) for hashed timestamps, because
a hashed timestamp must be re-read **byte-identically** by the chain recovery sweeper (SPEC §9.4
warns explicitly against regenerating hashed fields). `TIMESTAMP` is stored in UTC and converted
on read, which is a conversion the sweeper must not perform; `DATETIME(6)` stored in UTC is
literal.

`TIMESTAMP` also has a hard range limit (2038) and a session-timezone dependency that makes
"write UTC" a convention rather than a property. This change stores UTC by convention — the
application's `timezone` setting — and `DATETIME(6)` makes that convention free of conversion
side-effects.

**Note on the hashing boundary.** These timestamps are **not** part of this change's hashed body —
that is the `ledger_entries` table (Epic 1 story 2), whose hashed field list depends on the D1
(H-6) outcome. Recording the reasoning here so the choice is consistent when that table is built.

### D-9 — `account_id` is `NOT NULL` and is not auto-derivable

**Decision.** `wallets.account_id BIGINT UNSIGNED NOT NULL` with a foreign key to
`ledger_accounts(id)`.

**Rationale.** C-2 requires the mapping to be **immutable**. A nullable `account_id` would allow a
wallet to exist with no account, and the ledger entries for that wallet would have no
`account_id` to hash. `NOT NULL` makes the binding structural.

The pair is created in one transaction: the account first, then the wallet referencing it. There is
no code path that creates a wallet without an account.

**Explicitly not assumed.** `wallet.id` and `account_id` are **not** assumed to have identical
numeric values (R9). The two are independent auto-increment sequences, and nothing in this
design relies on their values matching.

### D-10 — The models refuse to represent an invalid balance

**Decision.** The Eloquent models cast the money columns to integer (never float or decimal),
never expose a setter that accepts a float, and mark `balance_minor`/`reserved_minor` as
non-fillable from mass assignment except through an explicit guarded method.

**Rationale.** The database is the authority, but a model that silently accepts `"12.34"` and
casts it to `1234` is a latent source of rounding. The cast is `int`; a non-integer value is
rejected rather than truncated.

The read path additionally scopes wallet queries to the owning user, so a balance is not
retrievable by a peer even by direct query (R4, AC4 at the epic level).

## Risks / Trade-offs

**[Risk] H-1 is rejected later, leaving nullable owner columns in place.** → Mitigation: the
columns are exactly the ones H-1 would need, and the migration to make them `NOT NULL` touches no
hashed field and no ledger entry. The cost is one migration, not a redesign.

**[Risk] The `ck_wallets_reserved_within_balance` constraint constrains Epic 3's posting
transaction's write ordering.** → Mitigation: recorded in the design and carried into the Epic 3
ticket. The posting transaction must debit `balance_minor` and decrement `reserved_minor` so that
no intermediate statement violates the constraint. This is a known, bounded ordering constraint,
not a surprise.

**[Risk] `next_sequence_number` sits on `ledger_accounts` and could be misread as a balance.** →
Mitigation: the design, the spine's A6, and the Epic 1 ticket all state explicitly that it is an
allocation counter reconciled by sequence continuity, not by balance reconstruction. A `COMMENT` on
the column reinforces it at the storage layer.

**[Risk] The single-currency CHECK (`currency = 'BDT'`) blocks a future multi-currency version.**
→ Mitigation: intentional. Phase 1 is single-currency (SPEC §1.4, H-4). The column is
ISO-4217-shaped so the future change is a **dropped CHECK**, not a type migration.

**[Risk] The 1:1 unique indexes make multi-account-per-wallet impossible.** → Mitigation: this is
the requirement (R9 — multi-account-per-wallet is out of scope in this version). The error is
loud and explicit rather than a silent second balance source.

**[Risk] No cascade means deleting a user with history fails.** → Mitigation: intended. Data
retention and deletion policy is a legal/compliance decision (M-6 cold archive, ⏸️ deferred) and
must not be expressed as an automatic cascade in the meantime.

**[Trade-off] The `users` table is not altered here, so `ledger_accounts.owner_user_id` references
a `users` table without KYC columns.** → Mitigation: the KYC columns belong to BMAD Epic 2. This
change's FK to `users(id)` is valid today and remains valid after Epic 2 adds columns.

## Migration Plan

**Forward.** Two Laravel migrations in order:

1. `create_ledger_accounts_table` — includes the `ck_ledger_accounts_type` and
   `ck_ledger_accounts_currency` CHECK constraints via raw SQL appended to the blueprint.
2. `create_wallets_table` — includes `ck_wallets_reserved_within_balance` and
   `ck_wallets_currency`, and the three unique indexes.

**Ordering constraint.** `ledger_accounts` must exist before `wallets` because
`wallets.account_id` references it.

**Rollback.** `migrate:rollback` drops `wallets` first, then `ledger_accounts`. Both are empty at
first deployment, so rollback is lossless.

**Not run against a live database by this change.** The migration files are created and reviewed;
running them is the apply phase, which is explicitly out of scope for the proposal.

## Open Questions

None that affect this change's specs, approach, or task breakdown. The following are **deferred**
and none of them changes what is built here:

- **H-1** (SYSTEM accounts) — 🟠 Proposed (not approved). Affects whether the `SYSTEM` enum value
  and `system_role` are ever used. The schema accommodates both outcomes.
- **A6 / G2** (counter placement) — ⚙️ Architecture default, PENDING. A reversible migration.
- **C-4, M-8** (column name, currency representation) — ✅ Technical defaults, unconfirmed.
- **M-6** (cold archive and retention policy) — ⏸️ Deferred. Affects future deletion policy, not
  this schema.
