# Design

## Context

See `proposal.md` — Why, for the motivation. This section records only the current state and the
constraints that shape the approach.

**Current state.** The project has a real ledger substrate (`ledger_accounts`, `wallets`,
`ledger_entries`), an append-only trigger set on the ledger, a KYC gate, and a working
`WalletProvisioningService` that gives every KYC-approved user exactly one zero-balance wallet
bound to exactly one ledger account. The wallet page renders the authoritative, ledger-backed
balance. The wallet balance is **৳0 and must remain ৳0**, because H-1 (SYSTEM accounts) is
🟠 **Proposed (not approved)** and C-4 records that funding cannot yet be modelled honestly.

**Constraints that shape the design:**

- **MySQL 8.0.46, InnoDB**, `sql_mode` includes `STRICT_TRANS_TABLES`.
- **Money is `BIGINT UNSIGNED` integer Poisha** (1 BDT = 100 Poisha). A2. No float, no decimal,
  ever.
- **The ledger is append-only.** `ledger_entries` is protected by BEFORE UPDATE/DELETE triggers
  (SQLSTATE 45000), and `wallets.account_id` is protected by
  `trg_wallets_account_id_immutable`. This change adds no column to, and no row to, any of those
  tables.
- **Laravel 13 / PHP 8.3 / React 19 / Inertia 3 / Tailwind 4.** Inertia web routes and page
  props; no REST API.
- **`users.id` is `BIGINT UNSIGNED`** — this fixes the FK column type for both new tables.
- **KYC `APPROVED` is a hard precondition** for any money-shaped write path (SPEC §11.1,
  §7.1 step 2). The demo subsystem honours the same precondition.

**The isolation rule that governs every decision below.** The demo subsystem owns two tables and
nothing else. It reads `users` (ownership), `kyc_submissions` (eligibility), and `wallets` /
`ledger_accounts` (to display the authoritative balance beside the demo balance). It **writes
only** `demo_payments` and `demo_balances`. There is no code path — in this change or any future
one — that reads `demo_balances` in order to move, spend, reserve, settle or withdraw money. That
is what makes the demo balance incapable of becoming real money.

## Goals / Non-Goals

**Goals:**

- A **working** "Add Money" feature, not a mock-up: a real database write, a real balance, a real
  receipt, a real history that survives a refresh and a re-login.
- **Absolute separation** from the authoritative ledger-backed balance, enforced by construction
  and proven by tests.
- A **server-controlled state machine**: the client may ask for an outcome; the server decides it.
- **Exact integer arithmetic** and an **idempotent, atomic** credit.
- **A truthful UI**: every demo surface says, prominently, that no real money moved.

**Non-Goals:**

- Any real payment gateway, card network, bank integration, or API call. All four methods are
  simulations with no network egress.
- Any collection of real payment credentials. The card form takes fictional test data only, and
  says so on the field itself.
- Any spendable demo money. There is no withdrawal, no transfer, no settlement, no reversal path
  that reads the demo balance.
- Any change to `wallets`, `ledger_accounts`, `ledger_entries`, `transactions`, or the trigger set.
- Any production enablement of real money movement.

## Decisions

### D-1 — The demo balance lives in its own table, not in `wallets`

**Decision.** The demo balance is `demo_balances.balance_minor`, one row per user, and is never
mirrored into `wallets`.

**Rationale.** `wallets.balance_minor` is the **projection** of authoritative ledger entries
(C-2). A demo counter is not a projection of anything, so putting it in `wallets` would make the
wallet's balance a mixture of two incompatible truths — one derived from the ledger, one invented
by a simulation. The moment it did that, reconciliation (Epic 5) would have to know which half to
believe, and no answer would be right. Keeping them in separate tables means the wallet remains
exactly what the specification says it is.

**Alternatives considered.** *A separate `demo_balance_minor` column on `wallets`* — rejected: it
still mixes the two truths in one row, and it would require a migration on a table whose
`account_id` and money columns are already part of the hashed-body contract. *Deriving the demo
balance from the sum of `demo_payments`* — rejected as the storage of record: it makes the
balance a `SUM()` over a growing table on every page load, and it makes "available balance" a
query rather than a fact. The balance is stored, and the payment rows are the audit trail that
explains it.

