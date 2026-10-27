# Spec Delta

## Purpose

A DEMO payment subsystem: a working, database-backed simulation of "Add Money" that lets a
KYC-approved user exercise the whole journey — amount, method, simulated confirmation, receipt,
balance and history — while the authoritative ledger-backed balance is left exactly as the
ledger says it is, and no real payment credential is ever requested, transmitted or stored.

## ADDED Requirements

### Requirement: The demo balance is separate from the authoritative wallet balance

The system SHALL store the demo balance in its own table, one row per user, and SHALL NOT write
the demo balance into the wallet's posted or reserved balance. The system SHALL NOT create a
ledger entry, a transaction record or a system ledger account as a result of a demo payment.
The authoritative wallet balance SHALL remain a projection of ledger entries and nothing else.

#### Scenario: A successful demo payment leaves the wallet balance untouched
- **WHEN** a user completes a demo payment that succeeds
- **THEN** the user's wallet posted balance is unchanged
- **AND** the user's wallet reserved balance is unchanged
- **AND** the ledger contains no new entry
- **AND** the user's demo balance has increased by exactly the payment amount

#### Scenario: The demo balance is not spendable money
- **WHEN** a demo balance exists for a user
- **THEN** no transfer, settlement, withdrawal or reversal code path reads it
- **AND** the demo balance is never presented as an available or spendable balance

#### Scenario: No system account is created
- **WHEN** a demo payment succeeds
- **THEN** no system ledger account exists and no funding entry pair is written
- **AND** the capability to fund a real wallet remains unimplemented pending approval of H-1
  (🟠 Proposed, not approved)

> **PENDING — H-1 (🟠 Proposed, not approved) and C-4.** This requirement exists *because* H-1
> is unapproved. It is the isolation that makes the demo subsystem possible without approving
> H-1, and it is not a statement that H-1 has been resolved.

### Requirement: The demo payment outcome is decided by the server

The system SHALL create every demo payment in a pending state and SHALL move it to a terminal
state only through a server-controlled transition. The client SHALL be able to *request* an
outcome but SHALL NOT be able to assert one, and the system SHALL NOT accept a status, a balance
value, or an amount from an untrusted client as the truth of a payment.

#### Scenario: A client cannot post a successful payment directly
- **WHEN** a client submits a request containing a successful payment status
- **THEN** the status is not taken from the request
- **AND** the server performs the transition it decides to perform

#### Scenario: The server decides the simulated outcome
- **WHEN** the user requests a confirmation, a failure or a cancellation
- **THEN** the server transitions the pending payment to the corresponding terminal state
- **AND** the client is told the resulting state

#### Scenario: A terminal payment cannot be transitioned again
- **WHEN** a payment has already reached a terminal state
- **THEN** a further transition request is refused
- **AND** the payment's recorded state is unchanged

### Requirement: Only a successful demo payment increases the demo balance

The system SHALL increase the demo balance by exactly the amount of the payment, and only when
the server has transitioned that payment to a successful state. A failed or cancelled demo
payment SHALL NOT increase the demo balance.

#### Scenario: A successful payment credits the exact amount
- **WHEN** a demo payment of 500000 Poisha succeeds
- **THEN** the user's demo balance increases by exactly 500000 Poisha

#### Scenario: A failed payment credits nothing
- **WHEN** a demo payment fails
- **THEN** the user's demo balance is unchanged

#### Scenario: A cancelled payment credits nothing
- **WHEN** a demo payment is cancelled
- **THEN** the user's demo balance is unchanged

#### Scenario: A pending payment credits nothing
- **WHEN** a demo payment is created and never confirmed
- **THEN** the user's demo balance is unchanged

### Requirement: Demo crediting is idempotent and atomic

The system SHALL credit the demo balance exactly once per successful demo payment, even when the
same payment is submitted, confirmed or replayed more than once, and even when two confirmations
for the same payment arrive concurrently. The system SHALL perform the credit and the status
transition in a single transaction.

