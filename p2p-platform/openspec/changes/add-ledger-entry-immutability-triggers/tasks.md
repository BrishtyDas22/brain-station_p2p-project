# Tasks

**Scope.** BMAD Epic 1, story 3 — `ledger-entry-immutability-triggers`. The `BEFORE UPDATE` and
`BEFORE DELETE` triggers on `ledger_entries`, and the re-assertion of the cascade prohibition.
**No entry is written, no hash is computed, no transfer is posted.**

**Every task states how to verify completion.** Tests land in the group whose work calls for them.

**Not in this task list, by instruction:** running a migration against a live database, changing
`.env`, committing, or pushing. The apply phase creates and reviews the migration files; running
them is a separate, explicit step.

**Blocked-task notation.** A task marked **⛔ BLOCKED** names the decision that blocks it. No blocked
task is silently scheduled.

**Approval ceiling.** No requirement in this project is finally approved. Every unapproved decision
this change touches is recorded as PENDING in `proposal.md` and `design.md`, and none is implemented
as though it were settled.

**Status: all 24 actionable tasks are complete.** The six ⛔ BLOCKED items below remain blocked and
were not scheduled. See §"What was actually verified" at the end for the measured evidence.

---

## 1. The triggers

**Covers** spec requirements *A ledger entry can never be updated*, *A ledger entry can never be
deleted*, *The triggers are installed and verified*.
**Design** §"The DDL", §"The installer".

- [x] 1.1 Create the `add_ledger_entry_immutability_triggers` migration; verify the file exists and
  `php -l` reports no syntax error
- [x] 1.2 Install `trg_ledger_entries_no_update` — a `BEFORE UPDATE` trigger on `ledger_entries` that
  `SIGNAL SQLSTATE '45000'` unconditionally; verify by asserting the trigger is present in
  `information_schema.triggers` and that a direct `UPDATE` is rejected
- [x] 1.3 Install `trg_ledger_entries_no_delete` — a `BEFORE DELETE` trigger on `ledger_entries` that
  `SIGNAL SQLSTATE '45000'` unconditionally; verify by asserting the trigger is present in
  `information_schema.triggers` and that a direct `DELETE` is rejected
- [x] 1.4 Give both triggers a `MESSAGE_TEXT` that tells the caller corrections are made by
  appending a compensating entry, not by editing a row; verify the message text is present in the
  trigger's `ACTION_STATEMENT`
- [x] 1.5 Install both triggers through `MySqlTriggerSupport::install()`, so installation is
  **fail-closed** and the trigger's presence is **verified** after creation; verify by asserting the
  installer is used and that a missing trigger is detected rather than assumed absent
- [x] 1.6 Make the migration **drop `ledger_entries`** on the failure path, because MySQL DDL is not
  transactional and a table with one trigger and no other is a schema that looks correct while
  enforcing half the guarantee; verify by asserting the failure path drops the table and rethrows
- [x] 1.7 Make `down()` drop `ledger_entries` — MySQL drops a table's triggers with it, so a rollback
  SHALL NOT leave a table whose append-only guarantee is gone; verify by asserting the table and both
  triggers are gone after a rollback

## 2. The prohibition of cascades

**Covers** spec requirement *No foreign key on `ledger_entries` carries a referential action*.
**Design** §"Mechanism 3 — the cascade prohibition".

- [x] 2.1 Assert that no foreign key on `ledger_entries` declares `ON DELETE` or `ON UPDATE`;
  verify by inspecting `SHOW CREATE TABLE` and asserting neither clause appears
- [x] 2.2 Assert that deleting an account that has at least one entry is rejected by the database
  with a foreign-key violation; verify by attempting the delete and asserting the rejection
- [x] 2.3 Record in `docs/ledger-schema.md` that the **future** foreign keys carry the same
  obligation — the deferred `fk_ledger_entries_transaction` (Epic 3) and the `chain_close_records`
  reference to `ledger_entries.entry_id` (story 5) — and that a cascade from either would silently
  destroy ledger history; verify the section names both

## 3. The proof that the triggers fire

**Covers** spec requirement *The triggers are installed and verified*, and AC6 (*a direct `UPDATE`
raises an error from the trigger; a direct `DELETE` raises an error from the trigger. Both are
rejected even when issued from a privileged session*).

- [x] 3.1 Assert in a test that a direct `UPDATE` against `ledger_entries` is rejected with SQLSTATE
  `45000`; verify the test passes
- [x] 3.2 Assert in a test that the row is **unchanged** after the rejected `UPDATE`; verify the
  test passes
- [x] 3.3 Assert in a test that a direct `DELETE` against `ledger_entries` is rejected with SQLSTATE
  `45000` and the row is still present afterwards; verify the test passes
