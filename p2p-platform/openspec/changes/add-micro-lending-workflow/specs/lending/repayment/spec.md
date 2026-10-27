# Spec Delta

## Purpose

The repayment engine: an installment schedule generated from the loan's principal, stated
interest rate and tenure, and a collection path that debits the borrower's wallet and
credits **proportional principal and interest shares** back to the lenders who funded it.

The rules here are the PRD's own — the `GRACE_PERIOD` of 3 days and the 0.5% daily late
penalty after the grace period expires. No restructuring, write-off or collection policy is
defined, because the PRD defines none.

## ADDED Requirements

### Requirement: The repayment schedule is generated with the listing

The system SHALL generate the installment schedule when the loan request is created, with one
installment per month of the stated tenure. Each installment SHALL carry its own principal
portion and interest portion, both in integer Poisha, and the installments SHALL sum to
exactly the total repayable. The system SHALL compute the schedule with integer arithmetic
only, and SHALL NEVER let a floating-point value touch a financial amount.

#### Scenario: The schedule sums to the total repayable
- **WHEN** a listing for 20,000 BDT over 3 months is created with a 12% annual rate
- **THEN** three installments exist
- **AND** their amounts sum to exactly the total repayable
- **AND** each installment's principal portion plus its interest portion equals its amount

#### Scenario: The last installment absorbs the rounding remainder
- **WHEN** the equal split does not divide evenly
- **THEN** the final installment carries the remainder
- **AND** the installments still sum to exactly the total repayable

### Requirement: A borrower pays an installment from the wallet

The system SHALL debit the exact installment amount from the borrower's wallet and SHALL
credit the lenders' balances with their proportional shares of that installment, split
between principal and interest in proportion to each lender's commitment. The system SHALL
mark the installment `PAID` only when the whole amount has been distributed.

#### Scenario: An installment is collected and distributed
- **WHEN** the borrower pays an installment
- **THEN** the borrower's wallet balance falls by exactly the installment amount
- **AND** each lender's balance rises by their proportional share
- **AND** the shares sum to exactly the installment amount
- **AND** the installment's status becomes `PAID`

#### Scenario: The shares are proportional to the commitments
- **WHEN** two lenders funded a loan 60/40 and an installment is collected
- **THEN** each lender receives their share in that proportion
- **AND** the rounding remainder goes to the largest commitment
- **AND** no Poisha is created and none is lost

#### Scenario: Every movement is posted as a balanced pair
- **WHEN** an installment is collected
- **THEN** each wallet movement is posted as a balanced pair of `ledger_entries`
- **AND** `SUM(DEBIT) = SUM(CREDIT)` for every transaction it creates

#### Scenario: A non-borrower cannot collect
- **WHEN** a user who is not the borrower attempts to collect an installment
- **THEN** the collection is refused
- **AND** no money moves

#### Scenario: An already-paid installment is not collected twice
- **WHEN** an installment that is already `PAID` is collected again
- **THEN** nothing is written
- **AND** no balance moves

### Requirement: An unpaid installment enters a grace period

The system SHALL place an installment whose due date has passed and whose amount cannot be
debited into the `GRACE_PERIOD` state, for a grace period of 3 days. The system SHALL accrue a
daily late penalty surcharge of 0.5% of the installment amount for each whole day after the
grace period expires, and SHALL charge that penalty on collection.

#### Scenario: An unaffordable installment enters the grace period
- **WHEN** the due date passes and the borrower's balance cannot cover the installment
- **THEN** the installment's status becomes `GRACE_PERIOD`
- **AND** the grace period is recorded
- **AND** no penalty has accrued yet

#### Scenario: A penalty accrues after the grace period expires
- **WHEN** the grace period has expired and 2 whole days have passed
- **THEN** the accrued penalty is 0.5% of the installment amount for each of those days
- **AND** the penalty is charged on collection

#### Scenario: No penalty accrues during the grace period
- **WHEN** an installment is within its 3-day grace period
- **THEN** the accrued penalty is zero

#### Scenario: No collection policy is invented
- **WHEN** an installment remains unpaid past the grace period
- **THEN** no restructuring, write-off or collection workflow is performed
- **AND** the accrual is audited instead

### Requirement: Paying every installment marks the loan repaid

The system SHALL mark the loan `REPAID` once every installment is `PAID`, and SHALL record the
date on which the loan was repaid. The system SHALL move the loan to the `REPAYING` state
when the first installment is collected and the loan is disbursed.

#### Scenario: The loan is repaid
- **WHEN** the last outstanding installment is collected
- **THEN** the loan's status becomes `REPAID`
- **AND** the repaid-at date is recorded