#### Scenario: A repeated confirmation does not credit twice
- **WHEN** the same successful demo payment is confirmed a second time
- **THEN** the demo balance is increased only once
- **AND** the payment's recorded state is unchanged

#### Scenario: Concurrent confirmations credit once
- **WHEN** two confirmations for the same demo payment are processed concurrently
- **THEN** the demo balance is increased only once

#### Scenario: Concurrent confirmations of different payments credit both
- **WHEN** two different demo payments for the same user are confirmed concurrently
- **THEN** the demo balance is increased by the sum of both amounts, exactly once each

#### Scenario: A duplicate request creates no second payment
- **WHEN** the same payment creation request is replayed
- **THEN** no second demo payment is created for that request

### Requirement: Demo payments are scoped to their owner

The system SHALL scope every demo payment read and write to the authenticated user, and SHALL
take the owner from the authenticated session rather than from any request field. The system
SHALL refuse to let a user read, settle or otherwise act on another user's demo payment, and
SHALL NOT disclose another user's demo balance.

#### Scenario: An owner sees their own demo history
- **WHEN** the owner requests their demo transaction history
- **THEN** their own demo payments are returned

#### Scenario: A peer cannot see another user's demo payment
- **WHEN** a user requests a demo payment belonging to a different user
- **THEN** the request is refused and no payment data is disclosed

#### Scenario: A peer cannot settle another user's demo payment
- **WHEN** a user attempts to settle a demo payment belonging to a different user
- **THEN** the request is refused and the payment's balance is not credited

#### Scenario: A peer cannot read another user's demo balance
- **WHEN** a user requests the demo balance of a different user
- **THEN** the request is refused and no balance value is disclosed

### Requirement: A demo payment requires an approved identity

The system SHALL require the user to hold an approved identity verification before a demo payment
may be created, and SHALL refuse the flow entirely for a user whose identity is not approved.

#### Scenario: An approved user may add demo money
- **WHEN** a user whose identity verification is approved starts a demo payment
- **THEN** the payment is created

#### Scenario: An unapproved user is refused
- **WHEN** a user whose identity verification is not approved attempts to create a demo payment
- **THEN** the request is refused and no payment or balance change occurs

### Requirement: Demo amounts are validated as positive integer minor units

The system SHALL accept a demo payment amount only when it is positive, is expressed in BDT, and
does not exceed the configured demo limits. The system SHALL store and compute demo amounts as
unsigned integer minor units, and SHALL NOT use floating-point or decimal arithmetic for any
demo money value.

#### Scenario: A positive amount is accepted
- **WHEN** a demo payment of 5000 BDT is submitted
- **THEN** the payment amount is stored as 500000 Poisha

#### Scenario: A zero or negative amount is refused
- **WHEN** a demo payment of zero or a negative amount is submitted
- **THEN** the request is refused and no payment is created

#### Scenario: An amount above the configured demo limit is refused
- **WHEN** a demo payment exceeding the configured demo limit is submitted
- **THEN** the request is refused and no payment is created

#### Scenario: The amount is never a float
- **WHEN** a demo amount is stored
- **THEN** the stored value is an integer number of minor units
- **AND** the arithmetic that produced it used no floating-point operation

> **PENDING — C-3 (⚪ Open).** The numeric limit, the daily cap and the regulatory ceilings are
> **not decided**. The limit enforced here is a configurable **local demo limit**, and is not
> presented as a regulatory or compliance ceiling. No Bangladesh compliance rule is invented.

### Requirement: Every demo payment carries a unique reference and a receipt

The system SHALL give each demo payment a unique reference, generated server-side, and SHALL
record the method, the amount, the status and the date and time of the payment. The system SHALL
present a receipt containing those facts for a completed demo payment, and SHALL present a
history of the user's demo payments with the same facts.