- [x] 3.4 Assert in a test that a `DELETE` with **no** `WHERE` clause is rejected and **no** row is
  removed; verify the test passes
- [x] 3.5 Assert in a test that a **no-op** `UPDATE` — one that sets a column to its existing value —
  is also rejected, proving the trigger is unconditional and cannot be side-stepped; verify the test
  passes
- [x] 3.6 Assert in a test that the rejection holds **from a privileged session** — the connection
  used by the test suite holds `ALL PRIVILEGES` on the schema — and that the `MESSAGE_TEXT` names the
  compensating-entry remedy; verify the test passes
- [x] 3.7 Assert in a test that the trigger-bypass escape hatch is **off** in the default test
  configuration, so the suite runs against a schema whose invariant is genuinely enforced rather
  than one where the trigger was quietly skipped; verify the test passes

## 4. Documentation

- [x] 4.1 Record in `docs/ledger-schema.md` that `ledger_entries` is now **append-only at the data
  layer**, that both triggers are installed and verified, and that they reject `UPDATE` and
  `DELETE` unconditionally; verify the section states all three points
- [x] 4.2 Record in the same documentation that the **runtime-role privilege revocation is G4 and is
  NOT implemented** — the runtime role still holds `UPDATE` and `DELETE` — so the guarantee is not
  mistaken for being fully held; verify the section states this
- [x] 4.3 Record in the same documentation that a rollback of this migration **destroys**
  `ledger_entries` and its data, and why that is correct; verify the section states this

## 5. Integration verification

*Cross-cutting checks only. Every test owed by groups 1–4 landed in its own group.*

- [x] 5.1 Run the full test suite and verify it passes with no pre-existing test broken; verify the
  suite's exit status is success
- [x] 5.2 Verify the migration applies cleanly against the isolated test schema on port 3307 and
  rolls back cleanly, leaving no `ledger_entries` table and no orphaned trigger behind; verify
  `migrate:rollback` leaves nothing behind
- [x] 5.3 Verify `P2P_SPECIFICATION.md` is byte-identical to its state before this change (md5
  `a9e2b6cc16d140a8de83a69450f83026`); verify the checksum matches
- [x] 5.4 Verify no `.env` file was modified and no commit or push occurred; verify `git status`
  shows only the intended new files and `.env` is unchanged
- [x] 5.5 Verify the shared workstation instance on port 3306 is untouched — `p2p_platform_dev` still
  has 0 tables and 0 triggers, and `log_bin_trust_function_creators` is still `0`; verify the
  read-only check output

---

## ⛔ BLOCKED — not scheduled in this change

These are recorded so they are not forgotten. Each names its blocker. **None is silently scheduled.**

- [ ] ⛔ **BLOCKED by G4** (⚙️ Architecture default, PENDING) — apply `INSERT`/`SELECT`-only privileges on
  `ledger_entries` to a separate runtime role, so the runtime role holds **no** `UPDATE` and **no**
  `DELETE`. *An infrastructure task, not a migration.* **This is the one outstanding mechanism of
  R7.** The triggers installed by this change cover the privileged-session case that a privilege
  grant cannot, but the privilege revocation itself is not implemented here and no owner is
  recorded for it.
- [ ] ⛔ **BLOCKED by H-6 / D1** (🟠 Proposed, not approved) — decide whether `ledger_entries` carries a
  `status` column. **Not touched by this change.** The table still carries no `status` column, and
  the prepared-but-unapplied migration in `database/migrations/pending/` is unaffected.
- [ ] ⛔ **BLOCKED by G1 / A-019** (⚙️ Architecture default, PENDING) — confirm `entry_id` is a
  `CHAR(26)` ULID rather than an auto-increment. **Not touched.** The triggers reject every
  `UPDATE` regardless of the primary key's type.
- [ ] ⛔ **BLOCKED by M-2** (canonicalization, ⚪ BLOCKED) and **M-3** (genesis literal, ⚪ Open) —
  no hash is computed, verified or chained. The triggers are independent of the hash contract.
  *Blocks BMAD Epic 1 stories 8 and 9.*
- [ ] ⛔ **BLOCKED by the `transactions` table (Epic 3)** — the foreign key
  `fk_ledger_entries_transaction → transactions(id)` cannot be created until that table exists. Task
  2.3 records that it too must carry no referential action.
- [ ] ⛔ **BLOCKED by the `chain_close_records` table (story 5)** — the FK from
  `chain_close_records.entry_id` to `ledger_entries.entry_id` cannot be created until that table
  exists. Task 2.3 records that it too must carry no referential action.

---

## What was actually verified

The ticks above are backed by measured evidence.

### The triggers are installed and they fire

`information_schema.triggers` on the isolated instance, after a clean `migrate`:

