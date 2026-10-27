# Proposal

## Why

BMAD Epic 1 story 1 (`ledger-schema-foundation`) is built: `ledger_accounts` and `wallets` exist with
their constraints proven against a trigger-capable MySQL instance. But the system still has **no ledger**.
`wallets.balance_minor` is a projection with nothing behind it; `ledger_accounts.next_sequence_number` is
an allocator with nothing to allocate for. Every guarantee the specification names — the double-entry
invariant (SPEC §4.1), the append-only guarantee (SPEC §4.2), the chain (SPEC §5), balance reconstruction
from entries alone (SPEC §10.3) — is currently unbacked.

Story 2 creates `ledger_entries`: the append-only table that the money is actually derived from. It is
deliberately the **shape only**. It creates the table, its constraints, its indexes, and its Eloquent model.
It does **not** write entries, does **not** compute a hash, does **not** post a transfer.

**Why this shape and not another.** The specification is the authority on *what a ledger entry means*
(SPEC §3.1, §4.1, §4.2, §4.4, §5.1, §5.2). The architecture spine is the authority on *how it is typed*
(`_bmad-output/initiative-p2p-settlement/architecture-p2p-settlement/architecture-p2p-settlement.md`
§"the MySQL 8.0 data model"). The spine is explicitly **"a companion, not an approval"**, so every
architectural choice it carries into this change is marked PENDING below.

**Why now.** Stories 3 (append-only triggers), 4 (sequence allocator), 5 (chain head and close records),
6 (models and scopes) and 9 (invariants and verifier) all reference `ledger_entries`. Fixing the table shape
first means every one of them inherits a reviewed contract. Story 3 in particular needs the table to exist
before it can install the `BEFORE UPDATE` / `BEFORE DELETE` triggers that make append-only real rather
than merely intended.

## What Changes

- **Add a `ledger_entries` table** — the append-only double-entry ledger. Carries `entry_id`
  (`CHAR(26)`, a ULID), `transaction_id`, `account_id`, `direction` (`DEBIT`/`CREDIT`), `amount_minor`,
  `currency`, `account_sequence_number`, `balance_after` (nullable), `prev_hash`, `entry_hash` and
  `created_at`.
- **Make the gapless per-account sequence a data-layer fact** via the unique index
  `uq_ledger_entries_account_sequence (account_id, account_sequence_number)` (A6/G2, R8, AC3). Two entries
  for the same account can never share a sequence number — the database refuses the second one.
- **Make a zero-or-negative amount physically unrepresentable** via `BIGINT UNSIGNED` plus
  `ck_ledger_entries_amount CHECK (amount_minor > 0)` (A2, R5). A zero-amount entry is not a
  degenerate transfer, it is an accounting error, and the database rejects it.
- **Keep `balance_after` out of the hash's guarantee** — it is nullable, non-negative, and explicitly
  **not** part of the chained body (SPEC §5.2). The schema makes it optional rather than authoritative,
  so nothing can come to depend on a number no verifier can recompute.
- **Make a duplicated hash a hard error** via `uq_ledger_entries_entry_hash (entry_hash)` — a collision or
  a duplicated insert is a bug worth failing loudly on.
- **Support the two reconciliation scans** with `ix_ledger_entries_transaction (transaction_id, direction)`
  (Invariant 1) and `ix_ledger_entries_account_created (account_id, created_at)` (Invariant 3 / A18).
- **Add an `Eloquent` model** with the direction as a backed enum and the money columns cast through the
  existing `IntegerPoisha` cast, so a decimal string can never be silently truncated on the money path.
- **Accommodate H-6 without a migration** by keeping the `status` column in a **separate, reversible
  migration** that is **not** applied by this change. **H-6 itself remains 🟠 Proposed (not approved) and is
  NOT decided by this change.**

### PENDING approval — carried, not assumed

Every item below is an **unapproved** decision this proposal depends on. Each is marked PENDING in the
artifact that relies on it. None is treated as settled.

