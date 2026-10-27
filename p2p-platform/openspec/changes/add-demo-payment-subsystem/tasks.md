# Tasks

**Scope.** BMAD — a new DEMO payment subsystem: two additive tables, a server-controlled payment
state machine, an idempotent demo balance, an Inertia "Add Money" flow, and the UI that presents
the demo balance beside the authoritative wallet balance.

**Every task states how to verify completion.** Tests land in the group whose work calls for them.

**Not in this task list, by instruction:** running a migration against a live database, changing
`.env`, committing, or pushing. The apply phase creates and reviews the migration files; running
them against the development schema is a separate, explicit step.

**Blocked-task notation.** A task marked **⛔ BLOCKED** names the decision that blocks it. No
blocked task is silently scheduled.

**Database rule for every task in this change.** The test schema (`p2p_platform_test`, port 3307)
is used by the automated suite. The development schema (`p2p_platform_dev`, port 3308) is touched
only by the explicit, final verification step (group 8). The shared workstation instance on port
3306 is **never** touched.

---

## 1. Schema — the two additive tables

**Covers** spec requirements *The demo balance is separate from the authoritative wallet balance*,
*The demo subsystem leaves the authoritative ledger schema intact*. **Design** D-1.

- [x] 1.1 Create the `create_demo_balances_table` migration with `id BIGINT UNSIGNED AUTO_INCREMENT`, `owner_user_id BIGINT UNSIGNED NOT NULL` with FK to `users(id)` and **no** referential action, `currency CHAR(3) NOT NULL DEFAULT 'BDT'`, `balance_minor BIGINT UNSIGNED NOT NULL DEFAULT 0`, `created_at`/`updated_at` as `DATETIME(6) NOT NULL`; verify the file exists and `php -l` reports no syntax error
- [x] 1.2 Add the `uq_demo_balances_owner` unique index on `owner_user_id` and the `ck_demo_balances_currency` CHECK restricting `currency` to `'BDT'`; verify by attempting to create two balances for one user and asserting the unique violation, and by attempting `'USD'` and asserting the CHECK violation
- [x] 1.3 Create the `create_demo_payments_table` migration with `id BIGINT UNSIGNED AUTO_INCREMENT`, `reference VARCHAR(32) NOT NULL`, `owner_user_id BIGINT UNSIGNED NOT NULL` with FK to `users(id)`, `method ENUM('BKASH','NAGAD','BANK_TRANSFER','CARD') NOT NULL`, `status ENUM('PENDING','SUCCEEDED','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING'`, `amount_minor BIGINT UNSIGNED NOT NULL`, `currency CHAR(3) NOT NULL DEFAULT 'BDT'`, `failure_reason VARCHAR(120) NULL`, `settled_at DATETIME(6) NULL`, `created_at`/`updated_at` as `DATETIME(6) NOT NULL`; verify the file exists and `php -l` reports no syntax error
- [x] 1.4 Add the `uq_demo_payments_reference` unique index on `reference`, the `ix_demo_payments_owner` index on `owner_user_id`, and the `ck_demo_payments_currency` CHECK; verify by attempting to reuse a reference and asserting the unique violation, and by attempting `'USD'` and asserting the CHECK violation
- [x] 1.5 Confirm via `SHOW CREATE TABLE` that both new tables use `bigint unsigned` for every money column and contain no `DECIMAL`, `FLOAT` or `DOUBLE` column; verify the check output shows unsigned integer types only
- [x] 1.6 Write a test asserting the migration is purely additive: `wallets`, `ledger_accounts` and `ledger_entries` are unchanged after it, and the three ledger triggers (`trg_ledger_entries_no_update`, `trg_ledger_entries_no_delete`, `trg_wallets_account_id_immutable`) are still installed; verify the test passes

## 2. Enums, models and configuration

**Covers** spec requirements *The demo balance is separate from the authoritative wallet balance*,
*Demo amounts are validated as positive integer minor units*. **Design** D-4, D-5.

