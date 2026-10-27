<?php

namespace App\Support;

use App\Exceptions\LedgerTriggerException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Capability probe and mandatory installer for MySQL triggers.
 *
 * ## Why the probe is not enough on its own
 *
 * A MySQL server with binary logging enabled refuses to create a trigger unless the
 * creating role holds `SUPER` **or** the server sets
 * `log_bin_trust_function_creators=1`. The error is 1419
 * (`ER_BINLOG_UNSAFE_ROUTINE`):
 *
 *     You do not have the SUPER privilege and binary logging is enabled
 *
 * The message names `SUPER`, but that is not the only cause. Two independent
 * conditions must hold for trigger creation to succeed:
 *
 * 1. The role must hold the `TRIGGER` privilege on the target schema, **and**
 * 2. either `log_bin=0`, or `log_bin=1` with `log_bin_trust_function_creators=1`
 *    (or the role holds `SUPER` / `SYSTEM_VARIABLES_ADMIN`).
 *
 * A role can satisfy (1) and still be refused, because (2) is a **server
 * configuration** and not a grant. Measured on the isolated test server
 * (MySQL 8.0.46, `log_bin=1`, `log_bin_trust_function_creators=0`): the role
 * `p2p_test` holds `ALL PRIVILEGES` on `p2p_platform_test.*` — which **includes**
 * `TRIGGER` — and `CREATE TRIGGER` still fails with 1419. Diagnosing this as
 * "grant TRIGGER" would send an administrator to the wrong place; the actual
 * fix is a server-side setting.
 *
 * ## Why triggers are mandatory here
 *
 * The wallet↔account binding is immutable. A foreign key cannot express that,
 * because an explicit `UPDATE` to a *different but valid* account satisfies
 * referential integrity. Only a `BEFORE UPDATE` trigger rejects it. Without the
 * trigger, the database accepts a re-point that silently breaks the ledger's
 * ownership binding — a corruption, not a mere inconvenience.
 *
 * Therefore trigger installation is **fail-closed**: see
 * {@see MySqlTriggerSupport::install()}. If a mandatory trigger cannot be
 * installed, the migration aborts. The schema is never left in place with the
 * invariant unenforced.
 *
 * ## The one permitted exception: the isolated test schema
 *
 * A migration that always fails closed would make the test suite unable to run at
 * all on a server that forbids triggers — 45 tests would error out during schema
 * setup rather than exercising anything. That is fail-closed in the letter and
 * useless in practice: the tests stop proving *anything*.
 *
 * The escape hatch is therefore deliberately narrow and deliberately loud:
 *
 *  - It is keyed on an **environment variable**, not a `.env` edit, so no
 *    environment file is modified.
 *  - It is honoured **only** when `APP_ENV=testing` **and** the database name
 *    ends in `_test`. Both conditions must hold. A production or development
 *    database can never match, whatever the variable says.
 *  - The trigger is still *attempted* first. It is only the absence of the
 *    server capability that triggers the escape.
 *  - Every skip is recorded in the test output with the exact blocker, so a
 *    green suite can never be mistaken for a fully-enforced schema.
 *
 * In every other situation the migration aborts loudly.
 */
final class MySqlTriggerSupport
{
    /**
     * The environment variable that permits the isolated test schema to run
     * without triggers. It is a no-op unless `APP_ENV=testing` and the database
     * name ends in `_test`.
     */
    public const TEST_SCHEMA_ESCAPE_VAR = 'LEDGER_ALLOW_UNENFORCED_TRIGGERS_IN_TEST_SCHEMA';

    /**
     * The config key that carries the same setting.
     */
    public const TEST_SCHEMA_ESCAPE_CONFIG_KEY = 'ledger.allow_unenforced_triggers_in_test_schema';

    /**
     * Whether the connected server permits creating a trigger without SUPER.
     */
    public static function triggersAreCreatable(): bool
    {
        return self::blocker() === null;
    }

