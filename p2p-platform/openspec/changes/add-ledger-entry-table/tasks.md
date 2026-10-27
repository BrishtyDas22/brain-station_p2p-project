# Tasks

**Scope.** BMAD Epic 1, story 2 — `ledger-entry-table`. The `ledger_entries` table, its data-layer
constraints, its indexes, and its Eloquent model. **No entry is written, no hash is computed, no transfer is
posted.**

**Every task states how to verify completion.** Tests land in the group whose work calls for them.

**Not in this task list, by instruction:** running a migration against a live database, changing `.env`,
committing, or pushing. The apply phase creates and reviews the migration files; running them is a separate,
explicit step.

**Blocked-task notation.** A task marked **⛔ BLOCKED** names the decision that blocks it. No blocked task is
silently scheduled.

**Approval ceiling.** No requirement in this project is finally approved. Every unapproved decision this
change touches is recorded as PENDING in `proposal.md` and `design.md`, and none is implemented as though it
were settled.

**Status: all 26 actionable tasks are complete.** The six ⛔ BLOCKED items below remain blocked and were not
scheduled. See §"What was actually verified" at the end for the measured evidence behind each tick.

---

## 1. The table

**Covers** spec requirements *Ledger entries carry a transaction, an account, a direction, an amount and a
currency* (SPEC §3.1), *Every entry carries a monotonic `account_sequence_number`* (SPEC §4.4).
**Design** DDL §"The DDL", Deviations 1–3.

- [x] 1.1 Create the `create_ledger_entries_table` migration with `entry_id CHAR(26) NOT NULL` as the
  primary key, `transaction_id BIGINT UNSIGNED NOT NULL`, `account_id BIGINT UNSIGNED NOT NULL`,
  `direction ENUM('DEBIT','CREDIT') NOT NULL`, `amount_minor BIGINT UNSIGNED NOT NULL`,
  `currency CHAR(3) NOT NULL DEFAULT 'BDT'`, `account_sequence_number BIGINT UNSIGNED NOT NULL`,
  `balance_after BIGINT UNSIGNED NULL`, `prev_hash CHAR(64) NOT NULL`, `entry_hash CHAR(64) NOT NULL`,
  `created_at DATETIME(6) NOT NULL`; verify the file exists and `php -l` reports no syntax error
- [x] 1.2 Add the UNIQUE index `uq_ledger_entries_account_sequence` over
  `(account_id, account_sequence_number)`; verify by inserting two entries for the same account with the same
  sequence number and asserting a unique violation is raised, and by inserting the same sequence number for
  two *different* accounts and asserting both are accepted
- [x] 1.3 Add the UNIQUE index `uq_ledger_entries_entry_hash` over `entry_hash`; verify by inserting two
  entries with the same hash and asserting a unique violation is raised
- [x] 1.4 Add the index `ix_ledger_entries_transaction` over `(transaction_id, direction)`; verify by
  asserting the index is present in `SHOW CREATE TABLE`
- [x] 1.5 Add the index `ix_ledger_entries_account_created` over `(account_id, created_at)`; verify by
  asserting the index is present in `SHOW CREATE TABLE`
- [x] 1.6 Add the raw DDL for `ck_ledger_entries_amount CHECK (amount_minor > 0)`; verify by inserting an
  entry with `amount_minor = 0` and asserting a CHECK-constraint violation, and by inserting
  `amount_minor = -1` and asserting an out-of-range violation
- [x] 1.7 Add the raw DDL for `ck_ledger_entries_currency CHECK (currency = 'BDT')`; verify by inserting
  `currency = 'USD'` and asserting a CHECK-constraint violation
- [x] 1.8 Add the raw DDL for `ck_ledger_entries_balance CHECK (balance_after IS NULL OR balance_after >=
  0)`; verify by inserting `balance_after = -1` and asserting rejection, and by inserting
  `balance_after = NULL` and asserting acceptance
- [x] 1.9 Add the foreign key `fk_ledger_entries_account` from `account_id` to `ledger_accounts(id)` with
  **no** `ON DELETE` and **no** `ON UPDATE`; verify by inserting an entry naming a non-existent account and
  asserting a foreign-key violation, and by asserting `SHOW CREATE TABLE` shows no referential action on any
  foreign key
