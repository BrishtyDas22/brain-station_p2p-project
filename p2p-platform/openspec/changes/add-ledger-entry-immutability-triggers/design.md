# Design: add-ledger-entry-immutability-triggers

## Context

BMAD Epic 1 (`ledger-core`), story 3 — `ledger-entry-immutability-triggers`. Story 2 built the
`ledger_entries` table; this story makes it **append-only in fact** rather than by convention.

The requirement is **R7** (parent R6, A5, D1): *the append-only guarantee is enforced at the data
layer, by three mechanisms together*. This change builds mechanisms **2** and **3**. Mechanism
**1** is **G4**, an infrastructure task, and is explicitly out of scope.

The acceptance criterion is **AC6**: *a direct `UPDATE` against `ledger_entries` raises an error
from the trigger; a direct `DELETE` raises an error from the trigger. Both are rejected even when
issued from a privileged session.*

## Goals

- A direct `UPDATE` against `ledger_entries` is rejected by the database.
- A direct `DELETE` against `ledger_entries` is rejected by the database.
- Both rejections hold **from a privileged session**, not merely from an unprivileged one.
- A rejected write leaves the row **unchanged**.
- The triggers are **mandatory**: if they cannot be installed, the migration fails closed rather
  than leaving a schema that looks correct while enforcing nothing.
- No foreign key on `ledger_entries` carries a referential action.

## Non-Goals

- **G4** — the privilege revocation. Not a migration; not implemented here.
- The `chain_close_records` triggers (story 5).
- The `transactions` / `reservations` triggers (Epic 3).
- Any hashing, canonicalization or chain verification (M-2 / M-3).
- Any transfer, reversal or reconciliation logic.

---

## The DDL

The spine (§"The append-only guarantee is enforced by three database-level mechanisms") specifies
the mechanism but not the trigger bodies. The bodies below follow MySQL 8.0 trigger syntax and the
spine's stated contract: `SIGNAL SQLSTATE '45000'`.

```sql
CREATE TRIGGER `trg_ledger_entries_no_update`
BEFORE UPDATE ON `ledger_entries`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ledger_entries is append-only. Rows are never updated. Correct by appending a compensating entry.';
END
```

```sql
CREATE TRIGGER `trg_ledger_entries_no_delete`
BEFORE DELETE ON `ledger_entries`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ledger_entries is append-only. Rows are never deleted. Correct by appending a compensating entry.';
END
```

### Why the bodies are unconditional

The trigger rejects **every** `UPDATE` and **every** `DELETE`, without comparing old and new
values. This is deliberate.

A trigger that permits a "no-op" update (`SET x = x`) would still be a hole: `UPDATE` can change
any column, and a trigger that inspects a column list must enumerate them, and a newly added
column would be silently unprotected. The unconditional form cannot be bypassed by any statement,
and it cannot be defeated by adding a column later.

### Why `SIGNAL SQLSTATE '45000'`

`45000` is the generic "unhandled user-defined exception" SQLSTATE. It is what the spine
specifies, and it is what `trg_wallets_account_id_immutable` already uses in this codebase, so
the two triggers behave identically for callers. A caller sees SQLSTATE `45000` with errno `1644`
and the `MESSAGE_TEXT` above.

### Why `MESSAGE_TEXT` says "compensating entry"

The rejection message is part of the operator experience. An operator who tries to fix a
ledger row by editing it should be told the correct action: **append a compensating entry**. That
is SPEC §4.2's rule, and a bare "operation not permitted" would send them looking for a
`--force` flag.

### The failure path

MySQL DDL is not transactional. If the second trigger fails to install, the first is already
attached and the table exists. The migration therefore **drops `ledger_entries` on the failure
path** and rethrows. A schema with one append-only trigger and no DELETE trigger is a schema
that looks correct while enforcing half the guarantee — worse than no schema, because a reader
of the DDL would believe the guarantee holds.

This is the same fail-closed pattern story 1 established for
`trg_wallets_account_id_immutable`, and it uses the same helper.

---

## Mechanism 3 — the cascade prohibition

The spine's third mechanism is *no `ON DELETE CASCADE` / `ON UPDATE CASCADE` on any FK
referencing `ledger_entries`*.

`ledger_entries` has exactly one foreign key today: `fk_ledger_entries_account`, from
`account_id` to `ledger_accounts(id)`. It declares **no** referential action, so MySQL's
`RESTRICT` default applies — deleting an account that has entries fails loudly rather than
cascading.

This is **already** true from story 2, and story 2's test suite already asserts it. This change
**re-asserts** it in the immutability test class, because mechanism 3 belongs to R7 and belongs
to this story's acceptance.

**The forward-looking caveat.** `chain_close_records` (story 5) will carry a foreign key to
`ledger_entries(entry_id)`. When that FK is created, it too must declare **no** referential
action — a `ON DELETE CASCADE` from `chain_close_records` would silently delete ledger entries,
destroying the exact history this story exists to protect. That is story 5's obligation, and it
is recorded here so it is not forgotten.

### The `transactions` foreign key

`ledger_entries.transaction_id` has **no** foreign key yet — the `transactions` table belongs to
Epic 3 and does not exist (story 2, task 2.2). When Epic 3 adds
`fk_ledger_entries_transaction → transactions(id)`, it too must carry no referential action.
Same obligation, same reason.

---

## The installer

`App\Support\MySqlTriggerSupport::install()` is reused unchanged. It already:

1. Probes whether the server can create a trigger, and reports the **precise** blocker
   (missing `TRIGGER` grant vs. server configuration) when it cannot.
