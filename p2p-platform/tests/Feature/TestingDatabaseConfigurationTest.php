<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestingDatabaseConfigurationTest extends TestCase
{
    public function test_the_testing_environment_resolves_to_the_dedicated_mysql_database(): void
    {
        $this->assertSame('testing', app()->environment());

        $this->assertSame('mysql_p2ptest', config('database.default'));

        $this->assertSame('p2p_platform_test', config('database.connections.mysql_p2ptest.database'));

        $this->assertSame('p2p_test', config('database.connections.mysql_p2ptest.username'));

        $this->assertEmpty(config('database.connections.mysql_p2ptest.url'));
    }

    /**
     * The isolated instance runs on its own port so the shared workstation
     * MySQL80 service on 3306 is never touched by a test run.
     */
    public function test_the_test_connection_targets_the_isolated_instance_on_port_3307(): void
    {
        $this->assertSame('3307', (string) config('database.connections.mysql_p2ptest.port'));

        $this->assertSame('mysql_p2ptest', config('database.default'));
    }

    /**
     * The trigger-bypass escape hatch must not be enabled any more: the
     * isolated instance is trigger-capable, so the ledger migrations install
     * the mandatory financial-integrity triggers for real.
     */
    public function test_the_trigger_bypass_escape_hatch_is_not_enabled(): void
    {
        $this->assertFalse((bool) config('ledger.allow_unenforced_triggers_in_test_schema'));
    }
}
