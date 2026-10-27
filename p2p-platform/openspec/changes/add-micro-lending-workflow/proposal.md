# Proposal

## Why

The platform was built as a **peer-to-peer transfer** system: two wallets, a double-entry
ledger, and a "Send Money" page that could never move money because no verified inbound
channel existed behind an account. The PRD, however, describes a **micro-lending** product:
borrowers request a loan, lenders fund it from their own balance, the capital sits in escrow
until the listing is fully funded, the principal disburses to the borrower's wallet, and the
borrower repays in installments that are split back across the lenders.

That flow was **not implemented**. The product's central journey — the one the business
actually sells — did not exist. Meanwhile the "Send Money" page was a dead end: a disabled
dropdown of bKash/Nagad/Bank/Card methods that could never complete, which is misleading UI
because it implies a transfer that the system does not support.

This change replaces the dead transfer surface with the **micro-lending workflow the PRD
describes**, and adds the **Withdraw Money** flow that a lending product genuinely needs.

## What Changes

- **Remove the "Send Money" page, its route and its controller method.** It could not move
  money and it implied a capability the platform does not have. It is deleted, not disabled.
- **Add a Loan Marketplace.** A KYC-approved borrower requests a loan of BDT 5,000–50,000 with
  a purpose and a repayment tenure. The listing becomes visible to lenders.
- **Fund a loan from wallet balance only.** A lender commits capital from their **existing
  wallet balance**. There is **no external payment gateway** in the funding path — funding a
  loan is not a payment, it is the movement of capital already inside the platform.
- **Lock committed capital in escrow.** Every commitment is posted as a balanced pair of
  `ledger_entries` against a `SYSTEM` / `ESCROW` account, so the capital is in custody and
  cannot be spent twice.
- **Disburse at 100%.** When a listing reaches full funding, the principal is released from
  escrow into the borrower's wallet through a second balanced posting.
- **Prevent overfunding, duplicate funding and over-commitment.** A pessimistic row lock on
  the loan serialises concurrent funding attempts; the idempotency key makes a replayed
  request a no-op; the escrow account can never be debited below zero.
- **Track the lending position.** Borrower loan status, lender investments, funding progress
  and installment schedules are visible on their own pages and on the dashboard.
- **Add installment repayment.** The schedule is generated from the principal, the stated
  interest rate and the tenure. Each payment is split across the lenders in proportion to what
  each committed.
- **Add Withdraw Money.** A borrower (or any member) withdraws available wallet funds through
  a simulated bKash / Nagad / Bank Transfer payout, posted against a `SYSTEM` / `PAYOUT` sink.
- **Add the `PAYOUT` system role** alongside the existing `FUNDING`, `ESCROW` and `TREASURY`
  roles, so money leaving the platform has a counterparty.
- **Repoint the navigation.** The sidebar, the dashboard and the wallet page now offer Add
  Money, Withdraw Money and Fund Loan. The Receive Money page explains that money arrives
  through the loan workflow.

## The decisions this change records

**H-1 (SYSTEM ledger accounts) remains 🟠 Proposed and NOT approved.** This change does not
approve it. It adds two more roles to the same unapproved umbrella — `ESCROW` for custody and
`PAYOUT` for the external sink — and both are used **only** inside the local, development-only
sandbox that `add-sandbox-wallet-funding` established.

**No interest, penalty or collection policy is invented.** The PRD fixes the loan range
(BDT 5,000–50,000), the status set, the 3-day grace period and the 0.5% daily late penalty.
It does **not** define a rate schedule, risk-based pricing, restructuring, write-off or
collection policy. The interest rate is therefore a **borrower-stated integer basis-point
field**, not a computed schedule, and no policy beyond the PRD's own words is implemented.

**No admin approval gate on funding.** The PRD places no approval step between a lender and a
listing, so none is added. KYC and role permissions are unchanged.

**No real gateway is contacted.** Funding uses only the wallet balance. Withdrawals use a
simulated provider whose outcome is clearly labelled as simulated and is always the user's
stated intent, never a real provider confirmation.

## What is NOT changed

- **`../P2P_SPECIFICATION.md` is not modified.**
- **`.env` is not modified.**
- **No real money moves.** No external provider API is called and no real credential is
  collected, transmitted or stored.
- **No ledger constraint is weakened.** The append-only triggers and every `CHECK` constraint
  are intact.
- **No wallet balance is ever overwritten.** Balances move only by the amount the ledger
  entries carry.
- **No existing record is deleted or rewritten.** The `Send Money` page is deleted; the
  `demo_payments` and `demo_balances` tables and their rows are left in place, as before.
- **H-1 is not approved, and C-4 is not resolved.**

## Impact

- Affected specs: `lending/marketplace` (new), `lending/repayment` (new),
  `wallet/withdrawal` (new)
- Affected code: the `loans` and `wallet/withdrawals` route groups, `LoanController`,
  `WithdrawalController`, `LoanService`, `RepaymentService`, `WithdrawalService`,
  `LedgerPostingService`, the lending models and enums, and the Sidebar / Dashboard /
  Wallet / Receive Money pages.
- Affected data: three new tables (`loan_requests`, `loan_investments`, `loan_installments`)
  and one new table (`wallet_withdrawals`); the `ledger_accounts.system_role` ENUM gains
  `PAYOUT`. All existing rows are untouched.
