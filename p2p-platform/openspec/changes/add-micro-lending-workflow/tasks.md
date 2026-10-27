# Tasks

## 1. The schema

- [x] 1.1 Create the `loan_requests` table — `reference` (unique), `borrower_user_id`,
      `principal_minor`, `currency`, `interest_rate_bps`, `tenure_months`, `purpose`,
      `status`, `funded_minor`, `investor_count`, `funded_at`, `repaid_at`
- [x] 1.2 Create the `loan_investments` table — `reference` (unique), `loan_request_id`,
      `lender_user_id`, `amount_minor`, `currency`, `idempotency_key` (unique),
      `transaction_id`
- [x] 1.3 Create the `loan_installments` table — `loan_request_id`, `sequence`, `due_date`,
      `amount_minor`, `principal_minor`, `interest_minor`, `penalty_minor`, `status`,
      `grace_started_at`, `paid_at`
- [x] 1.4 Create the `wallet_withdrawals` table — `reference` (unique), `owner_user_id`,
      `method`, `status`, `amount_minor`, `currency`, `details` (JSON), `transaction_id`,
      `idempotency_key` (unique), `settled_at`
- [x] 1.5 Add the `ESCROW` role to `ledger_accounts.system_role` by **appending** to the MySQL
      ENUM, so the existing members keep their values and no existing row is touched
- [x] 1.6 Add the `PAYOUT` role to `ledger_accounts.system_role`, also by appending
- [x] 1.7 Add the CHECK constraints as raw DDL — the principal bound to the PRD's BDT
      5,000–50,000 range, the amounts positive, and the currency fixed to `BDT`
- [x] 1.8 Add the foreign keys to `users`, `loan_requests`, `ledger_accounts` and
      `transactions` with **no** referential action
- [x] 1.9 Use `DATETIME(6)` for every timestamp, not `TIMESTAMP`
- [x] 1.10 Change **no** existing table, column, constraint, index or trigger
- [x] 1.11 Apply the migrations to the development schema on port 3308 and verify they report
      `DONE`, without a `migrate:fresh`

## 2. The models and enums

- [x] 2.1 `LoanStatus` — `MARKETPLACE_LISTED`, `ESCROW_LOCKED`, `DISBURSED`, `REPAYING`,
      `PAID`, `REPAID`, with professional labels
- [x] 2.2 `InstallmentStatus` — `PENDING`, `PAID`, `GRACE_PERIOD`, `OVERDUE`, with
      professional labels
- [x] 2.3 `WithdrawalStatus` — `PENDING`, `SUCCEEDED`, `FAILED`, `CANCELLED`
- [x] 2.4 `WithdrawalMethod` — `BKASH`, `NAGAD`, `BANK_TRANSFER`
- [x] 2.5 `WithdrawalIntent` — `CONFIRM`, `FAIL`, `CANCEL`, with the terminal state each maps
      to, kept **separate** from `FundingIntent` because a payout has a different lifecycle
- [x] 2.6 `LedgerSystemRole` — add the `PAYOUT` case, and document the four roles
- [x] 2.7 `LoanRequest`, `LoanInvestment`, `LoanInstallment`, `WalletWithdrawal` — guarded
      models with the enum casts and their relations
- [x] 2.8 `WithdrawalDetails` — validate and reduce the method-specific inputs to their
      minimal storable form, keeping only the last four digits of a bank account
- [x] 2.9 `config/p2p-lending.php` — the PRD's rules, with the platform limits and the PRD
      rules kept visibly distinct

## 3. The escrow-capable ledger

- [x] 3.1 `LedgerPostingService::systemRoleOf()` — replace the escrow-only helper with a
      general role lookup
- [x] 3.2 Widen the credit rule — a system account may be credited **only** if its role is
      `ESCROW` or `PAYOUT`, and **only** from a real user wallet