- [x] 2.1 Create the `DemoPaymentMethod` backed string enum with `BKASH`, `NAGAD`, `BANK_TRANSFER` and `CARD`, each carrying a display label, a short description and the fictional-input hint its screen needs; verify the enum instantiates and every case has a label
- [x] 2.2 Create the `DemoPaymentStatus` backed string enum with `PENDING`, `SUCCEEDED`, `FAILED` and `CANCELLED`, plus a `isTerminal()` helper and the legal transition table; verify the transition table refuses a transition out of a terminal state
- [x] 2.3 Create the `DemoPayment` model with `method` and `status` cast to their enums, `amount_minor` cast to `IntegerPoisha`, `$guarded = ['*']` with `$fillable = ['id']`, a `belongsTo(User::class)` relation and a `scopeForOwner` scope; verify the casts return the expected types
- [x] 2.4 Create the `DemoBalance` model with `balance_minor` cast to `IntegerPoisha`, `$guarded = ['*']` with `$fillable = ['id']`, a `belongsTo(User::class)` relation and a `scopeForOwner` scope; verify the casts return the expected types
- [x] 2.5 Create `config/p2p-demo.php` with `max_amount_minor` and `daily_max_amount_minor`, read from the environment with safe defaults, and a comment recording that these are **local demo limits, not regulatory ceilings**, and that C-3 is ⚪ Open; verify the configuration resolves and the comment is present
- [x] 2.6 Create the `DemoPaymentIntent` value object with the three intents (`CONFIRM`, `FAIL`, `CANCEL`) and the mapping from intent to terminal status; verify the mapping is total over the three intents

## 3. The server-controlled payment state machine

**Covers** spec requirements *The demo payment outcome is decided by the server*, *Only a successful demo payment increases the demo balance*, *Demo crediting is idempotent and atomic*. **Design** D-2, D-3.

- [x] 3.1 Implement `DemoPaymentService::start()`: refuse a user without an approved identity verification; parse and validate the amount against the configured demo limits; create the payment in `PENDING` with a freshly generated server-side reference; verify the test passes
- [x] 3.2 Implement `DemoPaymentService::settle()`: open one transaction, lock the payment row and the demo balance row, perform the transition only if the payment is still `PENDING`, and credit the demo balance only on the transition to `SUCCEEDED`; verify the test passes
- [x] 3.3 Make the credit conditional on the transition actually happening, so a repeated or concurrent settlement of one payment credits exactly once; verify by settling the same payment twice and by settling it from two concurrent connections
- [x] 3.4 Implement `DemoPaymentService::balanceFor()`, returning the user's demo balance and creating it at zero when it does not exist; verify the test passes
- [x] 3.5 Implement the reference generator so that references are unique and never accepted from a client; verify two generated references differ and that a duplicate is rejected by the database

## 4. Validation and authorisation

**Covers** spec requirements *Demo payments are scoped to their owner*, *A demo payment requires an approved identity*, *Demo amounts are validated as positive integer minor units*, *The demo payment methods are simulations and no real credential is collected*. **Design** D-4, D-7, D-8.

- [x] 4.1 Create the `DemoPaymentStoreRequest` validating the method, the amount, and the method-specific demo details; verify the request rejects a missing method, an unknown method, a zero amount, a negative amount and an amount above the configured demo limit
- [x] 4.2 Parse the amount from a decimal **string** into integer Poisha using string arithmetic, so no floating-point operation touches the value; verify that 5000.7 becomes exactly 500070 Poisha and that a value with more than two decimal places is refused
- [x] 4.3 Validate the simulated card details against the documented fictional test-card rule, and refuse anything else with a message that states fictional test data is required; verify the test passes
- [x] 4.4 Assert that no demo request ever accepts a status, a balance, a credited amount or a reference as input; verify by inspecting the validated payload of every demo request and asserting those keys are absent
- [x] 4.5 Scope every demo read and write by the authenticated session's user, never by a request field; verify a peer cannot read, settle or balance-read another user's demo data

## 5. Controllers and routes

**Covers** spec requirements *The demo payment outcome is decided by the server*, *Demo payments are scoped to their owner*. **Design** D-2, D-8.

- [x] 5.1 Add the Inertia web routes for the demo payment flow under the existing `auth`/`verified` middleware group, using the project's route-file conventions; verify the routes resolve to their named routes
- [x] 5.2 Implement the controller action that starts a demo payment and returns the payment's identifier and the next screen's state; verify the response is an Inertia redirect/response and not a JSON API
- [x] 5.3 Implement the controller action that settles a demo payment from a client-requested intent; verify the resulting status comes from the server and a replay is refused
- [x] 5.4 Implement the controller action that returns the demo balance and history scoped to the owner; verify a peer receives only their own data
- [x] 5.5 Confirm that no REST-style JSON API endpoint was added and that the demo screens are ordinary Inertia pages; verify by listing the registered demo routes and asserting each returns an Inertia response

