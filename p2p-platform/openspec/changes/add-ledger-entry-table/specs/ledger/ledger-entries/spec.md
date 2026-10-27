# ledger/ledger-entries Specification

## ADDED Requirements

### Requirement: The ledger entry table exists

The system SHALL provide a `ledger_entries` table holding the append-only double-entry ledger.

Its columns SHALL be exactly: `entry_id`, `transaction_id`, `account_id`, `direction`, `amount_minor`,
`currency`, `account_sequence_number`, `balance_after`, `prev_hash`, `entry_hash`, `created_at`.

#### Scenario: The columns exist

- **WHEN** the table's columns are inspected
- **THEN** exactly those eleven columns are present

#### Scenario: A ledger entry row can be written

- **WHEN** a row is inserted with a valid account, a direction, a positive amount, a currency of `'BDT'`, an
  account sequence number, a previous hash, an entry hash and a creation timestamp
- **THEN** the row is accepted and stored
- **AND** the amount is stored as an exact integer number of Poisha

#### Scenario: The table is engine InnoDB and charset utf8mb4

- **WHEN** the table's storage engine and character set are inspected
- **THEN** the engine is `InnoDB`
- **AND** the character set is `utf8mb4`

### Requirement: Each ledger entry column carries the declared type

`entry_id` SHALL be `CHAR(26)` NOT NULL and the primary key. `transaction_id`, `account_id`,
`amount_minor` and `account_sequence_number` SHALL be `BIGINT UNSIGNED` NOT NULL. `direction` SHALL be
`ENUM('DEBIT','CREDIT')` NOT NULL. `currency` SHALL be `CHAR(3)` NOT NULL with default `'BDT'`.
`balance_after` SHALL be `BIGINT UNSIGNED` and nullable. `prev_hash` and `entry_hash` SHALL be `CHAR(64)`
NOT NULL. `created_at` SHALL be `DATETIME(6)` NOT NULL.

#### Scenario: The declared types are present

- **WHEN** the table's columns are inspected
- **THEN** each column carries the type, nullability and default stated above

#### Scenario: The primary key is the entry id

- **WHEN** the table's primary key is inspected
- **THEN** it is `entry_id` alone

#### Scenario: The created-at timestamp keeps microsecond precision

- **WHEN** a row is written with a creation timestamp carrying microseconds
- **THEN** the stored timestamp keeps its microsecond precision, because it is inside the chained body
  (SPEC §5.1) and must not be truncated between write and verify

### Requirement: The per-account sequence number is unique per account

The table SHALL carry a UNIQUE index over `(account_id, account_sequence_number)`.

Two entries for the same account SHALL NEVER share a sequence number. This is the data-layer half of the
gapless-per-account guarantee (SPEC §4.4, A6); the gap itself is prevented by the allocator that increments
the counter inside the posting transaction (story 4).

#### Scenario: A duplicate sequence number for the same account is rejected

- **WHEN** an entry is inserted for account 7 with `account_sequence_number = 12`
- **AND** a second entry is inserted for account 7 with `account_sequence_number = 12`
- **THEN** the second insert is rejected by the database with a unique-constraint violation

#### Scenario: The same sequence number on different accounts is accepted

- **WHEN** an entry is inserted for account 7 with `account_sequence_number = 12`
- **AND** another entry is inserted for account 9 with `account_sequence_number = 12`
- **THEN** both are accepted

### Requirement: An entry's amount is strictly positive

The table SHALL enforce `amount_minor > 0` through a CHECK constraint, and SHALL store it as
`BIGINT UNSIGNED`.

A zero-amount entry is not a degenerate transfer — it is an accounting error, and the database rejects it.

#### Scenario: A zero amount is rejected

- **WHEN** an entry is inserted with `amount_minor = 0`
- **THEN** the insert is rejected by the database with a CHECK-constraint violation

#### Scenario: A negative amount is rejected

- **WHEN** an entry is inserted with `amount_minor = -1`
- **THEN** the insert is rejected by the database with an out-of-range violation

### Requirement: The entry hash is unique

The table SHALL carry a UNIQUE index over `entry_hash`.

A duplicate hash means either a hash collision or a duplicated insert. Both are bugs worth failing loudly
on rather than storing two rows that attest to the same content.

#### Scenario: A duplicate entry hash is rejected

- **WHEN** an entry is inserted with `entry_hash = 'a1b2…'`
- **AND** another entry is inserted with the same `entry_hash = 'a1b2…'`
- **THEN** the second insert is rejected by the database with a unique-constraint violation

### Requirement: The currency is a single currency

The table SHALL carry a CHECK constraint restricting `currency` to `'BDT'`, and SHALL store it as `CHAR(3)`.

#### Scenario: A currency other than BDT is rejected

- **WHEN** an entry is inserted with `currency = 'USD'`
- **THEN** the insert is rejected by the database with a CHECK-constraint violation

#### Scenario: BDT is accepted

