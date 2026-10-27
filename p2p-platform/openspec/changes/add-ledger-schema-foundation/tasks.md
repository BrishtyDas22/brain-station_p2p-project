# Tasks

**Scope.** BMAD Epic 1, story 1 — `ledger-schema-foundation`. Two tables, their data-layer
constraints, and their models. No ledger entries, no hash chain, no transfer path.

**Every task states how to verify completion.** Tests land in the group whose work calls for them.

**Not in this task list, by instruction:** running a migration against a live database, changing
`.env`, committing, or pushing. The apply phase creates and reviews the migration files; running
them is a separate, explicit step.

**Blocked-task notation.** A task marked **⛔ BLOCKED** names the decision that blocks it. No
blocked task is silently scheduled.

**Apply-phase result (2026-10-26).** **All 25 tasks are complete.** Task 2.5 is now
**fully verified** — its *re-point rejected* half, which was previously blocked by a server
configuration, passes against the isolated, trigger-capable MySQL instance this project now
owns on port 3307. The shared workstation `MySQL80` service on port 3306 was **not** modified,
restarted or reconfigured, and no global setting was changed on it.

The trigger is **mandatory and fail-closed**: if it cannot be installed, the migration aborts
and drops the `wallets` table rather than leaving a schema whose immutability guarantee is
missing. The narrowly-guarded escape remains in the code — it can never weaken a production or
development schema — but it is **switched off** in the test configuration, so the suite now
runs against a schema whose invariant is genuinely enforced by the database.

**Verification gap: closed.** The 3 previously-skipped tests now pass, and the suite is
**101 passed / 0 failed / 0 skipped** (326 assertions). No task was ticked on the strength of
work that was not actually done, and no pending architecture choice was implemented as an
approved fact.

---

## 1. Ledger accounts table

**Covers** spec requirements *Ledger accounts may be system-owned*, *Ledger accounts carry a
per-account sequence counter*. **Design** D-1, D-2, D-3, D-5, D-6.

- [x] 1.1 Create the `create_ledger_accounts_table` migration with `id BIGINT UNSIGNED AUTO_INCREMENT`, nullable `owner_user_id` with FK to `users(id)`, `account_type ENUM('USER','SYSTEM') NOT NULL DEFAULT 'USER'`, `system_role ENUM('FUNDING','TREASURY') NULL`, `currency CHAR(3) NOT NULL DEFAULT 'BDT'`, `next_sequence_number BIGINT UNSIGNED NOT NULL DEFAULT 1`, `created_at DATETIME(6) NOT NULL`; verify the file exists and `php -l` reports no syntax error
- [x] 1.2 Add the `uq_ledger_accounts_owner` unique index on `owner_user_id` and `ix_ledger_accounts_system_role`; verify by attempting to create two accounts for the same user and asserting a unique violation is raised
- [x] 1.3 Add the `ck_ledger_accounts_type` CHECK constraint enforcing that `USER` accounts have an owner and no `system_role`, and `SYSTEM` accounts have neither; verify by attempting both mismatched forms and asserting each is rejected by the database
- [x] 1.4 Add the `ck_ledger_accounts_currency` CHECK constraint restricting `currency` to `'BDT'`; verify by attempting to write `'USD'` and asserting rejection
- [x] 1.5 Add a `COMMENT` on `next_sequence_number` recording that it is a per-account sequence allocator, not a balance; verify the comment is present in the schema via `SHOW CREATE TABLE`
- [x] 1.6 Write the `LedgerAccount` model test asserting that a SYSTEM account may exist with a null owner and that the model creates it successfully; verify the test passes

## 2. Wallets table

**Covers** spec requirements *Wallet holds integer minor-unit balances*, *Reserved balance never
exceeds posted balance*, *Each wallet maps to exactly one ledger account*. **Design** D-1, D-2, D-4,
D-6, D-7, D-9.