## 6. Frontend — the Add Money flow

**Covers** spec requirements *Every demo surface states that no real money was transferred*, *The demo payment methods are simulations and no real credential is collected*, *The demo balance and history persist across sessions*. **Design** D-6, D-7.

- [x] 6.1 Add the "Add Money" entry point on the wallet page, opening the demo flow; verify it renders for an approved user and is absent for a user without an approved identity
- [x] 6.2 Build the amount step with BDT input, live integer-Poisha conversion, and the configured demo limit shown; verify the displayed limit is labelled as a demo limit
- [x] 6.3 Build the method step with the four methods, each with its label and description; verify all four are rendered
- [x] 6.4 Build the four method-specific simulated screens: a mobile number for each mobile wallet, a simulated bank and account selection, and a simulated card form with clearly fictional test data; verify each screen is rendered for its method and carries the demo disclaimer
- [x] 6.5 Add the Confirm, Simulate Failure and Cancel actions on the method screen, with loading, disabled and error states; verify the actions are present and the screen cannot be submitted twice concurrently
- [x] 6.6 Build the receipt, showing the demo reference, the method, the amount, the status and the date and time, with the demo disclaimer; verify all five facts are rendered
- [x] 6.7 Build the demo transaction history, listing the user's payments with reference, method, amount, status and date; verify the list is scoped to the owner and each row carries the disclaimer
- [x] 6.8 Present the real wallet balance and the demo balance in visibly distinct panels on the wallet page, each labelled as such, with the demo balance never called "available"; verify both are rendered and labelled
- [x] 6.9 Show the demo balance and the demo payment count on the dashboard, distinctly from the real balance; verify the dashboard renders both without conflating them

## 7. Tests

**Covers** every scenario in the spec delta. **Design** all.

- [x] 7.1 Test each of the four methods: a payment can be created and settled for each; verify all four pass
- [x] 7.2 Test the three outcomes — success, failure and cancellation — and assert the demo balance moves only on success; verify the three pass
- [x] 7.3 Test the invalid amounts — zero, negative, above the demo limit, and more than two decimal places — and assert no payment is created; verify the four pass
- [x] 7.4 Test the KYC gate — a user without an approved identity verification is refused; verify the test passes
- [x] 7.5 Test the duplicate-request protection — replaying a creation request creates no second payment, and a reused reference is rejected by the database; verify the two pass
- [x] 7.6 Test the double-credit prevention — settling the same payment twice credits once, and two concurrent settlements of one payment credit once; verify the two pass
- [x] 7.7 Test the simultaneous-submission safety — two different payments confirmed concurrently credit the exact sum, exactly once each; verify the test passes
- [x] 7.8 Test the cross-user authorisation — a peer cannot read, settle or balance-read another user's demo data; verify the three pass
- [x] 7.9 Test the persistence — the demo balance and history survive a refresh and a sign-out/sign-in cycle; verify the two pass
- [x] 7.10 Test the receipt and history accuracy — every displayed fact matches the stored payment, and the history contains only the owner's payments; verify the two pass
- [x] 7.11 Test that the **real** wallet balance is unchanged by every demo outcome, and that the real ledger contains no new entries; verify the test passes
- [x] 7.12 Test that the existing database triggers remain intact after the demo migration is applied; verify the test passes
- [x] 7.13 Test that every demo surface renders the "no real money was transferred" disclaimer; verify the test passes
- [x] 7.14 Test that the demo balance is never read by any settlement or transfer code path — a tripwire that greps the production code for `demo_balances` outside the demo subsystem; verify the test passes

## 8. Integration verification

*Cross-cutting checks only. Every test owed by groups 1–7 landed in its own group.*

