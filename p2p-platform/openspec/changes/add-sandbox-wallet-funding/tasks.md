# Tasks

## 1. The schema

- [x] 1.1 Create the `wallet_fundings` table — `reference` (unique), `owner_user_id`, `method`,
      `status`, `amount_minor`, `currency`, `failure_reason`, `details` (JSON), `transaction_id`,
      `idempotency_key` (unique), `settled_at`, `created_at`, `updated_at`
- [x] 1.2 Add the CHECK constraints as raw DDL — `ck_wallet_fundings_currency`
      (`currency = 'BDT'`) and `ck_wallet_fundings_amount` (`amount_minor > 0`)
- [x] 1.3 Add the foreign keys to `users` and `transactions` with **no** referential action
- [x] 1.4 Use `DATETIME(6)` for every timestamp, not `TIMESTAMP`
- [x] 1.5 Change **no** existing table, column, constraint, index or trigger
- [x] 1.6 Leave the `demo_payments` and `demo_balances` tables and their rows in place, for audit
- [x] 1.7 Apply the migration to the development schema and verify it reports `DONE`

## 2. The models and enums

- [x] 2.1 `FundingMethod` — `BKASH`, `NAGAD`, `BANK_TRANSFER`, `CARD`, with professional labels
- [x] 2.2 `FundingStatus` — `PENDING`, `SUCCEEDED`, `FAILED`, `CANCELLED`, with professional labels
- [x] 2.3 `FundingIntent` — `CONFIRM`, `FAIL`, `CANCEL`, with the terminal state each maps to
- [x] 2.4 `WalletFunding` — a guarded model with the enum casts, an `owner()` and a
      `transaction()` relation and a `scopeForOwner()`
- [x] 2.5 `FundingDetails` — validate and reduce the method-specific inputs to their minimal
      storable form

## 3. The ledger posting

- [x] 3.1 `LedgerPostingService::postPair()` — append exactly two entries of equal magnitude
      and opposite direction, in one transaction, with both accounts locked in sorted id order
- [x] 3.2 Refuse a posting whose source and destination are the same account
- [x] 3.3 Refuse a posting whose amount is not positive
- [x] 3.4 Refuse a posting that would take a **user** wallet below zero
- [x] 3.5 Refuse a posting that names a `SYSTEM` account as its **destination**
- [x] 3.6 Advance `wallets.balance_minor` by `balance_minor + delta` under a row lock, **after**
      the entries exist
- [x] 3.7 Allocate the per-account sequence number from `ledger_accounts.next_sequence_number`
      and advance it
- [x] 3.8 Chain each entry to the previous entry's `entry_hash`, using a **local, documented**
      canonical form (M-2 is ⚪ BLOCKED — this is **not** claimed to be the M-2 rule)
- [x] 3.9 Use a **local, documented** genesis constant (M-3 is ⚪ Open — this is **not** claimed
      to be the specification-approved value)
- [x] 3.10 `sandboxFundingAccount()` — lazily create exactly one `SYSTEM` / `FUNDING` account
      with no owner
- [x] 3.11 Record a wallet-less `SYSTEM` account's `balance_after` as its cumulative sourced
      amount, because `balance_after` is unsigned and constrained to be non-negative

## 4. The funding workflow

- [x] 4.1 `WalletFundingService::start()` — validate eligibility, parse the amount by string
      arithmetic, reduce the details, check the limits, create the pending attempt
- [x] 4.2 Assert an **approved identity** before any funding attempt
- [x] 4.3 Enforce the sandbox per-payment and daily limits
- [x] 4.4 Honour the idempotency key — a replayed creation returns the original attempt
- [x] 4.5 `WalletFundingService::settle()` — map the intent to a terminal state under a
      `FOR UPDATE` lock, with a conditional `UPDATE ... WHERE status = 'PENDING'`
- [x] 4.6 Credit the main wallet **only** when the conditional update changed a row
- [x] 4.7 Create the `transactions` row that links the two ledger entries and makes the credit
      visible in the main transaction history
- [x] 4.8 Audit every start, success, failure and cancellation, and audit a denied
      cross-user settlement

## 5. The request and controller

- [x] 5.1 `WalletFundingStoreRequest` — an explicit allow-list of accepted keys
- [x] 5.2 Strip `status`, `balance_minor`, `credited_minor`, `amount_minor`, `reference`,
      `owner_user_id`, `wallet_id`, `transaction_id`, `account_id`, `pin`, `otp`, `password` and
      `cvv` before validation runs
- [x] 5.3 `WalletFundingController::index()` — the Add Money screen
- [x] 5.4 `WalletFundingController::store()` — start an attempt and redirect to its payment screen
- [x] 5.5 `WalletFundingController::show()` — the payment screen and the receipt
- [x] 5.6 `WalletFundingController::settle()` — accept **only** `intent`
- [x] 5.7 `WalletFundingController::history()` — the funding history
- [x] 5.8 Scope every read and write to the authenticated user