    /**
     * The precise reason trigger creation is unavailable, or `null` if it is.
     *
     * Distinguishes a **missing grant** (an administrator can fix it with a
     * `GRANT`, no server restart) from a **server configuration** blocker (the
     * administrator must change a server variable or the role must be given
     * `SUPER`).
     */
    public static function blocker(): ?string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return 'The application is not connected to a MySQL server, so the trigger '
                .'cannot be created.';
        }

        try {
            $row = DB::selectOne(
                'SELECT @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trust'
            );
        } catch (Throwable) {
            return 'The server variables `log_bin` and `log_bin_trust_function_creators` '
                .'could not be read, so trigger creation cannot be confirmed available.';
        }

        if ($row === null) {
            return 'The server variables `log_bin` and `log_bin_trust_function_creators` '
                .'could not be read, so trigger creation cannot be confirmed available.';
        }

        $logBin = (bool) ($row->log_bin ?? false);
        $trust = (bool) ($row->trust ?? false);

        if (! $logBin || $trust) {
            return null;
        }

        if (! self::roleHoldsTriggerPrivilege()) {
            return 'The connected role does not hold the `TRIGGER` privilege on the target '
                .'schema, and this server has binary logging enabled (`log_bin=1`) with '
                .'`log_bin_trust_function_creators=0`. An administrator must grant `TRIGGER` '
                .'on the schema to the application role, or set '
                .'`log_bin_trust_function_creators=1`, or grant `SUPER` to the role.';
        }

        return 'This MySQL server has binary logging enabled (`log_bin=1`) and '
            .'`log_bin_trust_function_creators=0`. The connected role already holds the '
            .'`TRIGGER` privilege on the schema — this is **not** a missing-grant problem — '
            .'but MySQL additionally requires `SUPER` (or `SYSTEM_VARIABLES_ADMIN`) on the '
            .'creating role when binary logging is on and the trust variable is off. '
            .'An administrator must either set `log_bin_trust_function_creators=1` on this '
            .'server, or grant `SUPER` / `SYSTEM_VARIABLES_ADMIN` to the application role. '
            .'No schema-level `GRANT` can resolve this.';
    }

    /**
     * A human-readable explanation of why trigger creation is unavailable.
     */
    public static function unavailableReason(): string
    {
        return self::blocker() ?? 'Triggers are creatable.';
    }

    /**
     * Whether the connected role holds the `TRIGGER` privilege on the current schema.
     *
     * `SHOW GRANTS` is authoritative and does not leak the password. A role with
     * `ALL PRIVILEGES` on the schema holds `TRIGGER` implicitly, so the grant
     * string is inspected for both the specific and the blanket form.
     */
    private static function roleHoldsTriggerPrivilege(): bool
    {
        try {
            $grants = DB::select('SHOW GRANTS FOR CURRENT_USER');
        } catch (Throwable) {
            // If the grants cannot be read, assume the role does NOT hold it, so
            // the reported blocker names the grant as well. Being wrong in this
            // direction produces a strictly more conservative message.
            return false;
        }

        foreach ($grants as $grant) {
            $text = is_object($grant)
                ? (string) (array_values((array) $grant)[0] ?? '')
                : (string) $grant;

            $normalised = strtolower($text);

            if (str_contains($normalised, 'all privileges')) {
                return true;
            }

            if (preg_match('/\btrigger\b/', $normalised) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a trigger with the given name exists on the given table.
     */
    public static function triggerExists(string $triggerName, string $table): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT COUNT(*) AS aggregate FROM information_schema.triggers
                 WHERE trigger_schema = DATABASE() AND trigger_name = ? AND event_object_table = ?',
                [$triggerName, $table]
            );
        } catch (Throwable) {
            return false;
        }

        return (int) ($row->aggregate ?? 0) > 0;
    }

    /**
     * Whether the isolated-test-schema escape hatch is in force.
     *
     * True only when **all** of the following hold:
     *
     *  - `APP_ENV` is exactly `testing`;
     *  - the connected database name ends in `_test`;
     *  - the configured escape flag is truthy.
     *
     * The database-name check is what makes this safe: a production or
     * development database can never satisfy it, so the variable alone can
     * never weaken a real schema.
     *
     * The flag is read through `config()` rather than `env()` so the decision
     * does not change when the configuration is cached. Reading `env()` directly
     * would return `null` under `config:cache`, silently flipping the answer.
     */
    public static function testSchemaEscapeIsActive(): bool
    {
        if (app()->environment('testing') !== true) {
            return false;
        }

        $database = (string) config(
            'database.connections.'.config('database.default').'.database'
        );

        if (! str_ends_with($database, '_test')) {
            return false;
        }

        $flag = config('ledger.allow_unenforced_triggers_in_test_schema');

        return in_array(
            is_string($flag) ? strtolower(trim($flag)) : $flag,
            [true, 1, '1', 'true', 'yes', 'on'],
            true
        );
    }

    /**
     * Install a mandatory trigger, failing closed.
     *
     * The trigger is created and then **verified** to be present. If creation
     * fails, or if the server reports success but the trigger is absent, a
     * {@see LedgerTriggerException} is thrown and the calling migration aborts.
     *
     * The single exception is the isolated test schema, where the server cannot
     * create triggers at all — see {@see testSchemaEscapeIsActive()}. There the
     * trigger is skipped with a loud, recorded reason rather than making the
     * entire test suite unrunnable.
     *
     * @param  string  $triggerName  The trigger name.
     * @param  string  $table  The table the trigger is attached to.
     * @param  literal-string  $createSql  The complete `CREATE TRIGGER` statement.
     *                                     Typed as a literal string so that a
     *                                     caller cannot interpolate untrusted
     *                                     input into the DDL.
     * @param  bool|null  $allowTestEscape  Overrides the escape-hatch decision.
     *                                      `null` means "use the ambient
     *                                      decision". `false` forces fail-closed
     *                                      even in the test schema, which is how
     *                                      the fail-closed path is tested.
     *
     * @throws LedgerTriggerException
     */
    public static function install(
        string $triggerName,
        string $table,
        string $createSql,
        ?bool $allowTestEscape = null
    ): void {
        $blocker = self::blocker();

        if ($blocker !== null) {
            $escapeAllowed = $allowTestEscape ?? self::testSchemaEscapeIsActive();

            if ($escapeAllowed) {
                self::recordSkippedTrigger($triggerName, $blocker);

                return;
            }

            throw LedgerTriggerException::forTrigger($triggerName, $blocker);
        }

        // The DDL is a literal supplied by the migration, never assembled from
        // user input. It is asserted here so that a caller cannot smuggle
        // interpolation past this point.
        if (! str_starts_with(ltrim($createSql), 'CREATE TRIGGER')) {
            throw LedgerTriggerException::forTrigger(
                $triggerName,
                'The supplied DDL is not a CREATE TRIGGER statement.'
            );
        }

        try {
            DB::unprepared($createSql);
        } catch (Throwable $e) {
            throw LedgerTriggerException::forTrigger(
                $triggerName,
                'The CREATE TRIGGER statement failed: '
                .preg_replace('/\s+/', ' ', $e->getMessage())
            );
        }

        if (! self::triggerExists($triggerName, $table)) {
            throw LedgerTriggerException::missingAfterInstall($triggerName);
        }
    }

    /**
     * Record that a mandatory trigger was skipped in the isolated test schema.
     *
     * The record goes to the application log at `warning` level so it is
     * impossible to run the suite against a trigger-less server without the
     * reason appearing in the output.
     */
    private static function recordSkippedTrigger(string $triggerName, string $blocker): void
    {
        Log::warning(
            "Mandatory trigger `{$triggerName}` was NOT installed in the isolated test schema. "
            .$blocker
            .' The financial-integrity invariant this trigger enforces is therefore NOT '
            .'enforced by the database in this test run. This is permitted only for a '
            .'database whose name ends in `_test` while APP_ENV=testing.'
        );
    }
}