#### Scenario: A receipt describes the payment
- **WHEN** a user views the receipt of a completed demo payment
- **THEN** the receipt shows the demo reference, the method, the amount, the status, and the date
  and time

#### Scenario: References are unique
- **WHEN** two demo payments are created
- **THEN** their references differ
- **AND** the database refuses a third payment that reuses an existing reference

#### Scenario: The history matches the payments
- **WHEN** a user views their demo transaction history
- **THEN** every entry corresponds to one of that user's demo payments
- **AND** each entry's method, amount and status match the stored payment

### Requirement: The demo payment methods are simulations and no real credential is collected

The system SHALL provide four simulated payment methods — a simulated mobile wallet, a second
simulated mobile wallet, a simulated bank transfer and a simulated card payment — and SHALL NOT
contact any payment gateway, bank, card network or external API while processing a demo payment.
The system SHALL NOT request or store a real PIN, one-time password, card verification value,
bank credential or account password, and SHALL accept only clearly fictional test data for the
simulated card.

#### Scenario: No external call is made
- **WHEN** a demo payment is processed for any of the four methods
- **THEN** the application makes no outbound request to a payment provider

#### Scenario: The card form refuses a real-looking card number
- **WHEN** a card number that is not a documented fictional test number is submitted
- **THEN** the request is refused with a message that states fictional test data is required

#### Scenario: No PIN or one-time password is ever requested
- **WHEN** any demo payment method is displayed
- **THEN** the screen contains no field requesting a PIN, one-time password, bank password or
  account credential

### Requirement: Every demo surface states that no real money was transferred

The system SHALL label every demo payment screen, the demo balance, the receipt and the demo
transaction history so that it is unambiguous that the simulation involves no real money and that
the demo balance is not real funds. The system SHALL present the real, ledger-backed wallet balance
and the demo balance in visibly distinct panels, and SHALL NOT label the demo balance as an
available, spendable or withdrawable balance.

#### Scenario: The disclaimer is present on every demo screen
- **WHEN** a demo payment screen, the demo balance panel, a receipt or the history is rendered
- **THEN** the rendered output states that no real money was transferred

#### Scenario: The two balances are presented separately
- **WHEN** the wallet page is rendered for a user who has a demo balance
- **THEN** the real ledger-backed wallet balance and the demo balance appear in distinct panels,
  each labelled as such

#### Scenario: The demo balance is not called available funds
- **WHEN** the demo balance is rendered
- **THEN** it is not labelled as an available, spendable or real balance

### Requirement: The demo balance and history persist across sessions

The system SHALL persist the demo balance and the demo payment history in the database, so that
they survive a page refresh, a sign-out and a subsequent sign-in.

#### Scenario: The demo balance survives a refresh
- **WHEN** a user completes a demo payment and reloads the wallet page
- **THEN** the demo balance is the increased value

#### Scenario: The demo balance survives signing out and back in
- **WHEN** a user completes a demo payment, signs out, signs in again, and opens the wallet page
- **THEN** the demo balance is the increased value
- **AND** the history still lists the payment

#### Scenario: The demo balance starts at zero
- **WHEN** a user who has never made a demo payment opens the wallet page
- **THEN** the demo balance is zero and the history is empty

### Requirement: The demo subsystem leaves the authoritative ledger schema intact

The system SHALL add the demo payment tables without altering the columns, constraints, indexes or
triggers of the authoritative ledger tables, and SHALL leave every existing user, wallet, ledger
account, ledger entry and identity verification record unchanged.

#### Scenario: The ledger schema is unchanged
- **WHEN** the demo payment migration has been applied
- **THEN** the wallet, ledger account and ledger entry tables are unchanged
- **AND** the append-only update and delete triggers are still installed
- **AND** the immutable wallet-to-account trigger is still installed

#### Scenario: Existing records are preserved
- **WHEN** the demo payment migration has been applied
- **THEN** every pre-existing user, wallet, ledger account, ledger entry and identity
  verification record is still present and unchanged
