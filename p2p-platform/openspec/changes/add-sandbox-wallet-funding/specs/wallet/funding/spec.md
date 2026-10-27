# Spec Delta

## Purpose

A sandbox "Add Money" funding workflow: a simulated payment gateway that credits the user's
**real, ledger-backed main wallet** through **real, balanced double-entry postings**, so that
the Dashboard, the Wallet and the transaction history all move with it.

The gateway is simulated because no external provider API is available. The **accounting is
not** simulated.

## ADDED Requirements

### Requirement: A successful funding credits the main ledger-backed wallet

The system SHALL credit the user's main wallet when a funding attempt succeeds, and SHALL do so
by appending a **balanced pair of `ledger_entries`** belonging to one `transactions` row — a
DEBIT against the funding source and an equal CREDIT against the user's ledger account — so
that `SUM(DEBIT) = SUM(CREDIT)` for that transaction. The system SHALL NOT create a second,
parallel balance.

#### Scenario: A successful payment credits the main wallet
- **WHEN** a funding attempt of 500000 Poisha succeeds
- **THEN** the user's main wallet balance increases by exactly 500000 Poisha
- **AND** exactly two `ledger_entries` exist for that transaction
- **AND** the DEBIT and the CREDIT are of equal magnitude
- **AND** `SUM(DEBIT) = SUM(CREDIT)` for that transaction

> **PENDING — H-1 (🟠 Proposed, not approved) and C-4.** The funding source is a **local
> sandbox policy**, explicitly authorized for development and demonstration. It is **not**
> production funding, it does **not** approve H-1, and it does **not** resolve C-4.

### Requirement: The wallet projection is derived from the ledger entries

The system SHALL advance `wallets.balance_minor` by exactly the credited amount, and SHALL NOT
assign it an arbitrary number. The entries SHALL exist before the projection is advanced, and
the projection SHALL always equal the sum of the account's credits minus its debits.

#### Scenario: The wallet projection is derived from the entries
- **WHEN** the funding is settled
- **THEN** the ledger entries exist before the wallet projection is advanced
- **AND** the projection moves by the amount the entries carry
- **AND** the projection equals the sum of the account's credits minus its debits

#### Scenario: A user wallet never goes below zero
- **WHEN** a posting would take a user wallet below zero
- **THEN** the posting is refused and no entry is written

### Requirement: The funding source is a system account that is never credited

The counterparty of a funding posting SHALL be a `SYSTEM` account with the `FUNDING` role and
no owning user, created at most once and reused thereafter. A `SYSTEM` account SHALL hold no
wallet and SHALL never be presented as a user balance, and a posting naming it as its
destination SHALL be refused.

#### Scenario: A system account is never credited
- **WHEN** a ledger posting names a `SYSTEM` account as its destination
- **THEN** the posting is refused
- **AND** no money is created without an external inflow behind it

#### Scenario: The funding account is a source of funds
- **WHEN** a funding attempt succeeds
- **THEN** the counterparty is a `SYSTEM` account with the `FUNDING` role and no owning user
- **AND** that account is created at most once and reused thereafter
- **AND** it holds no wallet and is never presented as a user balance

### Requirement: The funding outcome is decided by the server

The system SHALL create every funding attempt in a pending state and SHALL move it to a terminal
state only through a server-controlled transition. The client SHALL be able to *request* an
outcome but SHALL NOT be able to assert one. The system SHALL NOT accept a status, a balance, a
credited amount, a reference, an owner or a transaction id from an untrusted client as the truth
of a payment.

#### Scenario: A client cannot post a successful payment directly
- **WHEN** a client submits a request containing a successful funding status or a credited amount
- **THEN** the field is stripped before validation
- **AND** the attempt is created in the pending state
- **AND** no balance moves

#### Scenario: The server decides the simulated outcome
- **WHEN** the user requests a confirmation, a failure or a cancellation
- **THEN** the server transitions the pending attempt to the corresponding terminal state
- **AND** the client is told the resulting state

#### Scenario: A terminal attempt cannot be transitioned again
- **WHEN** an attempt has already reached a terminal state
- **THEN** a further transition request is refused
- **AND** the attempt's recorded state is unchanged

### Requirement: Only a successful funding moves money

The system SHALL move money only when the server has transitioned a funding attempt to a
successful state. A failed, a cancelled and a pending attempt SHALL post no ledger entries,
create no transaction and move no balance.

#### Scenario: A failed payment credits nothing
- **WHEN** a funding attempt fails
- **THEN** the user's main wallet balance is unchanged
- **AND** no ledger entry exists
- **AND** no transaction exists
- **AND** a failure reason is recorded

#### Scenario: A cancelled payment credits nothing
- **WHEN** a funding attempt is cancelled
- **THEN** the user's main wallet balance is unchanged
- **AND** no ledger entry exists

