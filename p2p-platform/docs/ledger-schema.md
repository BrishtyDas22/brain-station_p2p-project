# Ledger schema — accounts, wallets and entries

The money substrate of the P2P settlement system. Three tables, their data-layer
constraints, and their Eloquent models.

**Scope.**

- BMAD Epic 1 (`ledger-core`), story 1 — `ledger-schema-foundation`.
  Implemented by OpenSpec change `add-ledger-schema-foundation`.
- BMAD Epic 1 (`ledger-core`), story 2 — `ledger-entry-table`.
  Implemented by OpenSpec change `add-ledger-entry-table`.
- BMAD Epic 1 (`ledger-core`), story 3 — `ledger-entry-immutability-triggers`.
  Implemented by OpenSpec change `add-ledger-entry-immutability-triggers`.

> **Approval ceiling.** Nothing described here is *finally* approved. The decisions this
> schema depends on are 🟡 Provisional, 🟠 Proposed (not approved), ⚪ Open, or ✅
> Technical default (unconfirmed). See the decision register at
> `_bmad-output/planning-artifacts/grill-me-decision-register.md`.

---

## The money substrate

- **`wallets` holds the only mutable balance in the system.** `balance_minor` and
  `reserved_minor` are `BIGINT UNSIGNED` integer Poisha (1 BDT = 100 Poisha). There is
  no float, `DECIMAL`, or fractional type anywhere in the money path.
- **`ledger_accounts` holds no balance column.** There is deliberately no
  `balance_minor` or `reserved_minor` on the account. A second independently mutable
  balance field on the account is forbidden, because it would create a second source
  of truth that the reconciliation engine would have to arbitrate between.
- **`ledger_accounts.next_sequence_number` is a per-account sequence allocator, not a
  balance.** It hands out the next `account_sequence_number` for an account. It is
  reconciled by **sequence continuity** (gapless per account), not by balance
  reconstruction. The column carries a `COMMENT` saying so at the storage layer.

### The invariants, and where each one is enforced

| Invariant | Enforced by |
|---|---|
| A balance is never negative | The `BIGINT UNSIGNED` column type. Under `STRICT_TRANS_TABLES` a negative value is rejected at write time. |
| `0 <= reserved_minor <= balance_minor` | `ck_wallets_reserved_within_balance` (a MySQL `CHECK`). |
| One account per wallet | `uq_wallets_account` (a unique index on `wallets.account_id`). |
| One account per user | `uq_ledger_accounts_owner` (a unique index on `ledger_accounts.owner_user_id`). |
| One wallet per user per currency | `uq_wallets_owner_currency`. |
| An account's type agrees with its owner | `ck_ledger_accounts_type` (a MySQL `CHECK`). |
| Phase 1 is single-currency (BDT) | `ck_wallets_currency`, `ck_ledger_accounts_currency`. |
| The wallet↔account binding is immutable | `trg_wallets_account_id_immutable` (a `BEFORE UPDATE` trigger), plus `NOT NULL` and the absence of any `ON UPDATE`/`ON DELETE` cascade. |
| Deleting an account with history fails loudly | The foreign keys carry **no** referential action, so MySQL's `RESTRICT` default applies. |
| A balance cannot be silently truncated | The `IntegerPoisha` Eloquent cast, which throws rather than rounding. |

### Reading a balance

`wallets` is readable only through its owner. The `Wallet::forOwner($ownerUserId)`
scope constrains the query, and the peer-read path is covered by a test.

### The reserved balance is a cache, not a source of truth

`reserved_minor` is a rebuildable projection of the active reservation records. The
ledger wins when the two disagree. It is not an independent source of truth, and
`balance_minor` is likewise a projection of the account's ledger entries.

---

## `ledger_entries` — the append-only double-entry ledger

`ledger_entries` holds the append-only double-entry ledger: the table the money is
actually **derived from**.

- **The entries are the authority.** `wallets.balance_minor` is a **projection** of the
  account's ledger entries and can be rebuilt from them. When a projection and the
  ledger disagree, **the ledger wins** (SPEC §4.3). The entries are not a record of
  what happened to a balance; they *are* the balance.
- **`balance_after` is explicitly NOT authoritative.** It is excluded from the chained
  body (SPEC §5.2) because nobody can recompute it under concurrency — it is not
  independently verifiable. It is kept for display and debugging only, it is nullable,
  and **a null balance stays null** — it never becomes a zero balance.
