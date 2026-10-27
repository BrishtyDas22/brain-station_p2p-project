# Design

## The shape of the problem

A lending platform has one structural fact that a transfer platform does not: **money has to
sit still for a while, in somebody else's name, without being spendable.** A transfer moves
money from A to B and is finished. A loan moves money from many lenders into a pool, holds it
there while the pool fills, then releases it to a borrower who does not yet have it, and then
pulls it back out again in installments across a period of months, splitting each installment
between the lenders who funded it in proportion to what each put in.

That holding state is the whole design problem. It is modelled here as a **SYSTEM ledger
account with the `ESCROW` role**: a real account in the same append-only ledger as the user
accounts, so the custody of other people's capital is audited by exactly the same machinery
that audits a user's own balance.

## The three system roles, and why there are four

The pre-existing `FUNDING` role is the **source** — the simulated gateway the outside world's
money arrives through. It is debited only, and its `balance_after` is the cumulative amount
sourced. It has no natural opposite: nothing in the platform ever legitimately *sends* money
back to the gateway.

This change needed two opposites, and the design's central decision was recognising that
they are different animals:

| Role | Direction | What it represents | `balance_after` |
|---|---|---|---|
| `FUNDING` | Debit only | The **source** — money entering the platform | Cumulative amount sourced |
| `ESCROW` | Both | **Custody** — user capital held inside the platform on someone else's behalf | Genuine running balance (`SUM(CREDIT) − SUM(DEBIT)`) |
| `PAYOUT` | Credit only | The **sink** — money leaving the platform | Cumulative amount paid out |
| `TREASURY` | — | Reserved, unused | — |

`FUNDING` and `PAYOUT` are a matched pair: the outside world's wallet, from the platform's
point of view. `ESCROW` is different in kind, because escrow holds **real user capital** and
its balance must never go negative — it is the only one of the three whose `balance_after` is
a true running balance.

### The credit rule

`LedgerPostingService::postPair()` previously refused to credit **any** system account,
because crediting the `FUNDING` account would have created money with no external inflow
behind it. That rule is still right for `FUNDING` and `TREASURY`, and it is now:

> A system account may be credited **only** if its role is `ESCROW` or `PAYOUT`, **and** only
> from a real user wallet.

The second half matters. Crediting escrow or payout from another system account would be
money appearing from nowhere. Crediting them **from a user wallet** is the opposite: it is
real user capital moving into custody, or leaving the platform.

## The funding path, step by step

A lender commits capital. That capital must stop being spendable, but it must not vanish.
So the commitment is a **balanced pair** against the escrow account:

```
DEBIT   lender's ledger account      500,000   (their money leaves their spendable balance)
CREDIT  ESCROW system account        500,000   (the same money, now in custody)
```

The lender's wallet projection falls by exactly 500,000. The escrow account's running
balance rises by exactly 500,000. The money is now in the platform, held for a borrower, and
cannot be spent twice.

When the listing reaches 100%, a **second** balanced pair releases it:

```
DEBIT   ESCROW system account        500,000   (custody ends)
CREDIT  borrower's ledger account    500,000   (the principal reaches the borrower)
```

The escrow balance returns to its previous level. The borrower's spendable balance rises by
the full principal. **The two postings are separate transactions** because they happen at
different times and for different reasons — the first is a commitment, the second is a
disbursement — and each must be independently auditable.

## Concurrency: the overfunding problem

The PRD's non-functional requirements are explicit:

> "The matching engine must enforce database-level pessimistic locking on loan objects during
> funding calls to prevent race-condition overfunding."

Two lenders clicking "Fund Loan" on the same listing at the same instant is the exact race
this describes. The fix is a `SELECT ... FOR UPDATE` on the **loan row** inside the funding
transaction:

```php
DB::transaction(function () {
    $request = LoanRequest::query()->whereKey($id)->lockForUpdate()->first();
    // ... only now is the remaining amount read, and only now is the
    // commitment counted against it.
});
```

