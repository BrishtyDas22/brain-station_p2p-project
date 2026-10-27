<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sandbox funding policy
|--------------------------------------------------------------------------
|
| This configuration describes the LOCAL SANDBOX funding workflow — the "Add Money"
| journey, in which a simulated payment gateway credits the user's real, ledger-backed
| main wallet.
|
| ## This is NOT production funding
|
| The funding source is a `SYSTEM` ledger account with the `FUNDING` role. **H-1 (SYSTEM
| accounts and the funding source) is 🟠 Proposed and not approved.** H-1 has NOT been
| approved here, and this configuration does not approve it.
|
| What exists is a **local sandbox funding policy**: a development-and-demonstration
| mechanism by which a simulated gateway tops up a local wallet. It is:
|
|   - reachable ONLY through the sandboxed "Add Money" routes;
|   - labelled as a sandbox in the interface, once, in the page chrome;
|   - never reachable from a production money-movement path (transfers, settlements,
|     withdrawals, reversals) — a tripwire test enforces this;
|   - backed by a properly balanced pair of `ledger_entries`, so the accounting is real
|     even though the external gateway is not.
|
| No real money is ever transferred. No real bank, wallet operator or card network is
| ever contacted. The amounts are not regulatory limits.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The single currency the sandbox funds in. The database enforces it with a CHECK
    | constraint on `wallet_fundings` and on `ledger_entries`.
    |
    */

    'currency' => env('P2P_FUNDING_CURRENCY', 'BDT'),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | The maximum amount a single funding attempt may request, and the maximum total
    | that may be funded in one day.
    |
    | These are SANDBOX LIMITS. They exist so a test cannot ask for an absurd number and
    | overflow a column. They are **not** regulatory ceilings and **not** a business
    | limit: decision **C-3 (⚪ Open)** has not been decided, and no Bangladesh
    | compliance rule is invented here. When C-3 is decided, these two values are
    | re-pointed at the decided values.
    |
    | The values are integer Poisha: 5,000,000 Poisha = ৳50,000.
    |
    */

    'max_amount_minor' => (int) env('P2P_FUNDING_MAX_AMOUNT_MINOR', 5_000_000),

    'daily_max_amount_minor' => (int) env('P2P_FUNDING_DAILY_MAX_AMOUNT_MINOR', 10_000_000),

    /*
    |--------------------------------------------------------------------------
    | The fictional test card
    |--------------------------------------------------------------------------
    |
    | The ONLY card the card screen accepts. It is a well-known test number, not a
    | real card. Real card details are refused by `FundingDetails`.
    |
    */

    'test_card' => [
        'number' => '4242 4242 4242 4242',
        'digits' => '4242424242424242',
        'expiry' => '12/30',
        'cvv' => '123',
        'holder' => 'TEST CARDHOLDER',
    ],

    /*
    |--------------------------------------------------------------------------
    | The sandbox indicator
    |--------------------------------------------------------------------------
    |
    | One short, accurate line shown in the page chrome — outside the payment
    | interface itself. It says the environment is a sandbox and that no real provider
    | processed the payment. It is deliberately NOT repeated on every card, receipt and
    | row, because a payment application that repeats a warning on every surface reads
    | as a prototype rather than a product.
    |
    */

    'environment_label' => 'Sandbox environment — the payment gateway is simulated and no real money is transferred.',

    /*
    |--------------------------------------------------------------------------
    | Reference prefix
    |--------------------------------------------------------------------------
    |
    | The prefix of every funding reference. A reference quoted in a ticket still says
    | what it is.
    |
    */

    'reference_prefix' => 'ADD',

];