- **`ledger_accounts.next_sequence_number` is an allocator, not history.** The
  per-account gapless sequence is the ledger's ordering guarantee (SPEC §4.4); the
  column on the account only hands out the next number.

### The invariants, and where each one is enforced

| Invariant | Enforced by |
|---|---|
| An entry's amount is strictly positive | `ck_ledger_entries_amount` (a MySQL `CHECK`), plus the unsigned column type. A zero-amount entry is an accounting error, not a degenerate transfer. |
| Phase 1 is single-currency (BDT) | `ck_ledger_entries_currency` (a MySQL `CHECK`). |
| A balance is never negative | `ck_ledger_entries_balance` (a MySQL `CHECK`), plus the unsigned column type. |
| Two entries for one account never share a sequence number | `uq_ledger_entries_account_sequence` (a unique index on `(account_id, account_sequence_number)`). |
| Two entries never attest to the same content | `uq_ledger_entries_entry_hash` (a unique index on `entry_hash`). |
| An entry names a real ledger account | `fk_ledger_entries_account`, carrying **no** `ON DELETE` and **no** `ON UPDATE` action. |
| The double-entry scan is supported | `ix_ledger_entries_transaction`, over `(transaction_id, direction)`. |
| The per-account timeline and by-year reconciliation scan are supported | `ix_ledger_entries_account_created`, over `(account_id, created_at)`. |
| An entry is never updated | `trg_ledger_entries_no_update` (a `BEFORE UPDATE` trigger), which signals SQLSTATE `45000`. |
| An entry is never deleted | `trg_ledger_entries_no_delete` (a `BEFORE DELETE` trigger), which signals SQLSTATE `45000`. |
| The money path never truncates | The `IntegerPoisha` Eloquent cast, which throws rather than rounding. |

The gapless-per-account guarantee is **half-enforced here**. The unique index makes a
*duplicate* sequence number impossible at the data layer; the *gap* is prevented by the
allocator that increments `next_sequence_number` inside the posting transaction — that
is Epic 1 story 4, not this one. This schema does not claim the gapless guarantee is
already held.

### The `transactions` foreign key is omitted — and that is an omission, not a choice

`ledger_entries.transaction_id` is a `BIGINT UNSIGNED NOT NULL` column and the index
`ix_ledger_entries_transaction` over `(transaction_id, direction)` exists and is
verified.

**The foreign key `fk_ledger_entries_transaction → transactions(id)` does NOT exist
yet.** The `transactions` table belongs to **Epic 3** and does not exist, and MySQL
cannot create a foreign key to a non-existent table. This is recorded as an
**omission**, not a design decision, so it is not mistaken for a considered choice.

The column and the index are created exactly as specified, so **Epic 3 adds the
constraint as a pure constraint addition with no data movement.** There is no column
to add, no backfill to run, and no hashed field to disturb.

### The `entry_id` collation hazard

`entry_id` is a `CHAR(26)` ULID. The architecture spine does not fix a collation for
it, so **this change does not silently pick one**: the column carries the table's
default collation, which is `utf8mb4_0900_ai_ci` — **case-INsensitive**.

A ULID's canonical Crockford Base32 encoding is **case-sensitive**. Two distinct ULIDs
differing only in case would therefore collide on this primary key, and a lookup could
match the wrong row.

**The hazard is currently latent:** no ULID is generated yet and no ledger entries
exist, so nothing can collide today. It becomes real the moment entries are written.

The conventional fix is:

```sql
ALTER TABLE ledger_entries
    CONVERT TO CHARACTER SET ascii COLLATE ascii_bin;
```

That is a one-line migration. It is deliberately **not** applied here, because the
spine does not decide the collation and picking one now would be deciding G1 / A-019 by
implementation. The id's *type* — a ULID rather than an auto-increment — is itself
⚙️ **Architecture default, PENDING** (G1 / A-019); the reason for preferring it is
recorded (an auto-increment id leaks transfer volume and timing, and it sits inside
the hashed body).

### The hash contract is NOT fixed by this schema

**Do not infer a hash contract from this schema.** Two decisions are outstanding and
both are load-bearing:

- **M-2 — canonicalization: ⚪ BLOCKED.** The hashed-body field list and the
  byte-level canonicalization rules are **not finalised**. This is precisely why the
  hashed body is not fixed here: a field list that changes later would change every
  historical hash. The column set on this table is **not** the hashed-body field list.
