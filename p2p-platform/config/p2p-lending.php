<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Micro-lending configuration
|--------------------------------------------------------------------------
|
| The rules the PRD states, and only those.
|
| **Loan range.** The PRD's header states "Loan Range: BDT 5,000 - 50,000 (short-term
| micro-credit)". Those two numbers are the PRD's own, not a regulatory figure invented
| here. They are enforced at three levels: a database CHECK constraint on
| `loan_requests.principal_minor`, a validation rule on the request form, and a
| service-level guard.
|
| **Interest rate.** The PRD requires the rate to be *displayed* to lenders and the
| repayment schedule to be calculated, but it does not define a rate schedule, a
| risk-based pricing table, or a default rate. None is invented here. The rate is a
| per-request field the borrower states, stored exactly as an integer number of basis
| points so no float ever touches a financial rate.
|
| **Tenure.** The PRD's borrower persona repays "monthly or weekly installments
| (Equated Monthly Installments - EMI)" and §4.1 describes a daily batch on due dates,
| so the schedule unit is the MONTH. The permitted range is a platform limit, and it is
| marked as such below — it is not a regulatory ceiling.
|
| **Grace period and penalty.** The PRD states these explicitly: "the loan enters a
| 3-day status of 'GRACE_PERIOD'" and "initiates a daily late penalty surcharge of 0.5%
| after grace period expiration". Both are used verbatim.
|
| **C-3 (the business limit) is ⚪ Open** in the decision register. Nothing here is a
| regulatory ceiling, and no Bangladesh compliance rule is invented. These are platform
| limits that mirror the PRD.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Loan principal
    |--------------------------------------------------------------------------
    |
    | The PRD's stated range, in integer Poisha. 1 BDT = 100 Poisha, so BDT 5,000 is
    | 500000 Poisha and BDT 50,000 is 5000000 Poisha.
    |
    */

    'min_principal_minor' => 500_000,

    'max_principal_minor' => 5_000_000,

    /*
    |--------------------------------------------------------------------------
    | Tenure
    |--------------------------------------------------------------------------
    |
    | A platform limit, in months. The PRD does not state a tenure range, so this is
    | deliberately modest and clearly labelled as a platform limit rather than a rule.
    |
    */

    'min_tenure_months' => 1,

    'max_tenure_months' => 12,

    /*
    |--------------------------------------------------------------------------
    | Interest rate
    |--------------------------------------------------------------------------
    |
    | The PRD does not define a rate. These are only the SANDBOX bounds of what a
    | borrower may state on a request — the ceiling keeps an accidental 50000% rate
    | off a listing. The rate itself is whatever the borrower declares.
    |
    | Stored as basis points: 1200 bps = 12.00%.
    |
    */

    'max_interest_rate_bps' => 10_000,

    /*
    |--------------------------------------------------------------------------
    | Grace period and late penalty
    |--------------------------------------------------------------------------
    |
    | Both are the PRD's own words (Epic 4, User Story 4.1):
    |
    |   "the loan enters a 3-day status of 'GRACE_PERIOD'"
    |   "initiates a daily late penalty surcharge of 0.5% after grace period expiration"
    |
    | `late_penalty_bps` is the DAILY surcharge, in basis points: 50 bps = 0.50% per day.
    |
    */

    'grace_period_days' => 3,

    'late_penalty_bps' => 50,

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    |
    | A single payout ceiling, mirroring the PRD's loan range because a withdrawal is
    | the borrower taking their own money back out. It is a platform limit.
    |
    */

    'max_withdrawal_minor' => 5_000_000,

    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    */

    'reference_prefix' => 'LOAN',

    'investment_reference_prefix' => 'INV',

    'withdrawal_reference_prefix' => 'WDR',

    /*
    |--------------------------------------------------------------------------
    | The sandbox indicator
    |--------------------------------------------------------------------------
    |
    | Shown once, outside the payment interface, on the screens where an external
    | payout is simulated. It is deliberately NOT shown on the marketplace, on the
    | funding screen, or on the loan detail page — those move real internal money
    | between real internal wallets, and there is no external provider involved.
    |
    */

    'environment_label' => 'Sandbox environment — the payout gateway is simulated and no real money leaves the platform.',

];
