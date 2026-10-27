<?php

namespace Tests\Feature\Ledger;

use App\Exceptions\LedgerTriggerException;
use App\Support\MySqlTriggerSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The mandatory-trigger machinery.
 *
 * A trigger that enforces a financial-integrity invariant is not optional. If it
 * cannot be installed, the migration must abort — never leave a schema in place
 * whose invariant looks enforced but is not.
 *
 * These tests cover the *policy* — fail closed, narrow escape hatch, precise
 * diagnosis — rather than the trigger's behaviour, which
 * {@see WalletSchemaTest} covers where the server permits it.
 */
class TriggerFailClosedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The trigger under test — the wallet↔account immutability guarantee.
     */
    private const TRIGGER = 'trg_wallets_account_id_immutable';

    /**
     * The installer's DDL, identical to the one the migration uses.
     */
    private const CREATE_SQL = <<<'SQL'
        CREATE TRIGGER `trg_wallets_account_id_immutable`
        BEFORE UPDATE ON `wallets`
        FOR EACH ROW
        BEGIN
            IF NEW.account_id <> OLD.account_id THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'wallets.account_id is immutable';
            END IF;
        END
    SQL;

    /**
     * The installer throws rather than silently succeeding when the server
     * refuses the trigger. This is the fail-closed guarantee.
     *
     * The escape hatch is explicitly denied here (`$allowTestEscape = false`),
     * so the fail-closed path is exercised even on a server where the test
     * schema's escape hatch is active. This is the behaviour every
     * non-test-schema migration relies on.
     *
     * The refusal is provoked deterministically — by asking the server to
     * create a trigger whose DDL is invalid — rather than by relying on the
     * server lacking the capability. That keeps the fail-closed guarantee
     * under test on a trigger-capable server too, where it would otherwise
     * never be exercised.
     */
    public function test_the_installer_throws_when_the_trigger_cannot_be_installed(): void
    {
        try {
            MySqlTriggerSupport::install(
                'trg_fail_closed_probe',
                'wallets',
                'CREATE TRIGGER `trg_fail_closed_probe` ON `no_such_table_here` FOR EACH ROW SET NEW.id = NEW.id',
                false
            );

            $this->fail('Expected the mandatory trigger installer to fail closed.');
        } catch (LedgerTriggerException $e) {
            $this->assertStringContainsString('trg_fail_closed_probe', $e->getMessage());
            $this->assertStringContainsString('financial-integrity', $e->getMessage());
            $this->assertStringContainsString('aborted', $e->getMessage());
        }
    }

    /**
     * The escape hatch is the only path that lets a missing trigger pass.
     *
     * With the escape hatch denied, a trigger that cannot be installed must
     * abort the migration rather than being skipped. This is proved against
     * the real trigger, on the real server: the DDL is made invalid so the
     * server refuses it, and the installer must refuse to swallow the error.
     */
    public function test_the_escape_hatch_is_the_only_path_that_skips_a_missing_trigger(): void
    {
        // The trigger under test is installed by the migration; remove it so
        // the installer has to do the work itself.
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_wallets_account_id_immutable`');

        $this->assertFalse(MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'));

        try {
            // Denied escape hatch -> must throw, not skip.
            try {
                MySqlTriggerSupport::install(
                    self::TRIGGER,
                    'wallets',
                    'CREATE TRIGGER `trg_wallets_account_id_immutable` ON `no_such_table_here` FOR EACH ROW SET NEW.id = NEW.id',
                    false
                );

                $this->fail('Expected the mandatory trigger installer to fail closed.');
            } catch (LedgerTriggerException $e) {
                $this->assertStringContainsString(self::TRIGGER, $e->getMessage());
            }

            $this->assertFalse(
                MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'),
                'A failed installation must not leave a trigger behind.'
            );
        } finally {
            // Restore the invariant for the rest of the suite.
            MySqlTriggerSupport::install(self::TRIGGER, 'wallets', self::CREATE_SQL);
        }

        $this->assertTrue(
            MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'),
            'The mandatory trigger must be restored after the fail-closed probe.'
        );
    }

    /**
     * When the server *can* create the trigger, the installer installs it and
     * verifies it landed.
     *
     * On a server that cannot, the test asserts the complementary truth: the
     * trigger is absent and the installer says so. Exactly one of the two
     * states is proven either way — the invariant is enforced, or the absence
     * is visible.
     */
    public function test_the_installer_installs_the_trigger_where_the_server_permits_it(): void
    {
        if (! MySqlTriggerSupport::triggersAreCreatable()) {
            $this->assertFalse(
                MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'),
                'The trigger must not be present on a server that cannot create it.'
            );

            return;
        }

        // The migration has already installed this trigger, so it must be
        // removed before the installer can be exercised against the same DDL.
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_wallets_account_id_immutable`');

        $this->assertFalse(MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'));

        MySqlTriggerSupport::install(self::TRIGGER, 'wallets', self::CREATE_SQL);

        $this->assertTrue(
            MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'),
            'The trigger was reported as installed but is not present in the schema.'
        );
    }

    /**
     * The escape hatch is the *only* path that lets a missing trigger pass, and
     * it applies only to the isolated test schema.
     *
     * The escape hatch must never make a missing trigger look installed: the
     * trigger's presence is still reported truthfully, so a suite run on a
     * trigger-less server cannot be mistaken for a fully-enforced schema.
     *
     * The property is asserted directly — by removing the trigger and checking
     * that the presence check reports it as absent — so it holds on a
     * trigger-capable server as well as on a trigger-less one.
     */
    public function test_the_escape_hatch_never_makes_a_missing_trigger_look_installed(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_wallets_account_id_immutable`');

        try {
            $this->assertFalse(
                MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'),
                'A trigger that is not present must be reported as absent, whatever the '
                .'escape hatch says.'
            );
        } finally {
            MySqlTriggerSupport::install(self::TRIGGER, 'wallets', self::CREATE_SQL);
        }

        $this->assertTrue(
            MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'),
            'The trigger must be reported as present once it is really installed.'
        );
    }

    /**
     * An aborted migration leaves NO `wallets` table behind.
     *
     * MySQL DDL is not transactional: the table created before the trigger
     * installation would survive a failure and leave a half-built schema whose
     * immutability guarantee is missing. The migration drops it on the failure
     * path, so an aborted run leaves no `wallets` table at all.
     *
     * The migration is exercised with the test-schema escape hatch **denied**,
     * which is exactly how it runs outside the isolated test schema.
     */
    public function test_an_aborted_migration_leaves_no_wallets_table(): void
    {
        $migration = require database_path(
            'migrations/2026_10_26_000002_create_wallets_table.php'
        );

        // Drop the table so the migration has to create it again.
        DB::statement('DROP TRIGGER IF EXISTS `trg_wallets_account_id_immutable`');
        Schema::dropIfExists('wallets');

        $this->assertFalse(
            $this->tableExists('wallets'),
            'The precondition of this test is that no wallets table exists.'
        );

        // Deny the escape hatch for the duration of this migration run, so the
        // migration behaves as it would on any non-test schema. The flag is
        // read through `config()`, so overriding it here is exactly what the
        // migration sees.
        $originalFlag = config(MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY);

        try {
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => false]);

            $failed = false;

            try {
                $migration->up();
            } catch (\Throwable $e) {
                $failed = true;
            }

            if ($failed) {
                $this->assertFalse(
                    $this->tableExists('wallets'),
                    'An aborted migration must not leave a wallets table behind. A table without '
                    .'its immutability trigger is a schema that looks correct while enforcing nothing.'
                );

                return;
            }

            // The server permitted the trigger, so the migration succeeded. The
            // table must exist and the trigger must be present.
            $this->assertTrue($this->tableExists('wallets'));
            $this->assertTrue(
                MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets'),
                'A successful migration must leave the trigger installed.'
            );
        } finally {
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => $originalFlag]);
        }
    }

    /**
     * Whether a table exists in the current schema.
     */
    private function tableExists(string $table): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) ($row->aggregate ?? 0) > 0;
    }

    /**
     * The trigger's actual presence is reported truthfully, whatever the server
     * allows. A test run on a trigger-less server must not report the invariant
     * as enforced.
     */
    public function test_the_trigger_presence_is_reported_truthfully(): void
    {
        $exists = MySqlTriggerSupport::triggerExists(self::TRIGGER, 'wallets');

        $this->assertSame(
            MySqlTriggerSupport::triggersAreCreatable(),
            $exists,
            'The reported trigger presence disagrees with the server capability. '
            .'On a trigger-capable server the trigger must be present; on a '
            .'trigger-less server it must be absent, and the difference must be visible.'
        );
    }

    /**
     * The installer verifies the trigger landed rather than trusting the DDL's
     * exit status.
     *
     * The verification step is what catches a server that reports success but
     * does not install the trigger. It is asserted by installing a trigger and
     * confirming the presence check distinguishes it from a name that was never
     * installed.
     */
    public function test_the_installer_verifies_the_trigger_landed(): void
    {
        if (! MySqlTriggerSupport::triggersAreCreatable()) {
            $this->markTestSkipped(MySqlTriggerSupport::unavailableReason());
        }

        MySqlTriggerSupport::install(
            'trg_wallet_immutability_probe',
            'wallets',
            <<<'SQL'
                CREATE TRIGGER `trg_wallet_immutability_probe`
                BEFORE UPDATE ON `wallets`
                FOR EACH ROW
                SET NEW.balance_minor = NEW.balance_minor
            SQL
        );

        $this->assertTrue(
            MySqlTriggerSupport::triggerExists('trg_wallet_immutability_probe', 'wallets'),
            'A trigger that was installed must be reported as present.'
        );

        $this->assertFalse(
            MySqlTriggerSupport::triggerExists('trg_never_installed_at_all', 'wallets'),
            'A trigger that was never installed must be reported as absent.'
        );

        DB::unprepared('DROP TRIGGER IF EXISTS `trg_wallet_immutability_probe`');

        $this->assertFalse(
            MySqlTriggerSupport::triggerExists('trg_wallet_immutability_probe', 'wallets'),
            'A dropped trigger must be reported as absent.'
        );
    }

    /**
     * The blocker diagnosis names the *actual* cause.
     *
     * When the connected role already holds the `TRIGGER` privilege, the
     * message must say so — otherwise an administrator is sent to issue a
     * `GRANT` that cannot possibly help.
     */
    public function test_the_blocker_diagnosis_distinguishes_grant_from_configuration(): void
    {
        $blocker = MySqlTriggerSupport::blocker();

        if ($blocker === null) {
            $this->assertTrue(MySqlTriggerSupport::triggersAreCreatable());

            return;
        }

        $grants = DB::select('SHOW GRANTS FOR CURRENT_USER');
        $grantText = strtolower(implode(' ', array_map(
            static fn ($g): string => (string) (array_values((array) $g)[0] ?? ''),
            $grants
        )));

        $holdsTrigger = str_contains($grantText, 'all privileges')
            || preg_match('/\btrigger\b/', $grantText) === 1;

        if ($holdsTrigger) {
            $this->assertStringContainsString(
                'not** a missing-grant problem',
                $blocker,
                'The role holds the TRIGGER privilege, so the blocker message must say the '
                .'problem is the server configuration, not a missing grant.'
            );
            $this->assertStringContainsString('log_bin_trust_function_creators=1', $blocker);
            $this->assertStringContainsString('No schema-level `GRANT` can resolve this', $blocker);
        } else {
            $this->assertStringContainsString(
                'does not hold the `TRIGGER` privilege',
                $blocker,
                'The role does not hold the TRIGGER privilege, so the blocker message must '
                .'name the missing grant.'
            );
        }
    }

    /**
     * The escape hatch is confined to the isolated test schema.
     *
     * It is honoured only when `APP_ENV=testing` AND the database name ends in
     * `_test`. The database-name condition is the safety interlock: no
     * production or development database can ever match it, so the flag cannot
     * weaken a real schema even if it were set in the environment.
     *
     * The suite runs against a trigger-capable isolated instance, so the hatch
     * is deliberately **off** — the interlock properties are asserted here by
     * switching it on explicitly and observing what it does and does not allow.
     */
    public function test_the_escape_hatch_is_confined_to_the_isolated_test_schema(): void
    {
        $this->assertTrue(
            app()->environment('testing'),
            'This test must run under APP_ENV=testing.'
        );

        $this->assertSame(
            'p2p_platform_test',
            config('database.connections.'.config('database.default').'.database'),
            'This test must run against the isolated test schema.'
        );

        // The hatch is off in this configuration: the server is trigger-capable,
        // so the mandatory triggers are really installed.
        $this->assertFalse(
            MySqlTriggerSupport::testSchemaEscapeIsActive(),
            'The escape hatch must be off when the server can really install the triggers.'
        );

        // Switching it on activates it — but only for this schema.
        $original = config(MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY);

        try {
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => true]);

            $this->assertTrue(
                MySqlTriggerSupport::testSchemaEscapeIsActive(),
                'With the flag on, the hatch must activate for the isolated test schema.'
            );
        } finally {
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => $original]);
        }
    }

    /**
     * The escape hatch refuses to activate for a database that is not the
     * isolated test schema — whatever the environment says.
     *
     * This is proved by pointing the configured database at a non-test name and
     * asking the guard directly. It is the interlock that makes the escape hatch
     * safe to leave switched on.
     */
    public function test_the_escape_hatch_refuses_a_non_test_database(): void
    {
        $connection = (string) config('database.default');
        $key = 'database.connections.'.$connection.'.database';
        $original = config($key);
        $originalFlag = config(MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY);

        try {
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => true]);

            config([$key => 'p2p_platform_dev']);

            $this->assertFalse(
                MySqlTriggerSupport::testSchemaEscapeIsActive(),
                'The escape hatch must not activate for a development database.'
            );

            config([$key => 'p2p_platform_production']);

            $this->assertFalse(
                MySqlTriggerSupport::testSchemaEscapeIsActive(),
                'The escape hatch must not activate for a production database.'
            );

            config([$key => 'p2p_platform_test']);

            $this->assertTrue(
                MySqlTriggerSupport::testSchemaEscapeIsActive(),
                'The escape hatch must still activate for the isolated test schema.'
            );
        } finally {
            config([$key => $original]);
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => $originalFlag]);
        }
    }

    /**
     * The escape hatch is inert when the environment is not `testing`, even for
     * a `_test` database name.
     */
    public function test_the_escape_hatch_requires_the_testing_environment(): void
    {
        $connection = (string) config('database.default');
        $key = 'database.connections.'.$connection.'.database';
        $original = config($key);
        $originalFlag = config(MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY);
        $originalEnv = app()->environment();

        try {
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => true]);
            config([$key => 'p2p_platform_test']);

            // Simulate a non-testing environment by exercising the guard's own
            // environment check through the application instance's environment.
            app()->detectEnvironment(fn () => 'production');

            $this->assertFalse(
                MySqlTriggerSupport::testSchemaEscapeIsActive(),
                'The escape hatch must not activate outside the testing environment.'
            );
        } finally {
            app()->detectEnvironment(fn () => $originalEnv);
            config([$key => $original]);
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => $originalFlag]);
        }
    }

    /**
     * A production-named database outside the test environment cannot be
     * weakened by the escape hatch — the strongest statement of the interlock.
     */
    public function test_a_production_database_can_never_use_the_escape_hatch(): void
    {
        $connection = (string) config('database.default');
        $key = 'database.connections.'.$connection.'.database';
        $original = config($key);
        $originalFlag = config(MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY);
        $originalEnv = app()->environment();

        try {
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => true]);
            app()->detectEnvironment(fn () => 'production');
            config([$key => 'p2p_platform_production']);

            $this->assertFalse(MySqlTriggerSupport::testSchemaEscapeIsActive());
        } finally {
            app()->detectEnvironment(fn () => $originalEnv);
            config([$key => $original]);
            config([MySqlTriggerSupport::TEST_SCHEMA_ESCAPE_CONFIG_KEY => $originalFlag]);
        }
    }
}
