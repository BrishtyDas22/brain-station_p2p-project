# Spec Delta

## Purpose

A loan marketplace with crowdfunded escrow: a KYC-approved borrower lists a loan request, and
one or more lenders commit capital from their **own wallet balance**. The committed capital
is held in a dedicated escrow account, and the principal is released to the borrower's
spendable wallet only when the listing reaches 100% funding.

The funding path involves **no external payment gateway**. Funding a loan is not a payment —
it is the movement of capital already inside the platform from a lender's balance into
custody.

## ADDED Requirements

### Requirement: An eligible borrower may list a loan request

The system SHALL allow a borrower whose identity is approved, and who has no active overdue
loan, to create a loan request carrying a principal, a repayment tenure, an interest rate and
a purpose. The system SHALL create the listing in the `MARKETPLACE_LISTED` state with a
unique server-generated reference.

#### Scenario: An eligible borrower lists a loan request
- **WHEN** a KYC-approved borrower with no active overdue loan submits a request for 20,000 BDT over 3 months
- **THEN** a listing is created with the status `MARKETPLACE_LISTED`
- **AND** the listing carries a unique server-generated reference
- **AND** the repayment schedule is generated from the principal, the stated rate and the tenure

#### Scenario: A borrower without an approved identity is refused
- **WHEN** an unapproved borrower submits a loan request
- **THEN** the request is refused
- **AND** no listing is created

#### Scenario: A borrower with an active overdue loan is refused
- **WHEN** a borrower who has an active overdue loan submits a request
- **THEN** the request is refused
- **AND** no listing is created

#### Scenario: A principal outside the PRD range is refused
- **WHEN** a borrower requests 4,999 BDT or 50,001 BDT
- **THEN** the request is refused
- **AND** the database CHECK constraint on the principal refuses the row as well

#### Scenario: The stated interest rate is stored exactly
- **WHEN** a borrower states a rate of 1200 basis points
- **THEN** the rate is stored as the integer 1200
- **AND** no floating-point value ever touches the rate

### Requirement: A lender funds a listing from their own wallet balance

The system SHALL allow a lender whose identity is approved to commit capital to an active
listing, and SHALL take that capital from the lender's **existing wallet balance**. The
system SHALL NOT involve an external payment gateway in the funding path, and SHALL NOT
present a payment-method selection to the lender.

#### Scenario: A lender commits capital from the wallet balance
- **WHEN** an approved lender commits 5,000 BDT to a listing
- **THEN** the capital is taken from the lender's wallet balance
- **AND** no payment method is requested and no external provider is contacted
- **AND** the lender's wallet balance falls by exactly 5,000 BDT

#### Scenario: A lender without an approved identity is refused
- **WHEN** a lender whose identity is not approved commits capital
- **THEN** the commitment is refused
- **AND** no money moves

#### Scenario: The borrower cannot fund their own listing
- **WHEN** the borrower of a listing commits capital to it
- **THEN** the commitment is refused

#### Scenario: A lender cannot fund the same listing twice
- **WHEN** a lender who has already funded a listing commits capital to it again
- **THEN** the commitment is refused

#### Scenario: A commitment beyond the available balance is refused
- **WHEN** a lender commits more than their available wallet balance
- **THEN** the commitment is refused
- **AND** the wallet balance is unchanged

### Requirement: Committed capital is locked in escrow

The system SHALL hold every committed capital in a dedicated escrow account and SHALL post
the commitment as a **balanced pair of `ledger_entries`** belonging to one `transactions` row
— a DEBIT against the lender's ledger account and an equal CREDIT against the escrow account
— so that `SUM(DEBIT) = SUM(CREDIT)` for that transaction. The system SHALL advance the
lender's wallet projection by exactly the committed amount, and SHALL NEVER assign it an
arbitrary number.

#### Scenario: A commitment is locked in escrow
- **WHEN** a lender commits 5,000 BDT
- **THEN** exactly two `ledger_entries` exist for that commitment
- **AND** the DEBIT and the CREDIT are of equal magnitude
- **AND** `SUM(DEBIT) = SUM(CREDIT)` for that transaction
- **AND** the escrow account's running balance rises by 5,000 BDT
- **AND** the lender's wallet projection falls by exactly 5,000 BDT

#### Scenario: The escrow account is a system account with no owner
- **WHEN** a commitment is posted
- **THEN** the escrow account is a `SYSTEM` account with the `ESCROW` role and no owning user
- **AND** its balance is a genuine running balance

#### Scenario: The escrow account never goes negative
- **WHEN** a posting would take the escrow account below zero
- **THEN** the posting is refused
- **AND** no entry is written

#### Scenario: A system account is credited only from a user wallet
- **WHEN** a posting would credit a system account from another system account
- **THEN** the posting is refused
- **AND** no money is created without a real user's capital behind it

### Requirement: The loan disburses when it reaches 100% funding

The system SHALL detect that a listing has reached its full funding threshold, SHALL
transition it to the `DISBURSED` state, and SHALL release the principal from escrow into the
borrower's spendable wallet through a **second balanced pair of `ledger_entries`** — a DEBIT
against the escrow account and an equal CREDIT against the borrower's ledger account. The
system SHALL NOT disburse a listing that is not fully funded.

#### Scenario: Full funding disburses the principal
- **WHEN** a listing with 5,000 BDT remaining receives a 5,000 BDT commitment
- **THEN** the listing's status becomes `DISBURSED`
- **AND** a second balanced pair of entries moves the principal from escrow to the borrower
- **AND** the borrower's spendable wallet balance rises by the full principal
- **AND** the escrow account's running balance returns to its level before the commitment

#### Scenario: Partial funding does not disburse
- **WHEN** a listing receives a commitment that does not reach 100% funding
- **THEN** the listing remains `MARKETPLACE_LISTED`
- **AND** the borrower's wallet balance is unchanged
- **AND** the capital remains in escrow

### Requirement: Concurrent funding cannot overfund a listing

The system SHALL enforce **database-level pessimistic locking on the loan object** during the
funding call, so that two lenders funding the same listing at the same moment are serialised
and the listing can never be overfunded. The system SHALL additionally refuse any commitment
that exceeds the amount still required, and SHALL honour the `Idempotency-Key` so that a
replayed funding request creates one investment and posts one entry pair.

#### Scenario: Two lenders funding the same listing are serialised
- **WHEN** two lenders commit capital to the same listing concurrently
- **THEN** the second commitment observes the first one's effect
- **AND** the listing's total committed amount never exceeds its principal

#### Scenario: A commitment beyond the remaining amount is refused
- **WHEN** a lender commits more than the amount still required
- **THEN** the commitment is refused
- **AND** the escrow balance and the lender's balance are unchanged

#### Scenario: A replayed funding request posts one entry pair
- **WHEN** the same `Idempotency-Key` is submitted twice
- **THEN** one investment exists
- **AND** only one balanced pair of entries was posted

### Requirement: The lending position is visible

The system SHALL show the borrower their loans with their status and funding progress, and
SHALL show the lender their investments with the amount committed, the amount locked in
escrow and the amount disbursed. The system SHALL show the funding progress of a listing as
a proportion of the principal, and SHALL scope every read to the authenticated user.

#### Scenario: The borrower sees their own loans
- **WHEN** the borrower opens "My Loans"
- **THEN** their loans are listed with their status and funding progress
- **AND** no other borrower's loan appears

#### Scenario: The lender sees their own investments
- **WHEN** the lender opens "My Investments"
- **THEN** their commitments are listed with the committed, escrowed and disbursed totals
- **AND** no other lender's investment appears