Because the lock is held for the whole transaction, the second lender's read happens **after**
the first lender's write has committed. It sees the reduced remaining amount and is either
allowed to fund the rest or refused. Without the lock, both lenders would read the same
"5,000 remaining" and together commit 10,000 against a 5,000 loan.

Three further guards back this up:

1. **The escrow account cannot go negative.** It is unsigned, and a posting that would take it
   below zero is refused. Even if the lock were removed, the ledger would refuse to over-lock.
2. **The idempotency key makes a replay a no-op.** A unique index on
   `loan_investments.idempotency_key` means a double-submitted form produces one investment.
3. **The lender's wallet cannot go negative.** A lender cannot commit capital they do not
   have.

The unique index on `loan_investments(lender_user_id, loan_request_id)` additionally prevents
the same lender funding the same listing twice.

## The installment schedule and the proportional split

The schedule is generated when the loan is created, from the principal, the stated rate and
the tenure:

- The **total** repayable is `principal + principal × rate × tenure`, in integer Poisha.
- The schedule is split into `tenure` equal installments, so the principal and interest are
  each split evenly across the tenure.
- The **last** installment absorbs the remainder, so the installments sum to exactly the
  total. This is the rounding rule, and it is the only place rounding is decided.

When an installment is paid, it is distributed across the lenders **in proportion to what
each committed**:

```
lender's share = intdiv(installment_amount × lender_commitment, total_committed)
```

Integer division truncates, so the shares can be short by a few Poisha. The remainder goes to
the **largest** commitment, so the installment is distributed exactly — no Poisha is created
and none is lost. This is the same "largest commitment absorbs the remainder" rule the
disbursement uses.

## What the PRD does not say, and what was therefore not built

This is the part of the design that matters most, because a lending platform invites the
temptation to fill gaps with plausible-sounding policy.

| The PRD says | The PRD does **not** say | What was done |
|---|---|---|
| "displays the calculated repayment schedule and interest rates" | How the rate is *determined* — there is no rate schedule, risk table or default rate | The rate is a **borrower-stated integer bps field**, bounded by a sandbox ceiling. No rate is computed |
| "initiates a daily late penalty surcharge of 0.5% after grace period expiration" | How the penalty compounds, or on what base | 0.5% **of the installment amount** per whole day past the grace end — the most literal reading |
| "the loan enters a 3-day status of 'GRACE_PERIOD'" | What happens if the borrower never pays | **Nothing** — no write-off, no restructuring, no collection workflow |
| "alerts the borrower via SMS and push notification" | Which provider, which template, which number | **Nothing** — no provider is configured and none is invented. The grace event is audited |
| "If the funding window expires, funds return to the lender's wallet instantly" | How long the window is | **Deferred** — no window length is defined, so no expiry timer is invented |
| "based on risk scores, yield percentages" (lender marketplace view) | A risk-scoring model | **Nothing** — the marketplace shows the stated rate, not a computed risk score |
| "the daily repayment batch job runs" | A scheduler, a queue, a job class | **Nothing** — the borrower pays from the schedule screen. The PRD's *engine* is a background job; this change builds the *money path* that job would call |
| — | Restructuring, settlement, write-off, collections policy | **Nothing** |

The consistent rule: **the money path is implemented; the policy around it is not invented.**
Where the PRD names a rule, it is used verbatim. Where the PRD is silent, the feature is
either absent or, in the case of the interest rate, expressed as a user-stated value rather
than a computed one.

## Withdrawal, and the PAYOUT sink

The borrower's persona "withdraws approved funds to third-party accounts (Bank / Mobile
Financial Services)", so a withdrawal is money **leaving** the platform — the mirror of a
funding. It is posted as:

```
DEBIT   user's ledger account     300,000   (their money leaves their spendable balance)
CREDIT  PAYOUT system account      300,000   (the money has left the platform)
```

The `PAYOUT` account is credited only, and its `balance_after` is the cumulative amount paid
out — the exact mirror of `FUNDING`.