- [x] 1.10 Confirm via `SHOW CREATE TABLE` that the table's engine is `InnoDB`, its charset is `utf8mb4`, and
  that `amount_minor` and `balance_after` are `bigint unsigned` with no `decimal`, `float` or `double` column
  anywhere in the table; verify the check output

## 2. The two documented omissions

**These are omissions, not design choices.** Each is recorded so it is not mistaken for a decision.

- [x] 2.1 Prepare — but **DO NOT apply** — a separate, reversible migration
  `add_status_to_ledger_entries_table` that adds a `status` column to `ledger_entries`. **H-6 / D1 is
  🟠 Proposed (not approved)**; applying this migration would decide it by implementation. Verify that the
  file exists, that `php -l` reports no syntax error, and that it is **not** among the migrations applied to
  the schema
- [x] 2.2 Record in `docs/ledger-schema.md` that the `transactions` foreign key is **omitted** because the
  `transactions` table belongs to Epic 3 and does not exist; name the column and the index that *are* created,
  and state that Epic 3 adds the constraint with no data movement. Verify the section exists and names all
  three facts
- [x] 2.3 Record in `docs/ledger-schema.md` the `entry_id` collation hazard — MySQL's default
  `utf8mb4_0900_ai_ci` is case-insensitive, and a ULID's canonical encoding is case-sensitive, so two distinct
  ULIDs differing only in case would collide on the primary key — and that the spine does not fix a
  collation, so this change does not silently choose one. Verify the section states the hazard, that it is
  currently latent because no ULID is generated yet, and the conventional fix
  (`CONVERT TO CHARACTER SET ascii COLLATE ascii_bin`) by name

## 3. The model

**Covers** spec requirement *Ledger entries carry a direction* (SPEC §3.1).
**Design** §"The model".

- [x] 3.1 Create the `LedgerDirection` backed string enum with the cases `DEBIT` and `CREDIT`; verify the
  enum instantiates and its backing values match the column's allowed values
- [x] 3.2 Create the `LedgerEntry` Eloquent model with `direction` cast to `LedgerDirection::class` and
  `amount_minor` and `balance_after` cast to `IntegerPoisha::class`; verify the model instantiates and the
  casts return the expected types
- [x] 3.3 Give `LedgerEntry` `protected $guarded = ['*']` with `protected $fillable = ['id']`, so every
  column is guarded except the primary key; verify by asserting `isFillable('id')` is true and
  `isFillable('amount_minor')` and `isFillable('direction')` are false
- [x] 3.4 Add the `belongsTo(LedgerAccount::class)` relation on `account_id`; verify the relation resolves
  to the account the entry names
- [x] 3.5 Assert in a test that assigning a non-integer value to `amount_minor` is rejected rather than
  silently truncated or rounded, and that the attribute holds no truncated value; verify the test passes
- [x] 3.6 Assert in a test that a `LedgerEntry` read from the database with `balance_after` of `NULL` reports
  `null`, not `0` — a null balance must not become a zero balance; verify the test passes

## 4. Documentation

- [x] 4.1 Add a section to `docs/ledger-schema.md` recording that `ledger_entries` holds the append-only
  double-entry ledger, that `wallets.balance_minor` is a **projection** of those entries and the entries are
  the authority, and that `balance_after` is explicitly **not** authoritative and is excluded from the
  chained body (SPEC §5.2); verify the section states all three points
- [x] 4.2 Record in the same documentation that the hashed-body field list is **not** finalised here
  (**M-2 is ⚪ BLOCKED**) and that the genesis literal is **not** chosen (**M-3 is ⚪ OPEN**), so that no
  reader infers a hash contract from this schema; verify the section states both statuses
- [x] 4.3 Record in the same documentation that append-only is **not yet enforced** — story 3 adds the
  triggers and G4's privilege revocation is an infrastructure task — so the guarantee is not mistaken for
  already-held; verify the section states this

## 5. Integration verification

*Cross-cutting checks only. Every test owed by groups 1–4 landed in its own group.*