- **M-3 — genesis literal: ⚪ OPEN.** The `prev_hash` value of the first entry is
  **not chosen**. The column exists; the constant does not.

Until both are resolved, `prev_hash` and `entry_hash` are **opaque 64-character
lowercase hex strings** and nothing more. No code in this change computes, verifies, or
chains them.

### Append-only at the data layer — and the one mechanism still outstanding

**`ledger_entries` IS append-only at the data layer.** Two triggers are installed on the
table, and both are **verified** after installation:

| Trigger | Event | Effect |
|---|---|---|
| `trg_ledger_entries_no_update` | `BEFORE UPDATE` | Every `UPDATE` is rejected with `SIGNAL SQLSTATE '45000'`. |
| `trg_ledger_entries_no_delete` | `BEFORE DELETE` | Every `DELETE` is rejected with `SIGNAL SQLSTATE '45000'`. |

Both trigger bodies are **unconditional**. They do not compare old and new values, and
they do not enumerate columns. A trigger that permitted a "no-op" update (`SET x = x`)
would have to enumerate the columns to compare, and a column added later would be
silently unprotected. The unconditional form cannot be bypassed by any statement and
does not need to be maintained as the table evolves.

The rejection message names the remedy — *corrections are made by appending a
compensating entry* (SPEC §4.2) — because the wrong remedy (editing history) is what an
operator will otherwise try.

The rejection holds **from a privileged session**. The triggers, not the privilege
grant, are what cover an operator with full schema privileges.

#### The one mechanism still outstanding — G4

The architecture spine (A5) specifies append-only as **three** mechanisms together:

| # | Mechanism | Status |
|---|---|---|
| 1 | The runtime role holds `INSERT` and `SELECT` on `ledger_entries` but **not** `UPDATE` or `DELETE` | ❌ **NOT implemented — G4** |
| 2 | `BEFORE UPDATE` and `BEFORE DELETE` triggers that `SIGNAL SQLSTATE '45000'` | ✅ **Installed and verified** |
| 3 | No `ON DELETE`/`ON UPDATE` cascade on any foreign key referencing `ledger_entries` | ✅ **Asserted** |

**Mechanism 1 is outstanding.** The privilege revocation is **G4** — an infrastructure
task, not a migration — and **no owner is recorded for it**. The runtime role still
holds `UPDATE` and `DELETE` on this table.

So the *effective* guarantee today is: **the triggers stop any `UPDATE` or `DELETE`,
regardless of the caller's privileges.** That is stronger than mechanism 1 alone and
covers the privileged-session case a privilege grant cannot. What mechanism 1 adds is
defence in depth, and defence against a role that holds `DROP` — a role that can drop
the trigger can also drop the table.

**The append-only guarantee is therefore real and enforced at the data layer, but it
is not yet complete in the spine's full sense.** This document does not claim more
than is in place.

#### The cascade prohibition extends to future foreign keys

`ledger_entries`' only foreign key today is `fk_ledger_entries_account`, which declares
**no** referential action, so MySQL's `RESTRICT` default applies — deleting an account
that has entries fails loudly.

**Two foreign keys are still to come, and both must carry the same obligation:**

- `fk_ledger_entries_transaction → transactions(id)` (Epic 3),
- `chain_close_records.entry_id → ledger_entries.entry_id` (story 5).

A cascade from either would silently destroy the ledger history these triggers exist to
protect. This is recorded here so it is not forgotten when those tables are built.

#### A rollback of this migration destroys `ledger_entries`

The immutability migration's `down()` **drops `ledger_entries`**, and its data with it.
That is deliberate: a table that survived a rollback without its append-only triggers
would be a schema that looks correct while enforcing nothing. The previous migration
recreates the table, and the immutability migration re-adds the triggers. In Phase 1 no
entry is ever written, so nothing is lost.

---

## H-6 / D1 — `ledger_entries.status`: 🟠 Proposed (not approved)

**H-6 / D1 is 🟠 Proposed (not approved).** It has not been adopted, and this change
**does not decide it**.

- **The table carries NO `status` column.** The column is absent.
- **A separate, reversible migration is prepared and deliberately NOT applied:**
  `database/migrations/pending/2026_10_26_000004_add_status_to_ledger_entries_table.php`.
  It adds `status ENUM('CHAIN_PENDING','CHAIN_CLOSED') NULL` after `direction`.