**The outcome is the user's intent, never a provider's.** The client sends `CONFIRM`, `FAIL`
or `CANCEL`; the server maps the intent to a terminal state. There is no real provider, so
there is no provider confirmation to represent. The page labels the environment as a sandbox
in the page chrome, **outside** the payment interface, and the screen states plainly that no
PIN, OTP or real credential is ever collected — only a mobile number or the last four digits
of a bank account.

The marketplace, the funding screen and the loan detail page carry **no** sandbox label,
because none of them involves an external provider: they move real internal money between
real internal wallets.

## Idempotency, again

The `Idempotency-Key` header is honoured on funding, exactly as it is on Add Money. A replayed
funding request with the same key returns the original investment and posts **no** new
entries. The unique index is the real guarantee; the read is only the fast path.

## The schema

Four new tables, all guarded models with attributes assigned explicitly, all amounts in
integer Poisha, all `DATETIME(6)`, and **no referential action** on any foreign key:

- **`loan_requests`** — the listing. `reference`, `borrower_user_id`, `principal_minor`,
  `interest_rate_bps`, `tenure_months`, `purpose`, `status`, `funded_minor`,
  `investor_count`, `funded_at`, `repaid_at`. A CHECK constraint bounds the principal to the
  PRD's BDT 5,000–50,000 range at the database level.
- **`loan_investments`** — one lender's commitment. `reference`, `loan_request_id`,
  `lender_user_id`, `amount_minor`, `idempotency_key` (unique), `transaction_id`. A unique
  index on `(lender_user_id, loan_request_id)` prevents duplicate funding.
- **`loan_installments`** — the schedule. `loan_request_id`, `sequence`, `due_date`,
  `amount_minor`, `principal_minor`, `interest_minor`, `penalty_minor`, `status`,
  `grace_started_at`, `paid_at`.
- **`wallet_withdrawals`** — one payout attempt. `reference`, `owner_user_id`, `method`,
  `status`, `amount_minor`, `details`, `transaction_id`, `idempotency_key`.

`ledger_accounts.system_role` gains `PAYOUT` by **appending** to the MySQL ENUM, which does
not renumber the existing members, so `FUNDING`, `TREASURY` and `ESCROW` keep their values
and every existing row is untouched. The `down()` narrows the ENUM and lets MySQL refuse if
any `PAYOUT` rows exist — a safe, self-protecting rollback.

## The interfaces

Four new Inertia pages, all reachable through the existing Inertia routing — **no REST API
is added**, per the instruction:

- `loans/marketplace.tsx` — the listings, plus a collapsible request form.
- `loans/show.tsx` — one listing: the funding meter, the "Fund this loan" card, the lenders
  table and the repayment schedule.
- `loans/my-loans.tsx` — the borrower's loans.
- `loans/my-investments.tsx` — the lender's commitments.
- `wallet/withdraw.tsx`, `wallet/withdrawal.tsx`, `wallet/withdrawal-history.tsx` — the
  withdrawal screens.

The sidebar, the dashboard, the wallet page and the Receive Money page are repointed. The
"Send Money" page, its route and its controller method are **deleted** — leaving a disabled
payment dropdown in place would misrepresent what the platform can do.

## The `Send Money` removal

This deserves its own note, because deletion is a stronger act than disabling.

The `Send Money` page was a real page with a real form: a method dropdown listing bKash,
Nagad, Bank Transfer and Debit / Credit Card, and a disabled button. It could never complete,
and it implied a capability the platform does not have — a one-to-one transfer. On a
micro-lending platform, that implication is actively misleading: it suggests a member could
send money to a peer, when the platform's actual model is that money moves by **funding a
loan**, which is a different thing with different rules.

So the page, the route and `DemoController::send()` are removed rather than disabled, and
the "Fund Loan" action takes its place. The `receive` and `notifications` screens remain
read-only and continue to state their own availability, and they are now accurate about how
money actually reaches a member: a disbursed principal, a repayment share, or an Add Money
top-up.
