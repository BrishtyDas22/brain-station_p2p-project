# Design

## Context

BMAD Epic 1 story 2 creates `ledger_entries` — the append-only double-entry ledger table. Story 1
(`ledger-schema-foundation`) is built and verified: `ledger_accounts` and `wallets` exist, their
constraints are proven against the isolated trigger-capable MySQL instance on port 3307, and the
wallet↔account immutability invariant is enforced by the database.

This change is the **shape only**. It creates the table, its constraints, its indexes and its model. It
writes no entries, computes no hash, and posts no transfer.

**Sources of truth, and their standing:**

| Source | Standing | What it fixes here |
|---|---|---|
| `../P2P_SPECIFICATION.md` | **Authoritative** | What a ledger entry *means* — §3.1 entity list, §4.1 double-entry, §4.2 append-only, §4.4 per-account sequence, §5.1 chained body, §5.2 exclusions |
| `architecture-p2p-settlement.md` | **A companion, not an approval** | The MySQL 8.0 *types* — the `CREATE TABLE` this change transcribes |
| `approval-checklist-ledger-core.md` | Decision status authority | Which choices are PENDING |
| `P2P_SPECIFICATION.md` §5.1 | Authoritative but **incomplete** | The chained body's *field list* is named; its *byte-level* canonicalization is **M-2, ⚪ BLOCKED** |

## Goals / Non-Goals

**Goals**

- `ledger_entries` exists with the spine's shape, transcribed faithfully.
- Every property the spine says the table guarantees is guaranteed **by the database**, not by application
  code: gapless per-account sequence, positive amount, single currency, unique hash.
- The `status` question (H-6) is **not decided** by this change, and reversing it costs one migration.
- The money path cannot silently truncate a decimal.

**Non-Goals**

- Writing an entry. No posting path, no transaction, no transfer.
- Computing a hash. **M-2 is ⚪ BLOCKED** — canonicalization is unspecified, so `entry_hash` is stored as a
  `CHAR(64)` and never computed here.
- The genesis literal. **M-3 is ⚪ OPEN.**
- Append-only enforcement. Story 3 adds the triggers; G4's privilege revocation is infrastructure.
- The `transactions` table, chain head, close records, reservations, idempotency, reversal, reconciliation,
  audit chain, marketplace (H-4, Phase 2), production enablement.

## The DDL

