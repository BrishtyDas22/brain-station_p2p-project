# Spec Delta

## Purpose

Withdraw Money: a member takes their own available wallet funds out to a third-party account
through a **simulated** bKash, Nagad or Bank Transfer payout.

The gateway is simulated because no external provider API is available in this environment.
The **accounting is not** simulated: the money leaves the user's real, ledger-backed wallet
through a balanced double-entry posting against a `PAYOUT` sink.

## ADDED Requirements

### Requirement: A member may request a withdrawal from the available wallet balance

The system SHALL allow a member whose identity is approved to request a withdrawal of their
own available wallet funds, through one of bKash, Nagad or Bank Transfer. The system SHALL
take the amount from the member's **available** balance, and SHALL refuse a request larger
than that balance.

#### Scenario: A member requests a withdrawal
- **WHEN** a member with an approved identity requests 3,000 BDT to a bKash mobile number
- **THEN** a pending withdrawal attempt is created
- **AND** no money has moved yet

#### Scenario: A withdrawal beyond the available balance is refused
- **WHEN** a member requests more than their available wallet balance
- **THEN** the request is refused
- **AND** the balance is unchanged

#### Scenario: A member without an approved identity is refused
- **WHEN** a member whose identity is not approved requests a withdrawal
- **THEN** the request is refused
- **AND** no withdrawal attempt is created

#### Scenario: No real credential is ever collected
- **WHEN** a member completes the withdrawal form
- **THEN** only a mobile number, or a bank name and the last four digits of an account, are stored
- **AND** no PIN, OTP, password or full card number is requested or stored

### Requirement: A confirmed withdrawal moves the money out through a balanced posting

The system SHALL, when a withdrawal attempt is confirmed, post a **balanced pair of
`ledger_entries`** belonging to one `transactions` row — a DEBIT against the user's ledger
account and an equal CREDIT against the `PAYOUT` system account — so that
`SUM(DEBIT) = SUM(CREDIT)` for that transaction. The system SHALL advance the wallet
projection by exactly the withdrawn amount, and SHALL NEVER assign it an arbitrary number.

#### Scenario: A confirmed withdrawal debits the wallet
- **WHEN** a withdrawal of 3,000 BDT is confirmed
- **THEN** exactly two `ledger_entries` exist for that withdrawal
- **AND** the DEBIT and the CREDIT are of equal magnitude
- **AND** `SUM(DEBIT) = SUM(CREDIT)` for that transaction
- **AND** the wallet balance falls by exactly 3,000 BDT
- **AND** the `PAYOUT` account's cumulative amount rises by exactly 3,000 BDT

#### Scenario: The payout account is a system account with no owner
- **WHEN** a withdrawal is confirmed
- **THEN** the `PAYOUT` account is a `SYSTEM` account with the `PAYOUT` role and no owning user
- **AND** it is credited only, never debited

#### Scenario: A failed or cancelled withdrawal moves nothing
- **WHEN** a withdrawal attempt fails or is cancelled
- **THEN** no ledger entry is written
- **AND** the wallet balance is unchanged

#### Scenario: A repeated confirmation pays out once
- **WHEN** the same withdrawal attempt is confirmed twice
- **THEN** only one balanced pair of entries is posted

#### Scenario: A peer cannot see or settle another member's withdrawal
- **WHEN** a member opens or settles a withdrawal belonging to someone else
- **THEN** the attempt is refused
- **AND** no money moves

### Requirement: The simulated outcome is clearly distinguishable from a real confirmation

The system SHALL take the outcome from the user's stated **intent** — confirm, fail or cancel
— and SHALL map that intent to the terminal state server-side. The client SHALL NOT be able
to state the outcome directly. The system SHALL label the payout environment as simulated, in
the page chrome and outside the payment interface, and SHALL NOT describe any simulated
outcome as a real provider confirmation, because no provider is contacted.

#### Scenario: The client cannot state the outcome
- **WHEN** a settlement request carries a forged status
- **THEN** the status is ignored
- **AND** only the intent is honoured

#### Scenario: The screen states that the gateway is simulated
- **WHEN** a member opens the payout screen
- **THEN** the environment is labelled as a sandbox
- **AND** no real provider confirmation is represented