- [x] 2.1 Create the `create_wallets_table` migration with `id BIGINT UNSIGNED AUTO_INCREMENT`, `owner_user_id BIGINT UNSIGNED NOT NULL` with FK to `users(id)`, `account_id BIGINT UNSIGNED NOT NULL` with FK to `ledger_accounts(id)`, `currency CHAR(3) NOT NULL DEFAULT 'BDT'`, `balance_minor BIGINT UNSIGNED NOT NULL DEFAULT 0`, `reserved_minor BIGINT UNSIGNED NOT NULL DEFAULT 0`, `created_at`/`updated_at` as `DATETIME(6) NOT NULL`; verify the file exists and `php -l` reports no syntax error
- [x] 2.2 Add the three unique indexes `uq_wallets_account` on `account_id`, `uq_wallets_owner_currency` on `(owner_user_id, currency)`, and `ix_wallets_owner` on `owner_user_id`; verify by attempting to bind two wallets to one account and asserting a unique violation is raised
- [x] 2.3 Add the `ck_wallets_reserved_within_balance` CHECK constraint `reserved_minor <= balance_minor`; verify by attempting to set `reserved_minor` above `balance_minor` and asserting rejection by the database, and by setting it to exactly `balance_minor` and asserting acceptance
- [x] 2.4 Add the `ck_wallets_currency` CHECK constraint restricting `currency` to `'BDT'`; verify by attempting to write `'USD'` and asserting rejection
- [x] 2.5 Confirm the two foreign keys carry **no** `ON DELETE` or `ON UPDATE` referential action; verify by attempting to delete an account that has a wallet and asserting the deletion is rejected, and by attempting to repoint a wallet's `account_id` and asserting rejection
  - ✅ **Fully verified.** All three halves now pass against the isolated, trigger-capable test instance (port 3307):
    - *No referential action* — asserted from `SHOW CREATE TABLE` for both tables (`test_no_foreign_key_carries_a_referential_action`).
    - *Deleting an account that has a wallet* — rejected by `fk_wallets_account` with errno 1451 (`test_deleting_an_account_that_has_a_wallet_is_rejected`).
    - *Repointing a wallet's `account_id`* — rejected by `trg_wallets_account_id_immutable` with SQLSTATE 45000 / errno 1644 (`test_repointing_a_wallet_at_a_different_account_is_rejected`).
  - ✅ **The trigger really fires.** `test_the_immutability_trigger_does_not_block_balance_updates` proves a balance-only `UPDATE` still succeeds, so the trigger rejects exactly the re-point and nothing else.
  - ✅ **The escape hatch is OFF.** The trigger-bypass setting was removed from `phpunit.xml`; the ledger migrations now install the trigger for real and the suite runs against a schema whose invariant is genuinely enforced.
  - ✅ **The fail-closed guarantee is still covered** and is no longer dependent on the server lacking the capability: `TriggerFailClosedTest` provokes a deterministic server refusal (invalid DDL) and asserts the installer throws `LedgerTriggerException` rather than swallowing the error, that a failed installation leaves no trigger behind, and that the invariant is restored afterwards.
  - ✅ **The trigger's presence is reported truthfully** — `test_the_escape_hatch_never_makes_a_missing_trigger_look_installed` removes the trigger and asserts it is reported absent, then restores it and asserts it is reported present.
  - ✅ **The interlock properties are unchanged and still proven**: the escape hatch refuses a non-`_test` database, refuses a non-`testing` environment, and can never apply to a production database.

  #### The blocker was resolved with a separate, isolated instance — no shared server was touched

  The server-side blocker (errno 1419: `log_bin=1` with
  `log_bin_trust_function_creators=0`) was resolved by standing up a **second,
  isolated MySQL 8.0 instance** rather than by changing any global setting on
  the shared workstation server. **The `MySQL80` service on port 3306 was not
  modified, restarted, reconfigured or deleted**, and no administrator-level
  change was made anywhere.

  | Property | Isolated test instance | Shared workstation instance |
  |---|---|---|
  | Port | **3307** | 3306 |
  | Config | `C:\ProgramData\MySQL\MySQL80-P2PTest\my.ini` | `C:\ProgramData\MySQL\MySQL Server 8.0\my.ini` (**unchanged**) |
  | Data directory | `C:\ProgramData\MySQL\MySQL80-P2PTest\data\` | `C:\ProgramData\MySQL\MySQL Server 8.0\Data\` (**unchanged**) |
  | `server-id` | 2 | 1 (**unchanged**) |
  | `log_bin_trust_function_creators` | **1** | **0** (**unchanged**) |
  | Schemas | `p2p_platform_test` only | `p2p_platform_dev` only (**0 tables, 0 triggers — untouched**) |
  | Windows service | none — runs as a detached background process | `MySQL80` (Running, Automatic — untouched) |

  - **No elevation was used or bypassed.** `sc.exe create` was refused with `OpenSCManager FAILED 5: Access is denied.`, so the instance was started as an ordinary **detached background process** instead of a Windows service. Nothing in the machine's security configuration was circumvented.
  - **Least-privilege credentials.** The role `p2p_test`@`localhost` holds `GRANT USAGE ON *.*` plus `GRANT ALL PRIVILEGES ON p2p_platform_test.*` — and nothing else. Measured: **0 global privileges**, **0 administrative grants** (`SUPER`, `SYSTEM_VARIABLES_ADMIN`, `SYSTEM_USER`, `GRANT OPTION`, `CREATE USER`, `REPLICATION SLAVE`/`CLIENT` — all absent). The trigger capability comes from the *instance setting*, not from an over-privileged grant.
  - **The password is never exposed.** It is generated randomly (32 characters from a 59-character alphabet), stored in `C:\ProgramData\MySQL\MySQL80-P2PTest\secrets\p2p_test_password.txt` with `chmod 0600`, and read at runtime through `App\Support\P2PTestSecret`. It is never written to `.env`, never committed, and never printed to stdout, a log or a terminal.
  - **No environment file was modified.** `.env` and `.env.testing` are untouched. The test connection is a dedicated `mysql_p2ptest` entry in `config/database.php`, selected by `phpunit.xml`.
  - **A guard runs before every destructive command.** `storage/app/p2ptest_connection_guard.php` asserts — before `migrate:fresh` is allowed to run — that the resolved connection is port 3307, database `p2p_platform_test`, role `p2p_test@localhost`, `server-id=2`, the isolated datadir, `log_bin_trust_function_creators=1`, zero global privileges, and that the isolated instance holds no schema other than `p2p_platform_test`. `storage/app/p2ptest_artisan.php` refuses to run any command if that guard fails.
  - **The guard proved its worth.** On its first run it resolved the *development* connection instead and refused to proceed — which caught that the earlier session's "the dev database does not exist" finding was wrong: `p2p_platform_dev` **does** exist on 3306. Because the guard refused, no destructive command was ever run against it. Verified afterwards: **0 tables, 0 triggers**, `log_bin_trust_function_creators` still `0`.
  - **Operator tooling.** `php storage/app/p2ptest_instance.php start|stop|status` starts, stops and reports the isolated instance.

  #### Superseded: the "administrator change" that was never applied

  The following was prepared for the shared-instance route and was **NOT executed**,
  because the isolated-instance route made it unnecessary. It is retained only to
  record what would be required if the shared instance were ever used instead.

  ```sql
  SET PERSIST log_bin_trust_function_creators = 1;
  ```

  - **Scope: GLOBAL.** The variable is GLOBAL-only (`SET SESSION` → errno 1229). It affects **every schema and every user on the shared server**, not just `p2p_platform_test`. `SET PERSIST` writes it to `mysqld-auto.cnf` so it survives a restart.
  - **Security implications:** with the flag on, **any account holding `CREATE ROUTINE`, `TRIGGER` or `ALTER ROUTINE` can create triggers, stored functions and procedures without `SUPER`**. The flag grants no privilege itself; it removes a restriction from those who already hold them.
  - **Rollback:** `SET PERSIST log_bin_trust_function_creators = 0;`, or `RESET PERSIST log_bin_trust_function_creators;` to remove the persisted entry entirely.
  - **Restart:** not required; the variable takes effect immediately.

  **Never applied, and explicitly excluded:** `GRANT SUPER` or
  `GRANT SYSTEM_VARIABLES_ADMIN` to any application or test user. `SUPER` is
  effectively equivalent to full server control and is unnecessary here.
- [x] 2.6 Write the `Wallet` model test asserting that a wallet is created with its account in a single transaction, and that a wallet cannot be created without a valid `account_id`; verify the test passes

## 3. Negative-balance protection

**Covers** spec requirement *Wallet holds integer minor-unit balances*. **Design** D-1.

- [x] 3.1 Write a test asserting that a write setting `balance_minor` to a negative value is rejected by the database and the stored balance is unchanged; verify the test passes
- [x] 3.2 Write a test asserting that a write setting `reserved_minor` to a negative value is rejected; verify the test passes
- [x] 3.3 Confirm via `SHOW CREATE TABLE` that all three money columns are `bigint unsigned` and that no `DECIMAL`, `FLOAT`, or `DOUBLE` column exists anywhere in the two tables; verify the check output shows unsigned integer types only

## 4. Models and read scopes

**Covers** spec requirements *Wallet holds integer minor-unit balances*, *Wallet balances are
readable only through their own owner*. **Design** D-10, D-9.

- [x] 4.1 Create the `LedgerAccount` Eloquent model with `account_type` and `system_role` cast to
  their enum values, `next_sequence_number` cast to integer, and `$guarded` covering every column
  except the auto-increment id; verify the model instantiates and the casts return the expected
  types
- [x] 4.2 Create the `Wallet` Eloquent model with `balance_minor` and `reserved_minor` cast to
  integer, `$guarded` covering every column except the auto-increment id, and a `belongsTo`
  relation to `LedgerAccount`; verify the model instantiates and the relation resolves
- [x] 4.3 Assert in a test that assigning a non-integer value to `balance_minor` is rejected
  rather than silently truncated or rounded; verify the test passes
- [x] 4.4 Add a wallet query scope that constrains results to a given `owner_user_id`, and a test
  asserting that a peer querying for another user's wallet receives no row; verify the test
  passes

## 5. Documentation

- [x] 5.1 Add a short section to the project's existing documentation recording that `wallets`
  holds the only mutable balance in the system, that `ledger_accounts` holds no balance column,
  and that `next_sequence_number` is a sequence allocator rather than a balance; verify the
  section exists and states all three points
- [x] 5.2 Record in the same documentation that `H-1` (SYSTEM accounts) is 🟠 Proposed (not
  approved), that the schema accommodates it, and that no SYSTEM account or funding path exists;
  verify the section states the status and the absence

---

## 6. Integration verification

*Cross-cutting checks only. Every test owed by groups 1–5 landed in its own group.*

- [x] 6.1 Run the full test suite and verify it passes with no pre-existing test broken; verify
  the suite's exit status is success
- [x] 6.2 Verify the two migrations apply cleanly in order against a scratch database and roll
  back cleanly in reverse order; verify `migrate:rollback` leaves no `ledger_accounts` or
  `wallets` table behind
- [x] 6.3 Verify `P2P_SPECIFICATION.md` is byte-identical to its state before this change (md5
  `a9e2b6cc16d140a8de83a69450f83026`); verify the checksum matches
- [x] 6.4 Verify no `.env` file was modified and no commit or push occurred; verify `git status`
  shows only the intended new files and `.env` is unchanged

---

## ⛔ BLOCKED — not scheduled in this change

These are recorded so they are not forgotten. Each names its blocker. **None is silently
scheduled.**

- [ ] ⛔ **BLOCKED by M-2** (canonicalization, ⚪ BLOCKED) — implement the hash chain over a
  canonical body. *Blocks BMAD Epic 1 stories 2, 8 and 9.* Not part of this change.
- [ ] ⛔ **BLOCKED by M-3** (genesis literal, ⚪ Open) — seed the chain head's genesis value.
  *Blocks BMAD Epic 1 story 5's chain bootstrap.* Not part of this change.
- [ ] ⛔ **BLOCKED by H-1** (SYSTEM accounts, 🟠 Proposed, not approved) — create SYSTEM accounts
  and the funding entry pair. *The schema accommodates it; this change implements neither.* Not
  part of this change.
- [ ] ⛔ **BLOCKED by D1 / H-6** (🟠 Proposed, not approved) — finalise the
  `ledger_entries.status` shape and the hashed-body field list. *Blocks BMAD Epic 1 stories 2, 3
  and 9.* Not part of this change.
- [ ] ⛔ **BLOCKED by G4** (⚙️ PENDING) — apply `INSERT`/`SELECT`-only privileges on the chain
  tables to a separate runtime role. *An infrastructure task, not a migration.* Not part of this
  change.

## Workflow follow-up

- Apply the change with the `openspec-apply-change` workflow once the four ⛔ decisions above are
  either resolved or confirmed to remain out of scope for this story.
- Reconcile the BMAD ticket: mark `story-ledger-schema-foundation` built in
  `_bmad-output/initiative-p2p-settlement/tickets/epic-ledger-core/tickets/tickets.toml`, and
  append to the epic's `.memlog.md`.
- Archive the change only after the BMAD review requirements for Epic 1 are satisfied.