Transcribed from `architecture-p2p-settlement.md` §"the MySQL 8.0 data model", with three deviations, each
recorded below and in `tasks.md`.

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
  CONSTRAINT fk_ledger_entries_account
    FOREIGN KEY (account_id)    REFERENCES ledger_accounts(id),
  CONSTRAINT ck_ledger_entries_amount   CHECK (amount_minor > 0),
  CONSTRAINT ck_ledger_entries_currency  CHECK (currency = 'BDT'),
  CONSTRAINT ck_ledger_entries_balance   CHECK (balance_after IS NULL OR balance_after >= 0)
) ENGINE=InnoDB;
```

### Deviation 1 — no `status` column, and no migration that adds it

The spine's DDL has no `status` column (its A5, adopting H-6's Option B). **H-6 is 🟠 Proposed (not
approved).** The spine's own reasoning notes the asymmetry:

> The **absence** of a column is the cheaper error to reverse later than the presence of one, because a later
> migration can add a nullable column but adding a column that was supposed to be in the hashed body changes
> every historical hash.

So the table omits `status`, and a **separate, reversible migration** carries the column. That migration is
**prepared but not applied** by this change, and is listed in `tasks.md` as a task that is *not* ticked,
because applying it would decide H-6 by implementation — which is exactly what "not approved" forbids.

**What this does and does not decide.** The table's *shape* is fixed by this change. The *hashed-body field
list* is **not** — that is M-2's job and M-2 is ⚪ BLOCKED. Nothing here computes or stores a hash derived
from a field list, so nothing here forecloses either answer.

### Deviation 2 — the `transactions` foreign key is deferred

The spine's DDL has `fk_ledger_entries_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id)`.
The `transactions` table is **Epic 3** and does not exist. MySQL cannot create a foreign key to a
non-existent table, so the constraint cannot be created now.

The column `transaction_id` is created exactly as specified (`BIGINT UNSIGNED NOT NULL`) and indexed
(`ix_ledger_entries_transaction`), so the FK can be added in Epic 3 as a pure constraint addition with no
data movement. `tasks.md` records the omission and names the exact migration that closes it.

**This is an omission, not a design choice, and it is not silent.**

### Deviation 3 — `entry_id`'s collation is left as the table default, and the hazard is recorded

The spine says `entry_id` is a **ULID** in a `CHAR(26)`. A ULID's canonical encoding is Crockford Base32,
which is **case-sensitive**: `01H…` and `01h…` are different ULIDs. MySQL's default collation
(`utf8mb4_0900_ai_ci`) is **case-insensitive**, so under it two distinct ULIDs differing only in case would
collide on the primary key — and a lookup would match the wrong row.

The spine does not fix a collation, so this change does not silently pick one. It:

- creates the column with the table's default collation, matching the spine verbatim;
- **records the hazard** in `tasks.md`, in the migration's own comment, and in `docs/ledger-schema.md`;
- leaves the decision **open** for a later, reversible `ALTER TABLE … CONVERT TO CHARACTER SET ascii
  COLLATE ascii_bin`, which is the conventional ULID collation and the obvious candidate.

The hazard is currently *latent*, not live: nothing generates ULIDs yet, and no entries exist. It becomes
live the moment ULID generation lands — which is story 4's sequence allocator or the posting path, both
later. Recording it here is what makes it fixable before it matters.

### Why `balance_after` is nullable and unguarded

SPEC §5.2 excludes `balance_after` from the hashed body because it is **not independently verifiable** —
"nobody can recompute it under concurrency". The schema makes it *optional* rather than authoritative: it
may be `NULL` when it is not known, and `ck_ledger_entries_balance` only forbids a *negative* value. Nothing
in the schema invites a reader to treat it as a source of truth; the ledger is.

## Data-layer enforcement for every invariant

| Invariant | Enforced by | Where |
|---|---|---|
| The ledger is append-only | **Story 3's triggers** (`BEFORE UPDATE` / `BEFORE DELETE`) + G4's privilege revocation | *Not this change.* Recorded as not-yet-enforced. |
| Exactly one DEBIT and one CREDIT per `transaction_id`, equal magnitude | Nightly reconciliation (SPEC §10) | Not a single-row constraint; cannot be a CHECK. Not this change. |
| Gapless per-account sequence | `uq_ledger_entries_account_sequence (account_id, account_sequence_number)` — **UNIQUE** | This change |
| A duplicate hash is a bug | `uq_ledger_entries_entry_hash (entry_hash)` — **UNIQUE** | This change |
| An amount is strictly positive | `ck_ledger_entries_amount CHECK (amount_minor > 0)` + `BIGINT UNSIGNED` | This change |
| A single currency | `ck_ledger_entries_currency CHECK (currency = 'BDT')` | This change |
| `balance_after` is never negative | `ck_ledger_entries_balance CHECK (balance_after IS NULL OR balance_after >= 0)` + `BIGINT UNSIGNED` | This change |
| No float money | `amount_minor` / `balance_after` are `BIGINT UNSIGNED`; the model casts through `IntegerPoisha` | This change |
| An entry belongs to a real account | `fk_ledger_entries_account → ledger_accounts(id)` | This change |
| An entry belongs to a real transaction | `fk_ledger_entries_transaction → transactions(id)` | **Epic 3** — column and index only, here |

**`ON DELETE` / `ON UPDATE`:** like story 1, **no referential action** is declared on any foreign key. The
base grammar emits none unless one is set, and none is set here. The `ledger_entries` table is append-only —
cascading a delete from a parent would silently destroy ledger history, which is the one thing the table
exists to prevent.

## The model

`app/Models/LedgerEntry.php`:

- `direction` → `LedgerDirection::class` (a backed string enum, `DEBIT`/`CREDIT`).
- `amount_minor`, `balance_after` → `IntegerPoisha::class` — the existing custom cast from story 1, which
  **throws** `InvalidArgumentException` on a decimal string instead of silently truncating `'12.34'` to `12`.
  The built-in `int` cast would lose money on the money path.
- `protected $guarded = ['*']` with `protected $fillable = ['id']` — the same pattern story 1 uses: every
  column is guarded except the primary key. A ledger entry is written by a controlled path, never mass-
  assigned from a request.
- `belongsTo(LedgerAccount::class)` — the FK is `ledger_entries.account_id`.
- `hasMany` / `belongsTo` on `transaction_id` is **not** added: the `transactions` table does not exist yet.

`balance_after` is nullable, so its cast must tolerate `null`. `IntegerPoisha::get()` returns `(int)` of the
stored value — `(int) null` is `0`, which would silently turn "unknown" into "zero". The cast therefore
returns `null` for a `null` attribute rather than `0`.

## The `status` migration (prepared, NOT applied)

A separate file, `database/migrations/2026_10_26_000004_add_status_to_ledger_entries_table.php`, holds the
`status` column. It is **not applied** by this change: the `down()` of the table migration would have to
drop it, and applying it would decide H-6.

**Why it exists at all.** Because H-6 is 🟠 Proposed, a reader of the schema needs to see *both* shapes and
know which one is live. One reversible migration makes the reversal a single `migrate` step rather than a
redesign.

## Alternatives considered

**A single migration with `status`, matching SPEC §3.1 literally.** Rejected. It decides H-6 by
implementation, and — worse — the SPEC lists `status` **inside the hashed body** (§5.1). Adding a column
later that was supposed to be hashed changes every historical hash. Absence is the cheaper error to reverse.

**Two table migrations, one per shape, both applied.** Rejected. It applies H-6 twice.

**No `status` migration prepared at all.** Rejected. It leaves the reversal costing a redesign instead of a
`migrate`.

**A `CHECK` enforcing "exactly one DEBIT and one CREDIT per transaction".** Rejected. MySQL's `CHECK`
constraints cannot reference other rows; the double-entry invariant is a *set* property, provable only by a
reconciliation scan (SPEC §10). It is not expressible as a row constraint.

**A composite foreign key `(transaction_id, direction)` to enforce the pair.** Rejected. It would require a
`transactions` table with a matching unique key, which does not exist, and it would enforce the wrong
direction anyway.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| The `transactions` FK is missing, so an entry can name a transaction that does not exist | Recorded as an explicit, named omission. Epic 3 adds it as a constraint-only migration. Until then no entries are written by any path. |
| `entry_id`'s case-insensitive collation is ULID-unsafe | Recorded explicitly, with the conventional fix named. Latent, not live — no ULIDs are generated yet. |
| The hashed-body field list is not fixed | Deliberate. **M-2 is ⚪ BLOCKED.** This change computes no hash, so it forecloses nothing. |
| `status` is absent from the table | Deliberate. **H-6 is 🟠 Proposed.** A prepared, reversible migration carries it. |
| Append-only is not yet enforced | **Story 3** adds the triggers; G4's privilege half is infrastructure. This change does not claim the guarantee holds. |

## Migration Plan

1. `create_ledger_entries_table` — the table, its constraints and its indexes.
2. `add_status_to_ledger_entries_table` — **prepared, NOT applied.**

**Rollback:** `down()` drops the table. The CHECK constraints, the foreign key and the indexes go with it.
Verified against the isolated test instance on port 3307, leaving no table and no orphaned index behind.

## Open Questions

| Question | Status | Where it is tracked |
|---|---|---|
| Does `ledger_entries` carry a `status` column? (H-6 / D1) | 🟠 **Proposed (not approved)** | `approval-checklist-ledger-core.md` §A1; the prepared migration |
| What is the chained body's byte-level canonicalization? (M-2) | ⚪ **BLOCKED** | `initiative-p2p-settlement.md` §7.6 / OQ-7 |
| What is the genesis literal? (M-3) | ⚪ **Open** | `architecture-p2p-settlement.md` §5.4 |
| Is `entry_id` a ULID? (G1 / A-019) | ⚙️ **Architecture default — PENDING** | `approval-checklist-ledger-core.md` §B |
| What collation does `entry_id` use? | ⚪ **Not fixed by the spine** | This document, Deviation 3 |
| Is append-only enforced by privilege revocation + triggers? (G4) | ⚙️ **Architecture default — PENDING** | `approval-checklist-ledger-core.md` §B |