| Item | Decision | Status | Effect on this change |
|---|---|---|---|
| **H-6 / D1** | Whether `ledger_entries` carries a `status` column | 🟠 **Proposed (not approved)** | The **table migration omits** `status`, following the spine's A5. The column is delivered as a **separate, reversible migration that this change does not apply**, so either answer costs one migration and no data rewrite. The hashed-body field list is **not** finalised here — see M-2. |
| **G1 / A-019** | `entry_id` is a `CHAR(26)` ULID, not an auto-increment | ⚙️ **Architecture default — PENDING** | The column is created as `CHAR(26)`. If G1 is signed off differently, a later migration changes it. The *reason* for the choice (the id must not leak transfer volume through the hashed body, SPEC §5.1) is recorded either way. |
| **A5 / G4** | Append-only is enforced by privilege revocation **and** triggers | ⚙️ **Architecture default — PENDING** | This change creates the **table**. The trigger half is story 3 and the privilege half is an infrastructure task. This change adds neither, and does not claim append-only is enforced. |
| **A6 / G2** | `next_sequence_number` lives on `ledger_accounts` | ⚙️ **Architecture default — PENDING** | Already implemented in story 1; recorded here because the per-account sequence's *uniqueness* is enforced by this change's index. |
| **A2** | Money is `BIGINT UNSIGNED` in integer Poisha | ⚙️ **Architecture default** | `amount_minor` and `balance_after` follow it. |
| **M-8** | Currency is `CHAR(3)` ISO-4217, default `'BDT'` | ✅ **Technical default (unconfirmed)** | The column is `CHAR(3)` with `ck_ledger_entries_currency`. |
| **A-019 collation** | `CHAR(26)` for a ULID needs a binary/ASCII collation | ⚙️ **Not fixed by the spine** | The column uses the table's `utf8mb4` charset, which is **case-insensitive and therefore not ULID-safe** as written. This change records the hazard explicitly and leaves the collation decision open rather than silently choosing one. |

**Explicitly NOT relied upon:** the hash computation (blocked by **M-2** ⚪ BLOCKED), the genesis literal
(blocked by **M-3** ⚪ OPEN), the chain head and chain-close records (story 5), the append-only triggers
(story 3), the sequence allocator logic (story 4), the `transactions` table, `chain_close_records`, the
audit outbox, transfer execution, reversal, reconciliation, the marketplace (H-4, Phase 2), and any
production enablement.

## Capabilities

### New Capabilities

- `ledger/ledger-entries`: The append-only double-entry ledger table — its identity, direction, integer-Poisha
  amounts, the gapless per-account sequence, the chain-linkage columns, and the constraints that make each
  of those properties true at the data layer.

### Modified Capabilities

_None._ `ledger/accounts-and-wallets` is read but not modified: `ledger_entries.account_id` references
`ledger_accounts.id`, and `ledger_entries.transaction_id` references `transactions.id` — a table that does
not yet exist (Epic 3). See "Impact" for how that is handled.

## Impact

**New database objects (MySQL 8.0, InnoDB, `utf8mb4`, UTC):**

- `ledger_entries`
- One **prepared but not applied** migration for the optional `status` column (see H-6 above)

**New Laravel objects:**

- `app/Models/LedgerEntry.php`
- `app/Enums/LedgerDirection.php`
- `database/migrations/2026_10_26_000003_create_ledger_entries_table.php`

**Existing objects read but not modified:** `ledger_accounts` (referenced by `ledger_entries.account_id`).

**The `transactions` foreign key.** The spine's DDL places `fk_ledger_entries_transaction` on
`transactions(id)`, but the `transactions` table is **Epic 3**, not Epic 1. A foreign key to a table that
does not exist cannot be created. This change therefore creates `ledger_entries` **without** that foreign
key and records the omission explicitly, with the exact migration that adds it in Epic 3. The omission is
**not** silent and **not** presented as a design choice.

**Out of scope:** append-only triggers, the hash computation and its canonicalization (M-2), the genesis
literal (M-3), the chain head and chain-close records, the sequence allocator, the `transactions` table,
reservations-as-records, idempotency, transfer execution, reversal, reconciliation, the audit chain, the
marketplace (H-4, Phase 2), and any production enablement.

**Not authorised by this change:** running the migration against a live database, changing `.env`,
committing, or pushing.