### D-2 — The payment status is a server-owned state machine

**Decision.** A demo payment's status is `PENDING` at creation and may then move, exactly once,
to `SUCCEEDED`, `FAILED` or `CANCELLED`. The transition is performed by the server. The client
sends an *intent* (`confirm`, `fail`, `cancel`); the server maps that intent to a state
transition and performs it.

**Rationale.** The requirement is: *"do not accept arbitrary balance values or payment success
claims from untrusted clients without enforcing a server-controlled simulation state transition."*
If the client could post `status=SUCCEEDED`, it could post `status=SUCCEEDED` twice, or
`status=SUCCEEDED&amount=999999`, and the server would be a rubber stamp. Instead the client asks
for a *simulation* and the server runs the simulation. The status column is not settable from a
request; it is settable only by `DemoPaymentService`.

**Alternatives considered.** *A per-method deterministic rule (e.g. the card's last digit decides
the outcome)* — rejected as surprising and untestable-by-intent; the user must be able to
demonstrate all three outcomes on demand. *A random server-side outcome* — rejected because the
user's demonstration flow explicitly requires the ability to show success, failure and
cancellation.

### D-3 — Crediting is idempotent and atomic

**Decision.** Crediting happens inside one transaction that (a) locks the `demo_balances` row
`FOR UPDATE`, (b) re-reads the payment row, (c) checks the status is still `PENDING`, (d) applies
the transition, and (e) increments the balance — crediting **only** when the transition to
`SUCCEEDED` actually happened.

**Rationale.** The requirement is: *"idempotent success processing … duplicate-submission and
replay protection … safe concurrent payment processing."* A `WHERE status = 'PENDING'` conditional
update makes the transition itself the guard: the first caller updates one row and the second
caller's update affects zero rows, so the second caller never credits. Combined with the row
lock on the balance, two concurrent confirmations of *different* payments still serialise
correctly, and two concurrent confirmations of the *same* payment credit once.

**Alternatives considered.** *A separate `idempotency_keys` table* — rejected: the payment id
*is* the idempotency key, and adding a second table would be a third place for the same fact.
*Checking the status in PHP and then updating* — rejected: a read-then-write race between two
workers would credit twice. The guard must be in the `UPDATE` itself.

### D-4 — Amounts are integer Poisha end to end

**Decision.** The client sends a decimal string of Taka; the server parses it into integer Poisha
using string arithmetic (no float ever touches the value), and the *server* is the only place the
amount is interpreted. The stored amount is `BIGINT UNSIGNED` Poisha.

**Rationale.** A2 requires integer minor units. A float would round ৳5,000.005 unpredictably and
would make "exactly the approved amount" unprovable. The client also cannot assert the amount's
Poisha value — it sends a Taka string, and the server converts. The conversion is
`whole * 100 + fraction`, with the fraction read as a string and padded, so `5000.7` is exactly
`500070` Poisha and never `500069.9999999999`.

### D-5 — The demo ceiling is a configurable demo limit, not a regulatory limit

**Decision.** `config/p2p-demo.php` exposes `max_amount_minor` (default ৳50,000) and
`daily_max_amount_minor` (default ৳100,000), overridable by environment variable. The UI labels
them as demo limits. The configuration file says in a comment that these are **not** regulatory
ceilings.

**Rationale.** C-3 — the business limit — is ⚪ **Open**, and the decision register says plainly:
*"Do not invent Bangladesh compliance rules."* A limit is nonetheless needed so the demo is not
unbounded. The honest resolution is a limit that is obviously a *demo* limit, is configurable, and
is never described as a compliance ceiling. The moment C-3 is decided, this configuration is
re-pointed at the decided value; the enforcement code does not change.

### D-6 — Every demo surface is labelled, and the labels are asserted by tests

**Decision.** The word "DEMO" and the statement that no real money was transferred appear on:
the Add Money entry point, each of the four method screens, the receipt, the demo balance panel,
the demo history, and the wallet page's demo section. The tests assert the presence of the
disclaimer text.

**Rationale.** The existing `DemoBanner` component (tones `info | warning | success | blocked`)
already exists for exactly this purpose, and the established `DemoController` precedent already
labels its screens. The risk this mitigates is a reader mistaking a demo balance for real funds —
which would be a materially misleading financial statement, not a cosmetic issue.

