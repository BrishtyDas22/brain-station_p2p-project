# Spec Delta

## Purpose

The money substrate for the P2P settlement system: the ledger accounts and wallets that hold and
attribute every unit of user money, and the data-layer constraints that make balances
non-negative, the wallet↔account mapping immutable and one-to-one, and reserved funds never
exceeding posted funds.

## ADDED Requirements

### Requirement: Wallet holds integer minor-unit balances

The system SHALL store a wallet's posted balance and reserved balance as unsigned integer
minor units (Poisha), where 1 BDT = 100 Poisha. The system MUST NOT store, compute, or transmit
a wallet balance as a floating-point or decimal value. The system SHALL reject any write that
would place a negative value in a balance column.

#### Scenario: Balance is stored in integer minor units
- **WHEN** a wallet is created with a posted balance of 50000
- **THEN** the stored balance is the integer 50000, representing 500 BDT 00 Poisha
- **AND** the value is stored in an unsigned integer column, not a float or decimal

#### Scenario: Negative balance write is rejected
- **WHEN** any write attempts to set a wallet's posted balance to a negative value
- **THEN** the write is rejected by the database and the stored balance is unchanged

#### Scenario: Currency is a three-letter ISO-4217 code
- **WHEN** a wallet is created
- **THEN** its currency is stored as a three-letter ISO-4217 code defaulting to `BDT`

### Requirement: Reserved balance never exceeds posted balance

The system SHALL enforce that a wallet's reserved balance is less than or equal to its posted
balance at all times, and SHALL reject any write that would violate this. The system SHALL treat
the reserved balance as a derived cache of active reservation records rather than as an
independent source of truth.

#### Scenario: Reservation exceeding the balance is rejected
- **WHEN** a write attempts to set a wallet's reserved balance above its posted balance
- **THEN** the write is rejected by the database and neither balance is changed

#### Scenario: Reservation within the balance is accepted
- **WHEN** a write sets a wallet's reserved balance to a value less than or equal to its posted
  balance
- **THEN** the write is accepted and the reserved balance reflects the new value

#### Scenario: A reservation alone never moves the posted balance
- **WHEN** funds are reserved against a wallet
- **THEN** the wallet's posted balance is unchanged
- **AND** the wallet's reserved balance increases

### Requirement: Each wallet maps to exactly one ledger account

The system SHALL map each wallet to exactly one ledger account, SHALL enforce that no two wallets
share a ledger account, and SHALL enforce that no user owns two ledger accounts for the same
wallet. The system SHALL reject any attempt to change a wallet's ledger account after the wallet
exists.

#### Scenario: A wallet gets exactly one ledger account
- **WHEN** a wallet is created
- **THEN** it is bound to exactly one ledger account and that binding is recorded

#### Scenario: A second wallet cannot claim the same ledger account
- **WHEN** an attempt is made to bind a second wallet to a ledger account that is already bound
- **THEN** the write is rejected by the database

#### Scenario: A user cannot own two ledger accounts for one wallet
- **WHEN** an attempt is made to create a second ledger account for a user who already has one
- **THEN** the write is rejected by the database

#### Scenario: The wallet-to-account binding cannot be changed
- **WHEN** an attempt is made to repoint an existing wallet at a different ledger account
- **THEN** the write is rejected by the database and the original binding is preserved

### Requirement: Ledger accounts may be system-owned

The system SHALL distinguish user-owned ledger accounts from system-owned ledger accounts, and
SHALL allow a ledger account to have no owner. A ledger account with no owner MUST be declared a
system account and MUST NOT be bindable to a wallet.

#### Scenario: A system account has no owner
- **WHEN** a system ledger account exists
- **THEN** it has no owning user and is declared as a system account

#### Scenario: A system account cannot back a wallet
- **WHEN** an attempt is made to bind a wallet to a system ledger account
- **THEN** the write is rejected by the database