#### Scenario: A pending attempt credits nothing
- **WHEN** a funding attempt is created and never confirmed
- **THEN** the user's main wallet balance is unchanged

### Requirement: Funding crediting is idempotent and atomic

The system SHALL credit the main wallet exactly once per successful funding attempt, even when
the same attempt is submitted, confirmed or replayed more than once, and even when two
confirmations for the same attempt arrive concurrently. The system SHALL perform the status
transition and the posting in a single transaction, with the funding attempt, the wallet and both
ledger accounts locked.

#### Scenario: A repeated confirmation does not credit twice
- **WHEN** the same successful funding attempt is confirmed a second time
- **THEN** the main wallet balance is increased only once
- **AND** the attempt's recorded state is unchanged

#### Scenario: Concurrent confirmations credit once
- **WHEN** two confirmations for the same funding attempt are processed concurrently
- **THEN** the main wallet balance is increased only once
- **AND** exactly two ledger entries exist

#### Scenario: A duplicate request creates no second attempt
- **WHEN** the same funding creation request is replayed with the same idempotency key
- **THEN** no second funding attempt is created
- **AND** the original attempt is returned

#### Scenario: A failed attempt cannot be confirmed afterwards
- **WHEN** an attempt that has failed is confirmed again
- **THEN** the attempt remains failed
- **AND** no balance moves

### Requirement: Funding is scoped to its owner

The system SHALL scope every funding read and write to the authenticated user, and SHALL take
the owner from the authenticated session rather than from any request field. The system SHALL
refuse to let a user read, settle or otherwise act on another user's funding attempt, and SHALL
NOT disclose another user's funding history or wallet balance.

#### Scenario: A peer cannot see another user's attempt
- **WHEN** a user requests a funding attempt belonging to a different user
- **THEN** the request is refused and no funding data is disclosed

#### Scenario: A peer cannot settle another user's attempt
- **WHEN** a user attempts to settle a funding attempt belonging to a different user
- **THEN** the request is refused
- **AND** neither user's wallet balance is credited

#### Scenario: A peer does not see another user's funding in the history
- **WHEN** a user opens their funding history
- **THEN** it lists only that user's funding attempts

### Requirement: A funding requires an approved identity

The system SHALL require an approved identity verification before a funding attempt may be
created, and SHALL refuse the flow entirely for a user whose identity is not approved.

#### Scenario: An approved user may add money
- **WHEN** a user whose identity verification is approved starts a funding attempt
- **THEN** the attempt is created

#### Scenario: An unapproved user is refused
- **WHEN** a user whose identity verification is not approved attempts to create a funding attempt
- **THEN** the request is refused
- **AND** no attempt is created and no balance moves

### Requirement: Funding amounts are validated as positive integer minor units

The system SHALL accept a funding amount only when it is positive, is expressed in BDT, and does
not exceed the configured sandbox limits. The system SHALL store and compute funding amounts as
unsigned integer minor units, and SHALL NOT use floating-point or decimal arithmetic for any
money value. The client SHALL send the amount as a string and the server SHALL convert it, so
that the credited amount is exactly the amount shown to the user.

#### Scenario: A positive amount is accepted
- **WHEN** a funding of 5000 BDT is submitted
- **THEN** the amount is stored as 500000 Poisha
- **AND** the wallet is credited 500000 Poisha

#### Scenario: A decimal amount is exact
- **WHEN** a funding of 1234.56 BDT is submitted
- **THEN** the amount is stored as 123456 Poisha

#### Scenario: A zero or negative amount is refused
- **WHEN** a funding of zero or a negative amount is submitted
- **THEN** the request is refused and no attempt is created

#### Scenario: An amount above the configured sandbox limit is refused
- **WHEN** a funding exceeding the configured sandbox limit is submitted
- **THEN** the request is refused and no attempt is created

> **PENDING — C-3 (⚪ Open).** The per-payment ceiling and the daily cap are **sandbox limits**
> that exist so a test cannot request an absurd number. They are **not** regulatory ceilings
> and **not** a business limit. No Bangladesh compliance rule is invented.

### Requirement: Every funding attempt carries a unique reference and a receipt

The system SHALL give each funding attempt a unique reference, generated server-side, and
SHALL record the method, the amount, the status and the date and time. The system SHALL present
a receipt containing those facts, and SHALL present a history of the user's funding attempts
with the same facts.

#### Scenario: A receipt describes the payment
- **WHEN** a user views the receipt of a funding attempt
- **THEN** the receipt shows the reference, the method, the amount, the status and the date and time

#### Scenario: References are unique
- **WHEN** two funding attempts are created
- **THEN** their references differ
- **AND** the database refuses a third attempt that reuses an existing reference

### Requirement: The funding methods are simulations and no real credential is collected

