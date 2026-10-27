# ledger/ledger-entries Specification

## ADDED Requirements

### Requirement: A ledger entry can never be updated

The `ledger_entries` table SHALL carry a `BEFORE UPDATE` trigger that rejects **every** `UPDATE`
statement with `SIGNAL SQLSTATE '45000'`.

The trigger SHALL be unconditional: it SHALL NOT permit a "no-op" update. It SHALL NOT enumerate
or compare column values, so that a column added later is protected without the trigger being
modified.

The rejection message SHALL tell the caller that corrections are made by appending a compensating
entry, not by editing a row (SPEC §4.2).

#### Scenario: A direct update is rejected

- **WHEN** an `UPDATE` statement is executed against `ledger_entries`
- **THEN** the database rejects it with SQLSTATE `45000`
- **AND** the row is left unchanged

#### Scenario: A no-op update is also rejected

- **WHEN** an `UPDATE` statement that sets a column to its existing value is executed against
  `ledger_entries`
- **THEN** the database rejects it with SQLSTATE `45000`

#### Scenario: The rejection holds from a privileged session

- **WHEN** an `UPDATE` statement is executed against `ledger_entries` by a session holding full
  privileges on the schema
- **THEN** the database rejects it with SQLSTATE `45000`

### Requirement: A ledger entry can never be deleted

The `ledger_entries` table SHALL carry a `BEFORE DELETE` trigger that rejects **every** `DELETE`
statement with `SIGNAL SQLSTATE '45000'`.

The trigger SHALL be unconditional and SHALL NOT compare row values.

#### Scenario: A direct delete is rejected

- **WHEN** a `DELETE` statement is executed against `ledger_entries`
- **THEN** the database rejects it with SQLSTATE `45000`
- **AND** the row is still present afterwards

#### Scenario: A delete of every row is rejected

- **WHEN** a `DELETE` statement with no `WHERE` clause is executed against `ledger_entries`
- **THEN** the database rejects it with SQLSTATE `45000`
- **AND** no row is removed

#### Scenario: The rejection holds from a privileged session

- **WHEN** a `DELETE` statement is executed against `ledger_entries` by a session holding full
  privileges on the schema
- **THEN** the database rejects it with SQLSTATE `45000`

### Requirement: The triggers are installed and verified

Both triggers SHALL be installed by a migration, and their presence SHALL be verified against
`information_schema.triggers` after installation.

If a trigger cannot be installed, or if the server reports success but the trigger is absent, the
migration SHALL **fail closed**: it SHALL drop the `ledger_entries` table and abort. A table
without its append-only guarantee SHALL NOT be left in place.

#### Scenario: The triggers exist

- **WHEN** the table's triggers are inspected
- **THEN** a `BEFORE UPDATE` trigger named `trg_ledger_entries_no_update` is present on
  `ledger_entries`
- **AND** a `BEFORE DELETE` trigger named `trg_ledger_entries_no_delete` is present on
  `ledger_entries`

#### Scenario: A failed installation leaves no half-built schema

- **WHEN** the trigger installation fails
- **THEN** the `ledger_entries` table is dropped
- **AND** the migration aborts

#### Scenario: The guarantee is not merely asserted

- **WHEN** the trigger list is read
- **THEN** both triggers are reported present
- **AND** a test proves each one actually fires

### Requirement: No foreign key on `ledger_entries` carries a referential action

The `ledger_entries` table SHALL carry no `ON DELETE` and no `ON UPDATE` referential action on any
foreign key. A cascade from a parent table would silently destroy ledger history — the one thing
this table exists to prevent.

This requirement extends to any foreign key **added to** `ledger_entries` later, and to any
foreign key **referencing** `ledger_entries` later, including the deferred
`fk_ledger_entries_transaction` and the future `chain_close_records` reference.

#### Scenario: No foreign key declares a referential action

- **WHEN** the table's DDL is inspected
- **THEN** no foreign key on the table declares `ON DELETE` or `ON UPDATE`

#### Scenario: A parent row with entries cannot be deleted

- **WHEN** an account that has at least one ledger entry is deleted
- **THEN** the deletion is rejected by the database with a foreign-key violation

### Requirement: The append-only guarantee's remaining gap is recorded

The `INSERT`/`SELECT`-only privilege on `ledger_entries` for a separate runtime role is **G4**, an
infrastructure task and not a migration. It is **NOT** implemented by this change.

The documentation SHALL record that the runtime-role privilege revocation is outstanding, so the
append-only guarantee is not mistaken for being fully held when only the trigger mechanisms are
in place.

#### Scenario: The outstanding mechanism is documented

- **WHEN** the ledger schema documentation is read
- **THEN** it states that the runtime role's `UPDATE`/`DELETE` privilege has not been revoked
- **AND** it states that the trigger mechanisms are installed and enforced