#### Scenario: No system account is created and no funding path exists
- **WHEN** the system is deployed with this capability alone
- **THEN** no system ledger account exists and no path moves money into a wallet
- **AND** the capability to fund a wallet from a system account remains unimplemented pending
  approval of H-1 (🟠 Proposed, not approved)

### Requirement: An account's declared type agrees with its owner

The system SHALL enforce at the data layer that a ledger account's declared type agrees with
whether it has an owning user: a user account MUST have an owner, and a system account MUST NOT.

#### Scenario: A user account without an owner is rejected
- **WHEN** an attempt is made to store a user account with no owning user
- **THEN** the write is rejected by the database

#### Scenario: A system account with an owner is rejected
- **WHEN** an attempt is made to store a system account with an owning user
- **THEN** the write is rejected by the database

### Requirement: Ledger accounts carry a per-account sequence counter

The system SHALL give each ledger account a monotonically increasing sequence counter, and SHALL
allocate the next value for an account atomically so that concurrent allocations for the same
account never return the same number. The system MUST NOT derive the next sequence number by
reading the maximum existing value and adding one.

#### Scenario: Sequential allocation for one account
- **WHEN** sequence numbers are allocated for an account twice, in order
- **THEN** the two allocations return consecutive values

#### Scenario: Concurrent allocation never collides
- **WHEN** two sequence allocations for the same account run concurrently
- **THEN** they return two distinct values, and no sequence number is ever allocated twice for
  that account

#### Scenario: Allocation does not depend on the maximum existing value
- **WHEN** a sequence number is allocated for an account
- **THEN** the value comes from the account's own counter
- **AND** the system does not compute it from the maximum sequence number already stored

> **PENDING — A6 / G2 (⚙️ Architecture default).** The counter's **placement** on the ledger
> account, rather than elsewhere, is an architecture choice awaiting sign-off. The requirement
> that allocation is atomic and collision-free is not pending.

### Requirement: Wallet balances are readable only through their own owner

The system SHALL make a wallet's balance readable only by the user who owns that wallet, and
SHALL reject a request from one user for another user's wallet balance before any balance data is
returned.

#### Scenario: An owner reads their own balance
- **WHEN** the owner requests their wallet's balance
- **THEN** the balance is returned

#### Scenario: A peer requests another user's balance
- **WHEN** a user requests a wallet belonging to a different user
- **THEN** the request is rejected and no balance value is disclosed

### Requirement: Ledger account identifiers are stable

The system SHALL treat a ledger account's identifier as permanent once the account is created. The
identifier of a ledger account appears inside the cryptographic hash of every ledger entry
written against it, and SHALL therefore never be changed, reused, or reassigned.

#### Scenario: The identifier survives for the account's lifetime
- **WHEN** a ledger account is referenced by a ledger entry
- **THEN** the same identifier identifies that account in every later reference

#### Scenario: Deleting the account removes its entries' referent
- **WHEN** an attempt is made to delete a ledger account that has ledger entries
- **THEN** the deletion is rejected by the database

> **PENDING — H-6 (🟠 Proposed, not approved).** The requirement that ledger *entries* carry no
> mutable status column is the related unapproved decision (D1). It does not change this
> requirement: an account identifier is permanent under either outcome.

### Requirement: No production money movement is enabled by this capability

The system SHALL NOT enable real-money production transfers, and SHALL NOT enable production
reversal execution, on the basis of this capability alone. These remain disabled until the
reversal time window, the reversal fee policy, the higher-value reversal approval threshold, and
the transaction and cumulative transfer ceilings are explicitly approved.

#### Scenario: Production transfer execution remains refused
- **WHEN** the capability is deployed without the transfer ceilings being approved
- **THEN** production real-money transfer execution is refused

#### Scenario: Production reversal execution remains refused
- **WHEN** the capability is deployed without the reversal policies being approved
- **THEN** production reversal execution is refused