The system SHALL provide four payment methods — bKash, Nagad, Bank Transfer and Debit / Credit
Card — and SHALL NOT contact any payment gateway, bank, card network or external API while
processing a funding. The system SHALL NOT request or store a real PIN, one-time password, card
verification value, bank credential or account password, and SHALL accept only clearly fictional
test data for the card.

#### Scenario: No external call is made
- **WHEN** a funding is processed for any of the four methods
- **THEN** the application makes no outbound request to a payment provider

#### Scenario: The card form refuses a real-looking card number
- **WHEN** a card number that is not the documented fictional test number is submitted
- **THEN** the request is refused with a message that states fictional test data is required

#### Scenario: The security code is never stored
- **WHEN** a card funding succeeds
- **THEN** only the last four digits and the cardholder name are retained
- **AND** the security code, the expiry and the card number are not stored

#### Scenario: No PIN or one-time password is ever requested
- **WHEN** any funding method is displayed
- **THEN** the screen contains no field requesting a PIN, one-time password, bank password or account credential

### Requirement: The funding appears in the main transaction history

The system SHALL present a successful funding in the **main** transaction history — the same
history that shows peer-to-peer transfers — and SHALL label it as an "Add Money" credit
distinguishable from a transfer. The system SHALL NOT maintain a separate payment history
separate from the transaction history.

#### Scenario: The credit appears in the transaction list
- **WHEN** a funding succeeds
- **THEN** the main transaction history shows a credit of the funded amount
- **AND** it is labelled as an "Add Money" movement
- **AND** it carries the funding reference

#### Scenario: The credit appears on the dashboard and the wallet page
- **WHEN** a funding succeeds
- **THEN** the dashboard shows the increased wallet balance
- **AND** the wallet page shows the increased wallet balance

### Requirement: The funding balance and history persist across sessions

The system SHALL persist the funding results in the database, so that the wallet balance and the
history survive a page refresh, a sign-out and a subsequent sign-in.

#### Scenario: The balance survives a refresh
- **WHEN** a user completes a funding and reloads the wallet page
- **THEN** the wallet balance is the increased value

#### Scenario: The balance survives signing out and back in
- **WHEN** a user completes a funding, signs out, signs in again, and opens the wallet page
- **THEN** the wallet balance is the increased value
- **AND** the history still lists the payment

### Requirement: There is one wallet balance, and no parallel demo balance

The system SHALL present exactly one balance to the user — the ledger-backed main wallet balance.
The system SHALL NOT present a separate demo balance, an "Add Demo Money" action, a separate
demo payment history, a duplicate balance card, or a large simulation warning banner on the
customer-facing payment screens. The system SHALL label the payment methods with their
professional names.

#### Scenario: No separate demo balance is presented
- **WHEN** the wallet, dashboard, Add Money, receipt or history screens are rendered
- **THEN** no separate demo balance, demo payment history or "Add Demo Money" action appears

#### Scenario: The payment methods are named professionally
- **WHEN** the Add Money screen is rendered
- **THEN** the four methods are labelled bKash, Nagad, Bank Transfer and Debit / Credit Card

#### Scenario: The sandbox is stated once, outside the payment interface
- **WHEN** the Add Money, receipt or history screen is rendered
- **THEN** a single accurate statement identifies the environment as a sandbox in which the
  gateway is simulated and no real money is transferred
- **AND** the statement is not repeated on every card, row and panel of the payment interface

#### Scenario: The old demo records are preserved
- **WHEN** the sandbox funding migration has been applied
- **THEN** the previous demo payment and demo balance tables and their rows are still present
  and unchanged
- **AND** the previously recorded demo balance is not credited to the main wallet

### Requirement: The funding workflow leaves the authoritative ledger schema intact

The system SHALL add the funding table without altering the columns, constraints, indexes or
triggers of the authoritative ledger tables, and SHALL leave every existing user, wallet, ledger
account, ledger entry and identity verification record unchanged. The system SHALL NOT write to
`wallets.balance_minor` in order to create money, and SHALL NOT create unbalanced or duplicate
ledger entries.

#### Scenario: The ledger schema is unchanged
- **WHEN** the funding migration has been applied
- **THEN** the wallet, ledger account and ledger entry tables are unchanged
- **AND** the append-only update and delete triggers are still installed
- **AND** the immutable wallet-to-account trigger is still installed

#### Scenario: No destructive migration is used
- **WHEN** the funding migration has been applied
- **THEN** no existing table is dropped and no existing row is deleted
- **AND** no ledger entry is rewritten

#### Scenario: The funding account is not reachable from a peer transfer
- **WHEN** a peer-to-peer transfer is posted
- **THEN** the sandbox funding account is not its counterparty
- **AND** the funding account's only ledger entries are the funding postings