### D-7 — No real payment credentials are collected or stored

**Decision.** The card method accepts a **fictional test card number, expiry and CVV** on a form
that is explicitly labelled as a test card, with the fields pre-filled with obviously fake
placeholders. The mobile methods accept a formatted mobile number, which is a demo input and is
stored only as part of the demo payment record.

**Rationale.** The requirement is: *"never request or store real PINs, OTPs, CVVs, passwords, bank
credentials, or actual card details — do not collect sensitive real payment information."* The
system never asks for a PIN, an OTP, a bank password or a bank credential, at all. For the card,
the form asks only for data that is labelled as fictional test data, and the values are validated
against a test-card rule so that a real-looking card is rejected at validation time with an
explicit message.

### D-8 — Demo payments are scoped to their owner, and eligibility is KYC-approved

**Decision.** Every read and every write is scoped by `owner_user_id` from the authenticated
session, never from a request field. A user without an `APPROVED` KYC submission may not open
the flow, may not create a payment, and may not credit a balance.

**Rationale.** SPEC §11.1 makes verified KYC a hard precondition for any money-shaped write path,
and §7.1 step 2 requires it before a payer may be used. A demo balance is money-shaped, so the
same gate applies. Scoping by session (never by request) is what makes cross-user authorisation
impossible rather than merely unlikely.

## Data model (MySQL 8.0 DDL shape)

Every money column is `BIGINT UNSIGNED`. Every `CHECK` and `UNIQUE` constraint is named, because
later stories and the tests reference it by name.

### `demo_balances`