## 6. The interface

- [x] 6.1 `wallet/add-money.tsx` — the amount step, the method step and the details step
- [x] 6.2 `wallet/funding.tsx` — the payment screen and the receipt
- [x] 6.3 `wallet/funding-history.tsx` — the payment history
- [x] 6.4 Use the professional labels throughout — Add Money, Wallet Balance, bKash, Nagad,
      Bank Transfer, Debit / Credit Card, Payment Successful, Payment Failed
- [x] 6.5 State the sandbox **once**, in the page chrome, outside the payment interface
- [x] 6.6 Show the wallet balance and an "Add Money" button on the Wallet page
- [x] 6.7 Show the wallet balance and an "Add Money" button on the Dashboard
- [x] 6.8 Show the Add Money credit in the main Transactions page, with an "Add Money" type pill
- [x] 6.9 Add the "Add Money" navigation item
- [x] 6.10 Add the `funding`/`funding-history` pages to the Inertia page resolver

## 7. Removing the previous demo subsystem

- [x] 7.1 Remove `DemoPaymentService`, `DemoPaymentController`, `DemoPaymentStoreRequest`,
      `DemoPayment`, `DemoBalance`, `DemoPaymentDetails` and the demo enums
- [x] 7.2 Remove `config/p2p-demo.php`
- [x] 7.3 Remove the `demo/payments` route group
- [x] 7.4 Remove the `resources/js/pages/demo/` directory
- [x] 7.5 Remove the demo panels from the Wallet page and the Dashboard
- [x] 7.6 Remove the `demo` prop from `DashboardController` and `WalletController`
- [x] 7.7 Rename `DemoBanner` to `NoticeBanner` and `DemoTag` to `SandboxTag`, then remove
      `SandboxTag` once it is unused
- [x] 7.8 Remove the demo payment subsystem's own tests
- [x] 7.9 **Do not** drop the `demo_payments` / `demo_balances` tables, and **do not** credit the
      previously recorded demo balance to the main wallet

## 8. The tests

- [x] 8.1 A successful funding credits the main wallet by exactly the paid amount
- [x] 8.2 Exactly two ledger entries, equal magnitude, opposite direction, `SUM(DEBIT) = SUM(CREDIT)`
- [x] 8.3 The credit lands on the user's account and the debit on the funding account
- [x] 8.4 The wallet projection equals the sum of its ledger entries
- [x] 8.5 A failed payment credits nothing
- [x] 8.6 A cancelled payment credits nothing
- [x] 8.7 A repeated settlement credits once
- [x] 8.8 Concurrent settlements credit once
- [x] 8.9 A replayed creation creates one attempt and credits once
- [x] 8.10 A peer cannot read, settle or see another user's funding
- [x] 8.11 An unapproved identity cannot fund
- [x] 8.12 A zero, a negative and an over-limit amount are refused
- [x] 8.13 The card security code is never stored, and a real-looking card is refused
- [x] 8.14 Every one of the four methods behaves identically
- [x] 8.15 The Dashboard, the Wallet, the Transactions list and the transaction detail show the
      credit
- [x] 8.16 The balance survives a refresh, a sign-out and a sign-in
- [x] 8.17 A forged `status` in the create request is stripped
- [x] 8.18 A forged `status` in the settle request is ignored
- [x] 8.19 The demo balance routes are gone
- [x] 8.20 The Add Money screen carries no demo branding

## 9. Documentation and bookkeeping

- [x] 9.1 `config/p2p-funding.php` — the sandbox policy, with the H-1 and C-3 status stated
- [x] 9.2 This change — the proposal, the design and the delta spec
- [x] 9.3 The epic memlog — a dated entry for the sandbox decision
- [x] 9.4 Re-run `openspec validate add-sandbox-wallet-funding --strict`

## 10. The explicitly deferred items

These are **not** done, and are **not** approved by this change. Each names its blocker.

- [ ] 10.1 H-1 — SYSTEM accounts and the funding source (🟠 Proposed, not approved). **BLOCKER:**
      the specification decision has not been made. This change implements a *local sandbox*
      policy around it and approves nothing.
- [ ] 10.2 C-4 — funding cannot be modelled honestly yet (⛔). **BLOCKER:** depends on H-1.
- [ ] 10.3 C-3 — the business limit (⚪ Open). **BLOCKER:** undecided. The enforced limits are
      sandbox limits, not regulatory ceilings.
- [ ] 10.4 M-2 — the canonicalization rule (⚪ BLOCKED). **BLOCKER:** undecided. The chain uses a
      local, documented canonical form and does **not** claim to implement the M-2 rule.
- [ ] 10.5 M-3 — the genesis constant (⚪ Open). **BLOCKER:** undecided. A local, documented
      constant is used and does **not** claim to be the specification-approved value.
- [ ] 10.6 H-6 / D1 — `ledger_entries.status` (🟠 Proposed). **BLOCKER:** undecided, and out of
      scope for this change.