- [x] 3.3 `ESCROW` `balance_after` — a genuine running balance, `SUM(CREDIT) − SUM(DEBIT)`,
      so escrow can never be released beyond what it holds
- [x] 3.4 `PAYOUT` `balance_after` — the cumulative amount paid out, `SUM(CREDIT)`
- [x] 3.5 `FUNDING` `balance_after` — unchanged, the cumulative amount sourced
- [x] 3.6 `sandboxPayoutAccount()` — lazily create exactly one `SYSTEM` / `PAYOUT` account
      with no owner
- [x] 3.7 Refuse a posting that would take a user wallet below zero
- [x] 3.8 Never assign a wallet balance an arbitrary number — it moves only by the amount
      the entries carry, after the entries exist

## 4. The loan workflow

- [x] 4.1 `LoanService::createRequest()` — validate eligibility, parse the principal by string
      arithmetic, generate the reference, create the listing and its schedule
- [x] 4.2 Assert an **approved identity** before a request
- [x] 4.3 Assert the borrower has **no active overdue loan** before a request
- [x] 4.4 Enforce the PRD's principal range, and the tenure and rate bounds
- [x] 4.5 Generate the installment schedule with integer arithmetic, with the final
      installment absorbing the rounding remainder
- [x] 4.6 `LoanService::fund()` — take the commitment from the lender's wallet balance
- [x] 4.7 Assert the lender's identity is approved, and that the lender is not the borrower
- [x] 4.8 Refuse a duplicate commitment by the same lender to the same listing
- [x] 4.9 Refuse a commitment beyond the amount still required
- [x] 4.10 Refuse a commitment beyond the lender's available balance
- [x] 4.11 **Lock the loan row** with `lockForUpdate()` inside the funding transaction, so
      concurrent commitments are serialised and the listing cannot be overfunded
- [x] 4.12 Post the commitment as a balanced pair against the escrow account
- [x] 4.13 Honour the `Idempotency-Key` — a replayed funding request returns the original
      commitment and posts no new entries
- [x] 4.14 Disburse on reaching 100% — a second balanced pair moves the principal from
      escrow to the borrower's wallet
- [x] 4.15 Do **not** disburse a listing that is not fully funded
- [x] 4.16 Audit every request, commitment, disbursement and collection
- [x] 4.17 `LoanService::marketplaceFor()`, `requestsFor()`, `investmentsFor()` — the reads,
      scoped to the caller

## 5. The repayment workflow

- [x] 5.1 `RepaymentService::collect()` — debit the borrower, distribute to the lenders
- [x] 5.2 Refuse a collection from anyone but the borrower
- [x] 5.3 Refuse a collection from an installment that is already paid
- [x] 5.4 Distribute each installment across the lenders **in proportion to their
      commitments**
- [x] 5.5 Give the rounding remainder to the largest commitment, so the installment is
      distributed exactly
- [x] 5.6 Split each share into its principal and interest portions
- [x] 5.7 Place an unaffordable, overdue installment into the `GRACE_PERIOD` state for 3 days
- [x] 5.8 Accrue a daily late penalty of 0.5% of the installment amount for each whole day
      after the grace period expires
- [x] 5.9 Charge the accrued penalty on collection
- [x] 5.10 Move the loan to `REPAYING` on the first collection, and to `REPAID` when every
      installment is paid
- [x] 5.11 Audit every collection, grace entry and penalty accrual

## 6. The withdrawal workflow

- [x] 6.1 `WithdrawalService::start()` — validate eligibility, parse the amount, reduce the
      details, check the balance and the ceiling, create the pending attempt
- [x] 6.2 Assert an **approved identity** before a withdrawal
- [x] 6.3 Refuse a withdrawal larger than the available balance
- [x] 6.4 `WithdrawalService::settle()` — map the intent to a terminal state under a
      `FOR UPDATE` lock, with a conditional `UPDATE ... WHERE status = 'PENDING'`