- [x] 5.1 Run the full test suite and verify it passes with no pre-existing test broken; verify the suite's
  exit status is success
- [x] 5.2 Verify the migration applies cleanly against the isolated test schema on port 3307 and rolls back
  cleanly, leaving no `ledger_entries` table and no orphaned index behind; verify `migrate:rollback` leaves
  nothing behind
- [x] 5.3 Verify `P2P_SPECIFICATION.md` is byte-identical to its state before this change (md5
  `a9e2b6cc16d140a8de83a69450f83026`); verify the checksum matches
- [x] 5.4 Verify no `.env` file was modified and no commit or push occurred; verify `git status` shows only
  the intended new files and `.env` is unchanged
- [x] 5.5 Verify the shared workstation instance on port 3306 is untouched — `p2p_platform_dev` still has 0
  tables and 0 triggers, and `log_bin_trust_function_creators` is still `0`; verify the read-only check
  output

---

## ⛔ BLOCKED — not scheduled in this change

These are recorded so they are not forgotten. Each names its blocker. **None is silently scheduled.**

- [ ] ⛔ **BLOCKED by M-2** (canonicalization, ⚪ BLOCKED) — finalise the chained-body field list and the
  byte-level canonicalization rules. **This is why the hashed body is not fixed here.** *Blocks BMAD Epic 1
  stories 8 and 9.* Not part of this change.
- [ ] ⛔ **BLOCKED by M-3** (genesis literal, ⚪ Open) — choose the genesis value for `prev_hash` of the first
  entry. *Blocks BMAD Epic 1 story 5's chain bootstrap.* Not part of this change.
- [ ] ⛔ **BLOCKED by H-6 / D1** (🟠 Proposed, not approved) — decide whether `ledger_entries` carries a
  `status` column. **The migration is prepared in task 2.1 and deliberately not applied.** The table shape is
  fixed either way; only the migration differs.
- [ ] ⛔ **BLOCKED by G1 / A-019** (⚙️ Architecture default, PENDING) — confirm `entry_id` is a `CHAR(26)`
  ULID rather than an auto-increment. Affects story 2's column type and the story 4 allocator's generation.
- [ ] ⛔ **BLOCKED by G4** (⚙️ Architecture default, PENDING) — apply `INSERT`/`SELECT`-only privileges on the
  chain tables to a separate runtime role. *An infrastructure task, not a migration.* *Affects story 3.*
- [ ] ⛔ **BLOCKED by the `transactions` table (Epic 3)** — the foreign key
  `fk_ledger_entries_transaction → transactions(id)` cannot be created until that table exists. Task 2.2
  records the omission.

---

## What was actually verified

The ticks above are backed by measured evidence, not by inspection alone.

### The applied schema, read back from the server

`SHOW CREATE TABLE ledger_entries` on the isolated instance (port 3307) returned exactly:

