<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| P2P authentication policy
|--------------------------------------------------------------------------
|
| Two decisions live here, both of them reversible without a code change:
|
| 1. `require_email_verification` — whether a signed-in user must have
|    confirmed their email address before reaching the platform.
|
|    It is FALSE by default so the LOCAL DEVELOPMENT / DEMO flow is:
|    register -> login -> dashboard, with no verification detour.
|
|    Set P2P_REQUIRE_EMAIL_VERIFICATION=true in the environment to turn the
|    requirement back on. Nothing else needs to change: the Fortify
|    verification routes, the `verified` middleware and the
|    `MustVerifyEmail` contract are all still registered, so the production
|    path is complete and waiting — it is simply not enforced while the
|    switch is off.
|
| 2. `login_after_registration` — whether a successful registration signs
|    the new user straight in.
|
|    It is FALSE in the local demo flow: registration ends at the login page
|    with a success message. The registration session is invalidated before
|    the redirect so a half-open session can never carry into the login
|    step.
|
| Both switches are read through the config cache, so they are safe to flip
| per environment. They are deliberately NOT read from `.env` directly in
| application code: config files are the single place these decisions are
| made, which keeps them auditable and testable.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    |
    | When true, the `verified` middleware on the platform routes sends an
    | unverified user to the Fortify verification notice, and a newly
    | registered user cannot reach the platform until they follow the signed
    | link that is emailed to them.
    |
    | When false, the same routes are reachable by any signed-in user and no
    | verification detour is presented.
    |
    */

    'require_email_verification' => (bool) env('P2P_REQUIRE_EMAIL_VERIFICATION', false),

    /*
    |--------------------------------------------------------------------------
    | Login after registration
    |--------------------------------------------------------------------------
    |
    | When true, Fortify's registration controller signs the new user in and
    | redirects to the home path.
    |
    | When false, the new user is signed OUT again, the registration session
    | is invalidated, and the browser is sent to the login page with a
    | success message.
    |
    */

    'login_after_registration' => (bool) env('P2P_LOGIN_AFTER_REGISTRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Registration success message
    |--------------------------------------------------------------------------
    */

    'registration_success_message' => 'Account created successfully. Please log in.',

];