```sql
CREATE TABLE `demo_balances` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_user_id`  BIGINT UNSIGNED NOT NULL,
  `currency`       CHAR(3) NOT NULL DEFAULT 'BDT',
  `balance_minor`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME(6) NOT NULL,
  `updated_at`     DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_demo_balances_owner` (`owner_user_id`),
  CONSTRAINT `fk_demo_balances_owner` FOREIGN KEY (`owner_user_id`)
      REFERENCES `users` (`id`),
  CONSTRAINT `ck_demo_balances_currency` CHECK (`currency` = 'BDT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

- `uq_demo_balances_owner` — one demo balance per user. The data-layer guarantee behind
  idempotent crediting and behind "two concurrent creators make one row".
- `ck_demo_balances_currency` — BDT only (M-8, ✅ Technical default (unconfirmed)).
- A negative balance is physically unrepresentable: `BIGINT UNSIGNED` under
  `STRICT_TRANS_TABLES` (A2).
- **No** `ON DELETE`/`ON UPDATE` referential action on the FK, matching the schema-wide rule
  already enforced for `wallets` and `ledger_accounts`.

### `demo_payments`

```sql
CREATE TABLE `demo_payments` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`       VARCHAR(32) NOT NULL,
  `owner_user_id`   BIGINT UNSIGNED NOT NULL,
  `method`          ENUM('BKASH','NAGAD','BANK_TRANSFER','CARD') NOT NULL,
  `status`          ENUM('PENDING','SUCCEEDED','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `amount_minor`   BIGINT UNSIGNED NOT NULL,
  `currency`        CHAR(3) NOT NULL DEFAULT 'BDT',
  `failure_reason`  VARCHAR(120) NULL,
  `created_at`      DATETIME(6) NOT NULL,
  `updated_at`      DATETIME(6) NOT NULL,
  `settled_at`      DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_demo_payments_reference` (`reference`),
  KEY `ix_demo_payments_owner` (`owner_user_id`),
  CONSTRAINT `fk_demo_payments_owner` FOREIGN KEY (`owner_user_id`)
      REFERENCES `users` (`id`),
  CONSTRAINT `ck_demo_payments_currency` CHECK (`currency` = 'BDT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

- `uq_demo_payments_reference` — the unique demo payment reference. The receipt and the history
  are keyed on it, and a duplicate reference is impossible at the data layer.
- `ix_demo_payments_owner` — the history read path.
- `method` and `status` are native `ENUM`s, matching the `ledger_accounts.account_type` and
  `system_role` pattern already in the schema.
- `amount_minor` is `BIGINT UNSIGNED`: the amount can never be negative and is never a float.
- `settled_at` is set only when the payment reaches a terminal status.

### What is **not** in the DDL

No `ALTER`, no `CREATE TRIGGER`, no `INSERT` into `wallets`, `ledger_accounts` or `ledger_entries`.
The three existing ledger triggers are not touched, and the migration does not drop or recreate
any of them. The migration is **purely additive**: two `CREATE TABLE` statements.

## State machine

```
                    ┌──────────────┐
     create ───────►│   PENDING    │
                    └──┬───┬───┬───┘
                       │   │   │
      confirm ─────────┘   │   └──────── cancel ──► CANCELLED
                           │
                    fail ───┘ ──────────────► FAILED
                           │
                    confirm (server-simulated success) ──► SUCCEEDED ──► credit demo balance
```

- Every arrow is a **server**-side transition performed by `DemoPaymentService`.
- `PENDING → SUCCEEDED` is the only transition that credits the balance.
- A transition out of a terminal status is refused: the status row is read under lock, and the
  update is conditional on `status = 'PENDING'`. A refused transition returns the existing
  payment's state, so a replay is harmless and idempotent.
- A payment that is created and never confirmed simply stays `PENDING`; it never credits.

## Application layer

### `DemoPaymentService`

| Method | Responsibility |
|---|---|
| `start(User $user, DemoPaymentMethod $method, int $amountMinor, array $details): DemoPayment` | Validates eligibility and amount, creates the payment in `PENDING` with a fresh unique reference, and returns it. Does **not** credit. |
| `settle(User $user, DemoPayment $payment, DemoPaymentIntent $intent): DemoPayment` | Performs the server-side transition inside one transaction, crediting the demo balance exactly once when the transition to `SUCCEEDED` happens. |
| `balanceFor(User $user): DemoBalance` | The user's demo balance, created at zero if absent. |

The service is the **only** writer of `demo_balances.balance_minor` and the **only** writer of
`demo_payments.status`. No controller, no request object and no model event writes either.

### Reference format

`DEMO-{YYYYMMDD}-{ULID-ish 10 chars}` — uppercase, no ambiguity, unique by database constraint.
The reference is generated server-side and is never accepted from the client.

### Failure reason

A `FAILED` payment records a short, fixed, non-sensitive reason drawn from a server-side list
(`Simulated decline by the demo gateway.`). It never contains a gateway response body, because
there is no gateway.

## Frontend

- `resources/js/pages/demo/add-money.tsx` — the flow: amount → method → method screen → receipt.
- `resources/js/pages/wallet.tsx` — the real wallet balance panel and the demo balance panel,
  side by side and visibly distinct.
- `resources/js/pages/dashboard.tsx` — the demo balance and the demo transaction count.
- All money formatting goes through `resources/js/p2p/money.ts` (`formatMoney`, `takaToPoisha`),
  which already does integer-only arithmetic. `takaToPoisha` parses the decimal **string**, so
  the client never produces a float; the server re-parses independently and is authoritative.
- Every demo surface uses `DemoBanner` with `tone="warning"` and states that no real money was
  transferred.

## Risks

| Risk | Mitigation |
|---|---|
| A demo balance is mistaken for real money | Every surface is labelled (D-6); the label's presence is asserted by tests. The demo balance is a different colour, a different panel, and is never called "available". |
| A future story reads `demo_balances` and makes it spendable | The demo tables are namespaced `demo_*` and no settlement or transfer code exists. The isolation is asserted by a test that greps the settlement/transfer code paths for `demo_balances` — a cheap, permanent tripwire. |
| Double-credit through double submission or concurrency | The conditional status update plus the row lock plus the unique reference (D-3). |
| A demo limit is read as a regulatory limit | The configuration and the UI both label it a demo limit; C-3 is named as ⚪ Open in the config file's comment. |
| Real card data is submitted | The card form's fields are pre-labelled fictional and validated against a test-card rule (D-7). |
| The migration alters the authoritative schema | The migration contains only two `CREATE TABLE` statements; a test asserts `wallets`, `ledger_accounts` and `ledger_entries` are unchanged after it, and that the three ledger triggers are still installed. |