```
CREATE TABLE `ledger_entries` (
  `entry_id` char(26) NOT NULL,
  `transaction_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `direction` enum('DEBIT','CREDIT') NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'BDT',
  `account_sequence_number` bigint unsigned NOT NULL,
  `balance_after` bigint unsigned DEFAULT NULL,
  `prev_hash` char(64) NOT NULL,
  `entry_hash` char(64) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`entry_id`),
  UNIQUE KEY `uq_ledger_entries_account_sequence` (`account_id`,`account_sequence_number`),
  UNIQUE KEY `uq_ledger_entries_entry_hash` (`entry_hash`),
  KEY `ix_ledger_entries_transaction` (`transaction_id`,`direction`),
  KEY `ix_ledger_entries_account_created` (`account_id`,`created_at`),
  CONSTRAINT `fk_ledger_entries_account` FOREIGN KEY (`account_id`) REFERENCES `ledger_accounts` (`id`),
  CONSTRAINT `ck_ledger_entries_amount` CHECK ((`amount_minor` > 0)),
  CONSTRAINT `ck_ledger_entries_balance` CHECK (((`balance_after` is null) or (`balance_after` >= 0))),
  CONSTRAINT `ck_ledger_entries_currency` CHECK ((`currency` = _utf8mb4'BDT'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
```

**Exactly eleven columns. No `status`. No `ON DELETE`. No `ON UPDATE`.**

### The status migration is genuinely unapplied — and mechanically so

Two independent checks:

1. The `migrations` table contains **eight** applied migrations, and
   `2026_10_26_000004_add_status_to_ledger_entries_table` is **not** among them.
2. A test calls `app('migrator')->getMigrationFiles(database_path('migrations'))` and asserts the pending file
   is **absent** from the runner's file list.

Laravel's migrator globs `$path.'/*_*.php'` — **non-recursively**
(`vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:592-601`). A file in a
subdirectory of `database/migrations/` is therefore invisible to `migrate`. The file lives at
`database/migrations/pending/2026_10_26_000004_add_status_to_ledger_entries_table.php` for exactly that
reason. **H-6 / D1 is left undecided.**

> **This was a real defect, caught and fixed during this change.** The prepared migration was initially
> written directly into `database/migrations/`, where `migrate` **would** have applied it — silently deciding
> H-6 / D1 by implementation. The file was moved to `pending/` and the non-recursive glob was confirmed
> against the framework source before the move was accepted as a fix.

### Rollback and re-apply

`migrate:rollback --step=3` dropped `ledger_entries`, `wallets` and `ledger_accounts` cleanly; the
subsequent `information_schema` probe reported **0 columns** on `ledger_entries` — the table was gone, with
no orphaned index or CHECK constraint. `migrate` then re-applied all three cleanly, and
`trg_wallets_account_id_immutable` was reinstalled and proved to fire (a balance-only update succeeded; a
repoint of `account_id` was rejected with SQLSTATE 45000 and the row was left unchanged).

### Test results

| Run | Result |
|---|---|
| `php artisan test --filter=LedgerEntry` | **46 passed**, 106 assertions, 0 failed, 0 skipped |
| `php artisan test` (full suite) | **147 passed**, 432 assertions, 0 failed, 0 skipped |
| `vendor/bin/phpstan analyse --memory-limit=1G` | **No errors** (level 7) |
| `vendor/bin/pint --test` (new files) | **Clean** after 3 fixes applied |
| `php -l` on all 8 new/changed PHP files | **No syntax errors** |
| `npx openspec validate add-ledger-entry-table --strict` | **valid** |

### A genuine defect this change found and fixed

`IntegerPoisha::set()` originally threw on `null`, because the cast was written for the **non-null** money
columns on `wallets` and `ledger_accounts`. `ledger_entries.balance_after` is **nullable**, and a null
balance is a meaningful, distinct value — "not computed" — that must never be coerced to `0` (SPEC §5.2,
task 3.6). The cast now passes `null` through unchanged on both the read and the write side, so a nullable
money column round-trips losslessly. The wallet and account models are unaffected: their columns are
`NOT NULL`, so the database still rejects a null there.

The change was made in `app/Casts/IntegerPoisha.php`, not worked around in the test.

### An additional invariant, proven independently of the model

The `LedgerDirection` enum cast rejects an unrecognised direction **before** it reaches the wire, with a
`ValueError` naming the offending value. That is stricter than the database. The database-level `ENUM`
rejection is therefore asserted **separately**, by a raw `DB::insert()` that bypasses Eloquent entirely, so
the guarantee does not rest on the cast alone.

### Untouched, as required

- `../P2P_SPECIFICATION.md` — md5 `a9e2b6cc16d140a8de83a69450f83026`, unchanged.
- `.env` and `.env.testing` — unchanged. The test password is read at runtime from a file only the current
  OS user can read; it is never in an environment file and was never printed.
- `p2p_platform_dev` on port 3306 — **0 tables, 0 triggers**, `log_bin_trust_function_creators` still `0`.
- The shared `MySQL80` service on 3306 — not modified, restarted or reconfigured.
- No commit and no push.

---

## Workflow follow-up

- Apply the change with the `openspec-apply-change` workflow.
- Reconcile the BMAD ticket: mark `story-ledger-entry-table` built in
  `_bmad-output/initiative-p2p-settlement/tickets/epic-ledger-core/tickets/tickets.toml`, and append to the
  epic's `.memlog.md`.
- Archive the change only after the BMAD review requirements for Epic 1 are satisfied.