- [x] 6.5 Debit the wallet and credit the `PAYOUT` sink as a balanced pair
- [x] 6.6 A failed or cancelled withdrawal posts no entries and moves no balance
- [x] 6.7 A repeated settlement pays out once
- [x] 6.8 Scope every read and write to the authenticated user
- [x] 6.9 Collect no PIN, OTP, password or full card number, and store only the last four
      digits of a bank account

## 7. The request and the controllers

- [x] 7.1 `LoanController::index()` — the marketplace
- [x] 7.2 `LoanController::store()` — create a loan request
- [x] 7.3 `LoanController::show()` — the listing detail, with the viewer's flags
- [x] 7.4 `LoanController::fund()` — commit capital, honouring the `Idempotency-Key` header
- [x] 7.5 `LoanController::myLoans()` — the borrower's loans
- [x] 7.6 `LoanController::myInvestments()` — the lender's investments
- [x] 7.7 `LoanController::collect()` — collect an installment, borrower only
- [x] 7.8 `WithdrawalController::index()` / `store()` / `show()` / `settle()` / `history()`
- [x] 7.9 Add the `loans` and `wallet/withdrawals` route groups to `routes/p2p.php`
- [x] 7.10 Add **no** REST API — the existing Inertia routes only

## 8. The interface

- [x] 8.1 `loans/marketplace.tsx` — the listings and the request form
- [x] 8.2 `loans/show.tsx` — the listing detail, the funding meter, the lenders table and the
      repayment schedule
- [x] 8.3 `loans/my-loans.tsx` — the borrower's loans
- [x] 8.4 `loans/my-investments.tsx` — the lender's commitments
- [x] 8.5 `wallet/withdraw.tsx` — the withdrawal form and the method-specific details
- [x] 8.6 `wallet/withdrawal.tsx` — the payout screen and the receipt
- [x] 8.7 `wallet/withdrawal-history.tsx` — the receipts table
- [x] 8.8 `LoanStatusBadge`, `InstallmentStatusBadge`, `WithdrawalStatusBadge` and
      `FundingMeter` in the shared component module
- [x] 8.9 Add the `LoanStatus`, `InstallmentStatus` and `WithdrawalStatus` types
- [x] 8.10 State the sandbox **once**, in the page chrome, outside the payment interface —
      and only on the screens where an external payout is simulated

## 9. Removing the misleading transfer surface

- [x] 9.1 Remove the `Send Money` page and the `resources/js/pages/send/` directory
- [x] 9.2 Remove the `send` route
- [x] 9.3 Remove `DemoController::send()`
- [x] 9.4 Repoint the sidebar — Withdraw Money, Loan Marketplace, My Loans, My Investments
- [x] 9.5 Repoint the dashboard — Add Money, Fund Loan, Withdraw money, and the lending tiles
- [x] 9.6 Repoint the wallet page — Add Money, Withdraw money, Fund Loan, Receive money
- [x] 9.7 Repoint the Receive Money page — explain that money arrives through the loan
      workflow, a repayment share, or Add Money
- [x] 9.8 Update `HandleInertiaRequests::pageDescription()` for the new paths
- [x] 9.9 Update the page metadata map, and delete the stale `page-meta.ts` duplicate that
      shadowed `page-meta.tsx`

## 10. The tests

- [x] 10.1 A request creates a listing in `MARKETPLACE_LISTED`
- [x] 10.2 A principal outside the PRD's range is refused
- [x] 10.3 A borrower without an approved identity is refused
- [x] 10.4 A borrower with an active overdue loan is refused
- [x] 10.5 A lender funds a listing from the wallet balance, with no payment method involved
- [x] 10.6 The commitment is locked in escrow as a balanced pair, and the escrow balance
      rises