- **Why it is not applied:** applying it would decide H-6 / D1 by implementation. The
  **absence** of a column is the cheaper error to reverse later than the presence of
  one — a later migration can add a nullable column in one step, but adding a column
  that was *supposed to be inside the hashed body* changes every historical hash.
- **How the reversal works if H-6 is approved:** move the file out of `pending/` and
  apply it. One migration, no data rewrite.
- **How it is kept out of `migrate`:** Laravel's migrator globs `$path/*_*.php` —
  **non-recursively** — so a file in a subdirectory of `database/migrations/` is
  invisible to the runner. The `pending/` directory is that mechanism, and a test
  asserts the file is present on disk *and* unreachable by the migration runner.

---

## H-1 — SYSTEM accounts: 🟠 Proposed (not approved)

**H-1 is 🟠 Proposed (not approved).** It has not been adopted.

- **The schema accommodates H-1.** `ledger_accounts.owner_user_id` is nullable and
  `account_type` distinguishes `USER` from `SYSTEM`, so approving H-1 later is a data
  change and not a schema migration. `ck_ledger_accounts_type` ties the declared type
  to the owner's presence: a `USER` account must have an owner and no `system_role`;
  a `SYSTEM` account must have neither an owner nor a `system_role`.
- **No SYSTEM account exists.** The `SYSTEM` enum value and the `system_role` column
  are present but unused.
- **No funding path exists.** Nothing in this change moves money into a wallet. There
  is no code path, no seed, and no mechanism that could create a balance without a
  corresponding ledger entry pair.

If H-1 is **rejected** instead, `owner_user_id` becomes `NOT NULL` and `account_type`
and `system_role` are dropped in a later migration. That migration touches no hashed
field and no ledger entry.

---

## Known environment limitation — trigger creation

`trg_wallets_account_id_immutable` is a **mandatory** trigger, and its installation is
**fail-closed**.

### The invariant, and why it needs a trigger

The wallet↔account binding is immutable. A foreign key cannot express that: an
explicit `UPDATE` to a *different but valid* account satisfies referential integrity and
would otherwise succeed. Only a `BEFORE UPDATE` trigger rejects it. The database
enforcement is not optional, and it is not replaced by an application check.

### What "fail-closed" means here

`MySqlTriggerSupport::install()` creates the trigger and then **verifies** it is present
in `information_schema.triggers`. If creation fails — or the server reports success but
the trigger is absent — it throws `LedgerTriggerException` and the migration aborts.

Because MySQL DDL is not transactional, the migration also **drops the `wallets` table on
the failure path**. An aborted migration therefore leaves *no* `wallets` table, rather
than a half-built one whose immutability guarantee is missing.

The previous behaviour — log a warning and carry on — produced a schema that looked
correct while enforcing nothing. It has been removed.

### The one narrow exception: the isolated test schema

A migration that always fails closed would make the test suite unable to run at all on a
server that forbids triggers: every test would error out during schema setup and the
suite would prove nothing. The escape hatch is therefore guarded by **two** conditions
that must **both** hold:

1. `APP_ENV` is exactly `testing`, and
2. the connected database name ends in `_test`.

The database-name condition is the safety interlock. No production or development
database can ever satisfy it, so the flag cannot weaken a real schema even if it is set
in the environment. The flag lives in `config/ledger.php` and is read through `config()`,
so the decision does not change when the configuration is cached.

Every skip is logged at `warning` level with the exact blocker, and the trigger's
presence is always reported truthfully — a missing trigger never looks installed.

> **The escape hatch is currently switched OFF.** The test environment now runs against
> a trigger-capable server, so the ledger migrations install the trigger for real and
> the suite runs against a schema whose invariant is genuinely enforced. The mechanism
> remains in the code as a safety net for a trigger-less server; it is simply not
> active.

### Diagnosing the blocker correctly

A MySQL server with binary logging enabled refuses trigger creation unless the role
holds `SUPER` (or `SYSTEM_VARIABLES_ADMIN`) **or** the server sets
`log_bin_trust_function_creators=1`. The error is 1419 (`ER_BINLOG_UNSAFE_ROUTINE`).