- **WHEN** an entry is inserted with `currency = 'BDT'`
- **THEN** the insert is accepted

### Requirement: `balance_after` is never negative and is never authoritative

The table SHALL store `balance_after` as a nullable `BIGINT UNSIGNED`, with a CHECK constraint permitting
`NULL` and forbidding a negative value.

`balance_after` SHALL NOT be part of the chained body (SPEC §5.2): it is not independently verifiable,
because nobody can recompute it under concurrency. It is kept for display and debugging only. The ledger
entries are the authority.

#### Scenario: A negative balance_after is rejected

- **WHEN** an entry is inserted with `balance_after = -1`
- **THEN** the insert is rejected by the database

#### Scenario: A null balance_after is accepted

- **WHEN** an entry is inserted with `balance_after = NULL`
- **THEN** the insert is accepted

### Requirement: The entry belongs to a real ledger account

The table SHALL carry a foreign key from `account_id` to `ledger_accounts(id)`, with **no** `ON DELETE` and
**no** `ON UPDATE` referential action.

#### Scenario: An entry naming a non-existent account is rejected

- **WHEN** an entry is inserted with an `account_id` that does not exist in `ledger_accounts`
- **THEN** the insert is rejected by the database with a foreign-key violation

#### Scenario: Deleting an account that has entries is rejected

- **WHEN** an account that has at least one ledger entry is deleted
- **THEN** the deletion is rejected by the database with a foreign-key violation

#### Scenario: No foreign key carries a referential action

- **WHEN** the table's DDL is inspected
- **THEN** no foreign key on the table declares `ON DELETE` or `ON UPDATE`

### Requirement: The two reconciliation scans are supported

The table SHALL carry:

- an index over `(transaction_id, direction)` — the double-entry scan (Invariant 1);
- an index over `(account_id, created_at)` — the per-account timeline and the by-year reconciliation scan
  (Invariant 3, A18).

#### Scenario: The indexes exist

- **WHEN** the table's indexes are inspected
- **THEN** an index over `(transaction_id, direction)` is present
- **AND** an index over `(account_id, created_at)` is present

### Requirement: The money path never truncates

The `LedgerEntry` model SHALL cast `amount_minor` and `balance_after` through the `IntegerPoisha` cast, which
throws `InvalidArgumentException` on a value that is not an exact integer.

A float or a decimal string assigned to a money attribute MUST NOT be silently truncated or rounded.

#### Scenario: Assigning a decimal string is rejected

- **WHEN** `amount_minor` is assigned the string `'12.34'`
- **THEN** an `InvalidArgumentException` is thrown
- **AND** the attribute holds no truncated value

#### Scenario: Assigning an exact integer is accepted

- **WHEN** `amount_minor` is assigned the integer `1234`
- **THEN** the attribute holds the integer `1234`

#### Scenario: A null balance_after stays null

- **WHEN** a `LedgerEntry` is read from the database with `balance_after` of `NULL`
- **THEN** the `balance_after` attribute is `null`, not `0`

### Requirement: The direction is a typed value

The `LedgerEntry` model SHALL cast `direction` to a backed enum with the cases `DEBIT` and `CREDIT`.

#### Scenario: The direction reads back as an enum

- **WHEN** a `LedgerEntry` is read from the database
- **THEN** its `direction` attribute is a `LedgerDirection` instance, not a raw string

### Requirement: The entry carries no `status` column, and the decision stays open

The `ledger_entries` table SHALL NOT carry a `status` column in this change.

**H-6 / D1 is 🟠 Proposed (not approved).** The column's presence or absence is therefore NOT decided here.
A separate, reversible migration SHALL be prepared to add it, and SHALL NOT be applied. Reversing the
decision later SHALL require one migration and no data rewrite.

#### Scenario: The table has no status column

- **WHEN** the table's columns are inspected
- **THEN** no column named `status` is present

#### Scenario: The status migration exists but is not applied

- **WHEN** the migration files are listed
- **THEN** a migration adding `status` to `ledger_entries` is present
- **AND** it is not among the migrations applied to the schema

### Requirement: The `transactions` foreign key is deferred, and the omission is recorded

The `ledger_entries` table SHALL carry `transaction_id` as a `BIGINT UNSIGNED NOT NULL` column with an index
over `(transaction_id, direction)`.

The table SHALL NOT carry a foreign key to `transactions(id)` in this change, because that table belongs to
Epic 3 and does not exist. This is an **omission, not a design choice**, and it SHALL be recorded in the
change's tasks and in the project documentation.

#### Scenario: The column and its index exist

- **WHEN** the table's columns and indexes are inspected
- **THEN** a `transaction_id` column of type `BIGINT UNSIGNED` exists, declared NOT NULL
- **AND** an index over `(transaction_id, direction)` exists

#### Scenario: The omission is recorded

- **WHEN** the change's task list is read
- **THEN** it names the omitted foreign key, the reason, and the Epic 3 work that closes it