```
trg_ledger_entries_no_delete  on ledger_entries
trg_ledger_entries_no_update  on ledger_entries
trg_wallets_account_id_immutable  on wallets
```

Both `ledger_entries` triggers are `BEFORE` / `UPDATE` and `BEFORE` / `DELETE` respectively,
asserted from `ACTION_TIMING` and `EVENT_MANIPULATION` rather than from the trigger name.

The rejections are **server errors**, observed as SQLSTATE `45000`:

| Attempted write | Result |
|---|---|
| `UPDATE ledger_entries SET amount_minor = …` | rejected, SQLSTATE 45000 |
| `UPDATE ledger_entries SET amount_minor = amount_minor` (no-op) | rejected, SQLSTATE 45000 |
| `UPDATE ledger_entries SET balance_after = …` (a **non-hashed** column) | rejected, SQLSTATE 45000 |
| `DELETE FROM ledger_entries WHERE entry_id = …` | rejected, SQLSTATE 45000 |
| `DELETE FROM ledger_entries` (no `WHERE` clause) | rejected, SQLSTATE 45000, **no** row removed |

Two of those deserve comment:

- The **no-op** update is rejected, which proves the trigger is unconditional. A trigger that
  permitted `SET x = x` would have to enumerate the columns to compare, and a column added later
  would be silently unprotected.
- The **`balance_after`** update is rejected, which proves the trigger protects the whole row and
  not merely the hashed fields. `balance_after` is excluded from the chained body, so the chain
  verifier could not detect its mutation.

A rejected `UPDATE` leaves the row **byte-identical** — asserted by comparing the raw row before
and after, not the model's cast attributes.

### The rejection holds from a privileged session

The test asserts, from `SHOW GRANTS FOR CURRENT_USER`, that the session holds `ALL PRIVILEGES` on
the schema — and then that the `DELETE` is still rejected with SQLSTATE `45000` and a
`MESSAGE_TEXT` naming the compensating-entry remedy. This is AC6's "even when issued from a
privileged session", proven rather than asserted.

### The fail-closed failure path

Probed directly against the server: the second trigger was made to fail with genuinely invalid
DDL (`ON no_such_table_here`), the migration's failure path ran, and the outcome was measured —
`ledger_entries` absent, **zero** triggers, **zero** orphaned probe triggers. A regression test
asserts the same property.

> **A measurement error was caught and corrected during this work.** The first probe used a raw
> PDO handle on `mysql_p2ptest` but the `Schema` facade on the **default** connection, which outside
> the test environment resolves to `mysql` → `p2p_platform_dev`. The probe therefore measured the
> wrong schema and appeared to show the table surviving the drop. It did not — the probe was simply
> looking at the wrong database. The corrected probe forces the default connection to
> `mysql_p2ptest` for both. The development database was **not** modified by either probe: the
> `dropIfExists` it did issue was a no-op, because `p2p_platform_dev` has no `ledger_entries` table.
> Verified afterwards by read-only `SELECT`: 0 tables, 0 triggers.

### Rollback and re-apply

`migrate:rollback --step=4` left the isolated schema with **1 table** (`migrations`) and **0
triggers** — no `ledger_entries`, and no orphaned trigger behind. `migrate` then re-applied all nine
migrations cleanly, restoring all three triggers.

### Test results

| Run | Result |
|---|---|
| `php artisan test --filter=LedgerEntryImmutability` | **18 passed**, 42 assertions, 0 failed, 0 skipped |
| `php artisan test` (full suite) | **165 passed**, 474 assertions, 0 failed, 0 skipped |
| `vendor/bin/phpstan analyse --memory-limit=1G` | **No errors** (level 7) |
| `vendor/bin/pint --test` (new files) | **Clean** |
| `php -l` on the new migration | **No syntax errors** |
| `npx openspec validate add-ledger-entry-immutability-triggers --strict` | **valid** |

### Untouched, as required

- `../P2P_SPECIFICATION.md` — md5 `a9e2b6cc16d140a8de83a69450f83026`, unchanged.
- `.env` and `.env.testing` — unchanged.
- `p2p_platform_dev` on port 3306 — **0 tables, 0 triggers**, `log_bin_trust_function_creators`
  still `0`.
- The shared `MySQL80` service on 3306 — not modified, restarted or reconfigured.
- The `status` migration in `database/migrations/pending/` — still not applied.
- No commit and no push.

---

## Workflow follow-up

- Apply the change with the `openspec-apply-change` workflow.
- Reconcile the BMAD ticket: mark `story-ledger-entry-immutability-triggers` built in
  `_bmad-output/initiative-p2p-settlement/tickets/epic-ledger-core/tickets/tickets.toml`, and append
  to the epic's `.memlog.md`.
- Archive the change only after the BMAD review requirements for Epic 1 are satisfied.