- [x] 8.1 Run the full test suite and verify it passes with no pre-existing test broken; verify the suite's exit status is success
- [x] 8.2 Run `vendor/bin/phpstan analyse --memory-limit=1G` and verify it reports no errors; verify the exit status is success
- [x] 8.3 Run `vendor/bin/pint --test` and verify it reports no style issues; verify the exit status is success
- [x] 8.4 Run `npx tsc --noEmit` and the frontend production build; verify both succeed
- [x] 8.5 Apply the migration to the isolated **test** schema and roll it back cleanly, confirming no `demo_balances` or `demo_payments` table is left behind; verify the rollback output
- [x] 8.6 Apply the migration to the **development** schema on port 3308 only, after a read-back of the pre-existing users, wallets, ledger accounts and ledger entries; verify the counts are unchanged and the three ledger triggers are still installed
- [x] 8.7 Verify the complete journey in a browser at `http://127.0.0.1:8000/`: sign in, open the wallet, add money, complete a simulated successful payment, and see the demo balance, the receipt and the history — then refresh and sign out and back in and confirm the demo balance persists while the real wallet balance is unchanged; verify each step
  - **Verified by a real HTTP client, not by eyeballing the screen.** `storage/app/p2p_demo_browser_check.php` drives the
    running dev server over real HTTP with a cookie jar, `X-Inertia: true`, `X-Inertia-Version` and the URL-decoded
    `X-XSRF-TOKEN` header — the exact request shape the React app sends — so the whole middleware stack
    (session, CSRF, `auth`, `verified`, the FormRequest, the controller, MySQL) is exercised end to end. All eleven
    sections passed. A headless-Chrome *screenshot* capture was attempted for visual proof and abandoned: Chrome's
    `--screenshot` flag cannot carry the session cookie, so it redirected to `/login`, and the DevTools-Protocol
    fallback hung on the socket read. The screenshot script was deleted; no application code or data was affected.
- [x] 8.8 Verify `P2P_SPECIFICATION.md` is byte-identical to its state before this change (md5 `a9e2b6cc16d140a8de83a69450f83026`) and that no `.env` file was modified; verify the checksums match
- [x] 8.9 Verify `git status` shows only the intended new files and that no commit or push occurred; verify the git log is unchanged

---

## ⛔ BLOCKED — not scheduled in this change

These are recorded so they are not forgotten. Each names its blocker. **None is silently
scheduled.**

- [ ] ⛔ **BLOCKED by H-1** (SYSTEM accounts, 🟠 Proposed, not approved) — create SYSTEM accounts and
  the funding entry pair, so that a **real** wallet balance can become non-zero. *This change
  deliberately does not implement it, and does not approximate it: the demo balance is a separate
  subsystem with its own table and is not spendable.*
- [ ] ⛔ **BLOCKED by C-3** (business limit, ⚪ Open) — the real numeric limit, daily cap and
  regulatory ceilings. *This change enforces a configurable **local demo limit** and invents no
  Bangladesh compliance rule. When C-3 is decided, `config/p2p-demo.php` is re-pointed at the
  decided value.*
- [ ] ⛔ **BLOCKED by C-4** (funding honesty, 🟠 Proposed, not approved) — give a real wallet a
  first, honest, ledger-backed balance. *Blocked by H-1. The demo subsystem is the workaround, and
  it is labelled as such everywhere.*
- [ ] ⛔ **BLOCKED by M-2** (canonicalization, ⚪ BLOCKED) — implement the hash chain over a
  canonical body. *Not part of this change: no ledger entry is written.*
- [ ] ⛔ **BLOCKED by M-3** (genesis literal, ⚪ Open) — seed the chain head's genesis value.
  *Not part of this change.*
- [ ] ⛔ **BLOCKED by OQ-1 … OQ-4** (⚪ Not approved) — enable production real-money transfers,
  production reversals, or the production transaction and cumulative ceilings. *Not part of this
  change; the demo subsystem is not a substitute for any of them.*
- [ ] ⛔ **BLOCKED by G4** (⚙️ PENDING) — apply `INSERT`/`SELECT`-only privileges on the chain
  tables to a separate runtime role. *An infrastructure task, not a migration.*

## Workflow follow-up

- Apply the change with the `openspec-apply-change` workflow.
- Reconcile the BMAD ticket tree: the demo payment subsystem is **not** part of BMAD Epic 1
  (`ledger-core`) and is **not** the marketplace (H-4, Phase 2). It is a local development and
  demonstration aid, and it is recorded as such in the epic's `.memlog.md` rather than as a
  replacement for any epic story.
- Archive the change only after the BMAD review requirements are satisfied.
