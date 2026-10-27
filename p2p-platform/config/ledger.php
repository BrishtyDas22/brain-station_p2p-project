<?php

/**
 * Ledger subsystem configuration.
 *
 * Only settings that the ledger itself reads live here. Keeping them out of
 * `.env` files is deliberate for the trigger escape hatch below: the flag is
 * read through `config()`, which is stable whether or not the configuration is
 * cached, and it is never consulted outside the guarded path in
 * `App\Support\MySqlTriggerSupport`.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Allow unenforced triggers in the isolated test schema
    |--------------------------------------------------------------------------
    |
    | A MySQL server with binary logging enabled refuses to create a trigger
    | unless the creating role holds SUPER, or the server sets
    | `log_bin_trust_function_creators=1`. The refusal is error 1419
    | (ER_BINLOG_UNSAFE_ROUTINE).
    |
    | A migration that always fails closed would make the test suite unable to
    | run at all on such a server — every test would error out during schema
    | setup and the suite would prove nothing. This flag permits the isolated
    | test schema alone to run without the mandatory financial-integrity
    | triggers.
    |
    | It is honoured ONLY when BOTH of the following hold:
    |
    |   1. `APP_ENV` is exactly `testing`, and
    |   2. the connected database name ends in `_test`.
    |
    | The database-name condition is the safety interlock. No production or
    | development database can ever satisfy it, so this flag cannot weaken a
    | real schema even if it is set in the environment.
    |
    | Outside the isolated test schema the migration FAILS CLOSED: it aborts
    | rather than leaving a schema whose financial-integrity invariant is not
    | enforced by the database.
    |
    | Set this to false once the test server is trigger-capable. The desired end
    | state is a server where `log_bin_trust_function_creators=1`, at which point
    | the escape hatch is never needed.
    |
    */

    'allow_unenforced_triggers_in_test_schema' => env(
        'LEDGER_ALLOW_UNENFORCED_TRIGGERS_IN_TEST_SCHEMA',
        false
    ),

];