MySQL's message names `SUPER`, which invites the wrong fix. **Measured:** the role
`p2p_test` already holds `ALL PRIVILEGES` on `p2p_platform_test.*` — which **includes**
the `TRIGGER` privilege — and `CREATE TRIGGER` still fails with 1419.
`SET SESSION log_bin_trust_function_creators` is rejected with errno 1229 (the variable
is GLOBAL-only); `SET GLOBAL`/`SET PERSIST` are rejected with errno 1227. **No
schema-level `GRANT` can resolve this.**

`MySqlTriggerSupport::blocker()` reports which of the two causes applies, so an
administrator is never sent to issue a `GRANT` that cannot help.

### How the blocker was resolved: a separate, isolated instance

The blocker was resolved **without touching the shared workstation server**. A **second,
isolated MySQL 8.0 instance** now runs on **port 3307**, with its own configuration, its
own data directory, its own `server-id`, and `log_bin_trust_function_creators=1` set
for that instance alone.

| | Isolated test instance | Shared workstation instance |
|---|---|---|
| Port | 3307 | 3306 |
| Config | `C:\ProgramData\MySQL\MySQL80-P2PTest\my.ini` | the original `my.ini` — **unchanged** |
| Data directory | `C:\ProgramData\MySQL\MySQL80-P2PTest\data\` | the original data directory — **unchanged** |
| `server-id` | 2 | 1 — **unchanged** |
| `log_bin_trust_function_creators` | 1 | **0 — unchanged** |
| Windows service | none (detached background process) | `MySQL80`, Running/Automatic — **untouched** |

- **No elevation was used or bypassed.** `sc.exe create` was refused with
  `OpenSCManager FAILED 5: Access is denied.`, so the instance runs as an ordinary
  detached background process instead of a Windows service.
- **Least-privilege credentials.** The role `p2p_test`@`localhost` holds `USAGE ON *.*`
  plus `ALL PRIVILEGES` on `p2p_platform_test.*` and **nothing else** — 0 global
  privileges, 0 administrative grants. The trigger capability comes from the instance
  setting, not from an over-privileged grant.
- **The password is never exposed.** It is stored in a file only the current OS user
  can read and is read at runtime through `App\Support\P2PTestSecret`. It is never in
  `.env`, never committed, never printed.
- **The shared server was never reconfigured.** No global setting was changed; no
  `SUPER` or `SYSTEM_VARIABLES_ADMIN` was granted to anyone.

**Operator tooling:** `php storage/app/p2ptest_instance.php start|stop|status`.

### A safety guard runs before every destructive command

`storage/app/p2ptest_connection_guard.php` asserts that the resolved connection is port
3307, database `p2p_platform_test`, role `p2p_test@localhost`, `server-id=2`, the
isolated data directory, `log_bin_trust_function_creators=1`, zero global privileges,
and that the isolated instance holds no schema other than `p2p_platform_test`.
`storage/app/p2ptest_artisan.php` refuses to run any artisan command if the guard
fails.

This is not ceremony. On its first run the guard resolved the *development* connection
and refused to proceed — which caught that `p2p_platform_dev` **does** exist on port
3306, contradicting an earlier finding. Because the guard refused, no destructive
command was ever run against it. Verified afterwards by read-only `SELECT`: 0 tables,
0 triggers.

### This affects the architecture spine more broadly

The spine relies on triggers for `ledger_entries` (append-only), `transactions`,
`reservations`, and `audit_log`. Those stories need the same fail-closed treatment, so
the invariant is never merely assumed — and they can now be built and proven on the
isolated instance rather than skipped.

---

## Not in this schema

The **hash chain** (the canonicalization rules under M-2 and the genesis literal under
M-3), the chain head and chain-close records, the `transactions` table and its foreign
key, the `reservations` table, the sequence *allocator* logic, the audit outbox,
transfer execution, reversal, and the reconciliation engine are all later stories. The
per-account sequence counter's **column** exists here; the atomic allocation that
uses it does not.

**The runtime-role privilege revocation (G4) is not here.** It is an infrastructure
task, not a migration. See §"The one mechanism still outstanding — G4".

**No ledger entry is written by this schema.** The table, its constraints, its
append-only triggers, and its model exist. Nothing in this change computes a hash,
posts a transfer, or creates an entry pair.

**No real-money production enablement is authorised by this schema.** Production
transfers and production reversals stay disabled until OQ-1, OQ-2, OQ-3 and OQ-4 are
explicitly approved.