2. Executes the `CREATE TRIGGER` statement.
3. **Verifies** the trigger is present in `information_schema.triggers` afterwards, and throws
   `LedgerTriggerException` if it is not — a server that reports success without creating the
   object is caught.
4. Is **fail-closed** by default: the trigger is mandatory, so a failure aborts the migration.
5. Asserts the supplied DDL begins with `CREATE TRIGGER`, so a caller cannot smuggle
   interpolated input into the DDL.

The isolated-test-schema escape hatch remains in place as a safety net, but it is **switched
off** in the test configuration, so the suite runs against a schema whose invariant is genuinely
enforced. The tests in this change assert that, too.

---

## The migration

`database/migrations/2026_10_26_000005_add_ledger_entry_immutability_triggers.php`.

```php
public function up(): void
{
    // Install the BEFORE UPDATE trigger.
    MySqlTriggerSupport::install(
        'trg_ledger_entries_no_update',
        'ledger_entries',
        <<<'SQL'
            CREATE TRIGGER `trg_ledger_entries_no_update`
            BEFORE UPDATE ON `ledger_entries`
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ledger_entries is append-only. Rows are never updated. Correct by appending a compensating entry.';
            END
        SQL,
    );

    // Install the BEFORE DELETE trigger.
    MySqlTriggerSupport::install(
        'trg_ledger_entries_no_delete',
        'ledger_entries',
        <<<'SQL'
            CREATE TRIGGER `trg_ledger_entries_no_delete`
            BEFORE DELETE ON `ledger_entries`
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ledger_entries is append-only. Rows are never deleted. Correct by appending a compensating entry.';
            END
        SQL,
    );
}

public function down(): void
{
    // The triggers are dropped with the table. MySQL drops a table's triggers
    // automatically, so there is nothing to uninstall here.
    Schema::dropIfExists('ledger_entries');
}
```

### Why `down()` drops the table rather than the triggers

The migration that *created* `ledger_entries` is `2026_10_26_000003`. Rolling **this** migration
back must not leave a table whose append-only guarantee is gone — that is the whole point of the
story. Dropping the table removes the triggers with it (MySQL drops a table's triggers
automatically), and the rollback continues to the previous migration, which recreates the table
without triggers.

**Consequence, stated plainly:** rolling back this migration **destroys `ledger_entries` and its
data.** That is correct here — the table is empty in Phase 1, no entry is ever written, and a
table without its append-only guarantee must not survive a rollback. It is called out because a
later reader must not be surprised by it.

---

## Data-layer enforcement

| Guarantee | Enforced by | In this change? |
|---|---|---|
| An entry is never updated | `trg_ledger_entries_no_update` | ✅ Yes |
| An entry is never deleted | `trg_ledger_entries_no_delete` | ✅ Yes |
| No cascade can destroy an entry | No referential action on any FK | ✅ Yes (asserted) |
| The runtime role cannot `UPDATE`/`DELETE` | `GRANT` on a separate runtime role | ❌ **No — G4** |
| The append-only guarantee holds end to end | All of the above | ❌ **No — G4 outstanding** |

---

## Alternatives considered

**A single trigger for both events.** MySQL does not support one trigger spanning two events —
`CREATE TRIGGER` accepts exactly one `BEFORE`/`AFTER` and one `UPDATE`/`INSERT`/`DELETE`. Two
triggers are the only shape available. Rejected as impossible, not merely undesirable.

**Permitting no-op updates (`SET x = x`).** Rejected: it requires enumerating the columns to
compare, and a column added later would be unprotected. The unconditional trigger cannot be
bypassed and does not need to be maintained.

**Enforcing append-only with a `BEFORE UPDATE` trigger that only guards the hashed columns.**
Rejected: `balance_after` and `created_at` are outside the hashed body, so a mutation of them
would be undetectable by the chain verifier. The chain verifies the hashed fields; the trigger
must protect the whole row.

**Enforcing append-only in application code only.** Rejected by the spine (A5): application code
can be bypassed by a migration, an operator script, or a future service. The requirement is
explicitly *data-layer*.

**A `BEFORE DELETE` trigger with `SIGNAL` plus revoking the `DELETE` privilege.** Both. The
privilege revocation is G4 and is out of scope; the trigger is what covers a privileged session.

---

## Risks

| Risk | Mitigation |
|---|---|
| The migration aborts on a server that cannot create triggers, leaving no schema. | Deliberate. The table is recreated by re-running `migrate`, and a schema without the append-only guarantee must not exist. The isolated instance this project owns (port 3307) has `log_bin_trust_function_creators=1`, so the migration applies cleanly there. |
| A future code path needs to update a ledger row. | There is no such path, and there never should be — SPEC §4.2 requires compensating entries. The trigger's message says so. |
| The rollback destroys `ledger_entries` data. | The table is empty in Phase 1 and no entry is ever written. A rollback that leaves a table without its guarantee would be worse. Stated explicitly in the migration's docblock. |
| A future FK to `ledger_entries` adds a cascade. | Mechanism 3 is re-asserted by a test in this change. `chain_close_records` (story 5) and the Epic 3 `transactions` FK are named in the design as carrying the same obligation. |

---

## Migration plan

1. `2026_10_26_000003` creates `ledger_entries` (already applied).
2. `2026_10_26_000005` installs the two triggers (this change).
3. `down()` drops the table, removing both triggers with it.

---

## Open questions

- **G4** — when and by whom is the runtime role's `UPDATE`/`DELETE` privilege revoked? This is an
  infrastructure task with no owner recorded. **It is the one outstanding piece of R7.**
- Should `chain_close_records` (story 5) carry its own append-only triggers? The spine says
  yes — "chain_close_records is immutable too" — but that is story 5's work, not this change's.