- [x] 10.7 Full funding disburses the principal to the borrower's wallet
- [x] 10.8 Partial funding does **not** disburse
- [x] 10.9 Overfunding is refused
- [x] 10.10 A duplicate funding attempt is refused
- [x] 10.11 Funding beyond the lender's balance is refused
- [x] 10.12 A lender without an approved identity cannot fund
- [x] 10.13 The borrower cannot fund their own listing
- [x] 10.14 Two lenders funding the same loan disburse once
- [x] 10.15 A replayed funding request posts one entry pair
- [x] 10.16 The installment schedule sums to the total repayable
- [x] 10.17 Collecting an installment pays the lenders proportionally, and the remainder goes
      to the largest commitment
- [x] 10.18 Paying every installment marks the loan repaid
- [x] 10.19 An unaffordable installment enters the grace period, and a penalty accrues only
      after it expires
- [x] 10.20 A withdrawal debits the wallet and credits the `PAYOUT` sink as a balanced pair
- [x] 10.21 A cancelled withdrawal moves nothing
- [x] 10.22 A withdrawal beyond the available balance is refused
- [x] 10.23 The ledger stays balanced — `SUM(DEBIT) = SUM(CREDIT)` for every transaction
- [x] 10.24 The demo screens' tests are updated for the removed `send` route

## 11. Documentation and bookkeeping

- [x] 11.1 This change — the proposal, the design and the three delta specs
- [x] 11.2 `config/p2p-lending.php` — the PRD's rules, and only those
- [x] 11.3 The epic memlog — a dated entry for the lending decision
- [x] 11.4 Re-run `openspec validate add-micro-lending-workflow --strict`

## 12. The explicitly deferred items

These are **not** done, and are **not** approved by this change. Each names its blocker.

- [ ] 12.1 H-1 — SYSTEM accounts (🟠 Proposed, not approved). **BLOCKER:** the specification
      decision has not been made. This change adds the `ESCROW` and `PAYOUT` roles to the same
      unapproved umbrella and approves nothing.
- [ ] 12.2 C-4 — funding cannot be modelled honestly yet (⛔). **BLOCKER:** depends on H-1.
- [ ] 12.3 The **interest rate schedule** — the PRD requires the rate to be *calculated* but
      defines no schedule, no risk-based pricing and no default rate. **BLOCKER:** undecided.
      The rate is therefore a borrower-stated basis-point field, not a computed one.
- [ ] 12.4 The **funding window** — the PRD says "if the funding window expires, funds return
      to the lender's wallet instantly" but states no window length. **BLOCKER:** undecided, so
      no expiry timer is implemented.
- [ ] 12.5 The **risk score** — the PRD's lender marketplace view is "based on risk scores,
      yield percentages and tenure". **BLOCKER:** no scoring model is defined, so the
      marketplace shows the stated rate rather than an invented score.
- [ ] 12.6 The **batch repayment job** — the PRD describes a daily batch. **BLOCKER:** no
      scheduler, queue or job class is defined. The money path the job would call is
      implemented and is exercised from the repayment schedule screen.
- [ ] 12.7 **Restructuring, write-off and collections** — **BLOCKER:** the PRD defines none, so
      none is invented.
- [ ] 12.8 **SMS and push notification** — the PRD says the borrower is "alerted via SMS and
      push notification" on entering the grace period. **BLOCKER:** no provider is configured
      and no template or destination is defined. The grace event is audited instead.
- [ ] 12.9 C-3 — the business limit (⚪ Open). **BLOCKER:** undecided. The enforced limits are
      platform limits that mirror the PRD, not regulatory ceilings.
- [ ] 12.10 M-2 — the canonicalization rule (⚪ BLOCKED). **BLOCKER:** undecided. The chain
      uses the local, documented canonical form and does **not** claim to implement the M-2 rule.
- [ ] 12.11 M-3 — the genesis constant (⚪ Open). **BLOCKER:** undecided.
- [ ] 12.12 H-6 / D1 — `ledger_entries.status` (🟠 Proposed). **BLOCKER:** undecided, and out
      of scope for this change.
