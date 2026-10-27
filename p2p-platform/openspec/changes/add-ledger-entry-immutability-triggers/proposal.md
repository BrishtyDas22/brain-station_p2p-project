# Change: add-ledger-entry-immutability-triggers

## Why

The `ledger_entries` table exists (BMAD story 2, `ledger-entry-table`) with its data-layer
constraints. But **append-only is not yet enforced.** As of story 2, a row in `ledger_entries`
can be updated or deleted by any role holding the privilege to do so — including a privileged
operator session, an operator script, or a future service. The unique indexes catch duplicate
sequence numbers and duplicate hashes; they do **not** catch a mutated row.

This is the single most consequential gap in the money substrate. SPEC §4.2 requires that Ledger
Entries are never updated and never deleted, and corrections are made only by appending
compensating entries. Right now that is a convention, not a guarantee.

The architecture spine settles the mechanism (A5, §"The append-only guarantee"): **three**
database-level mechanisms together, because application code alone can be bypassed:

1. **No `UPDATE`/`DELETE` privilege** for the runtime role on `ledger_entries`.
2. **`BEFORE UPDATE` and `BEFORE DELETE` triggers** that `SIGNAL SQLSTATE '45000'`.
3. **No `ON DELETE CASCADE` / `ON UPDATE CASCADE`** on any FK referencing `ledger_entries`.

**Mechanism 1 is G4 — an infrastructure task, not a migration.** It is out of scope for this
change and is recorded as BLOCKED. Mechanisms **2** and **3** are migration work, and they are
what this change builds.

Mechanism 2 is the belt to mechanism 1's braces: it covers a privileged operator session, which
privilege revocation alone cannot. Together they make the guarantee hold against every actor the
system can control.

## What Changes

- A new migration `add_ledger_entry_immutability_triggers` that installs two **mandatory,
  fail-closed** triggers on `ledger_entries`:
  - `trg_ledger_entries_no_update` — `BEFORE UPDATE`, `SIGNAL SQLSTATE '45000'`.
  - `trg_ledger_entries_no_delete` — `BEFORE DELETE`, `SIGNAL SQLSTATE '45000'`.
- Both are installed through `MySqlTriggerSupport::install()`, which creates the trigger and then
  **verifies** its presence in `information_schema.triggers`. If creation fails, or the server
  reports success but the trigger is absent, the migration aborts and the `ledger_entries` table
  is dropped — MySQL DDL is not transactional, so a half-built schema whose append-only guarantee
  is missing is worse than no schema.
- An explicit assertion that **no foreign key on `ledger_entries` carries a referential action**
  — mechanism 3 — proven against the live DDL rather than assumed.
- Focused tests proving each trigger **actually fires**, from a privileged session, and that the
  row is left unchanged after the rejection.
- Documentation recording what is now enforced and what is **still** outstanding (G4).

## PENDING approval — carried, not assumed

| Item | Status | Effect here |
|---|---|---|
| **G4** — runtime role holds `INSERT`/`SELECT` only, no `UPDATE`/`DELETE` | ⚙️ Architecture default, PENDING | **Not implemented.** It is an infrastructure task (a `GRANT` statement against a separate runtime role), not a migration. **Mechanism 1 is therefore NOT in place after this change.** The triggers (mechanism 2) are what this change delivers, and they cover the privileged-session case that a privilege grant cannot. |
| **H-6 / D1** — `ledger_entries.status` | 🟠 Proposed (not approved) | **Not touched.** The table still carries no `status` column. The prepared-but-unapplied migration in `database/migrations/pending/` is unaffected. |
| **A5** — append-only enforced by privilege revocation **plus** triggers **plus** no cascades | ⚙️ Architecture default | **Mechanisms 2 and 3 only.** Mechanism 1 is G4 and is blocked. This change implements the mechanisms the spine specifies, and does not claim the whole of A5. |
| **G1 / A-019** — `entry_id` is a `CHAR(26)` ULID | ⚙️ Architecture default, PENDING | **Not touched.** The trigger rejects every `UPDATE` regardless of the primary key's type. |
| **M-2** (canonicalization) / **M-3** (genesis literal) | ⚪ BLOCKED / ⚪ OPEN | **Not touched.** No hash is computed, verified or chained. The triggers are independent of the hash contract. |

## Capabilities

- **Append-only enforcement at the data layer** — `ledger_entries` rows can no longer be updated
  or deleted by *anyone* holding the privilege to attempt it, including a privileged session.

## Impact

- **Affected:** `ledger_entries` (two new triggers), the migration chain, `docs/ledger-schema.md`.
- **New files:** the migration, the trigger-support test class, the OpenSpec change artifacts.
- **Behaviour change:** any code path that issues an `UPDATE` or `DELETE` against
  `ledger_entries` will now fail. **No such path exists** — story 2 wrote no entry, and no
  transfer, reversal or reconciliation code exists yet. This is a tightening of a guarantee that
  was never actually enforced, so nothing that works today stops working.
- **Migration is fail-closed:** if the triggers cannot be installed, `ledger_entries` is dropped
  rather than left without its guarantee. This is deliberate, and it matches the wallet trigger's
  behaviour established in story 1.
- **Not affected:** `wallets`, `ledger_accounts`, the `transactions` table (Epic 3), the
  `chain_close_records` table (story 5), the sequence allocator (story 4).

## Out of scope

- **G4** — the privilege revocation. Infrastructure, not migration.
- The `chain_close_records` immutability triggers (story 5).
- The `transactions` and `reservations` triggers (Epic 3).
- The hash chain, the chain verifier, and the canonicalization rules (M-2) — **not started**.
- Any transfer, reversal or reconciliation logic.
