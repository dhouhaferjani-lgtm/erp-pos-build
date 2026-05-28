<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T6 Phase 0b — the `central` connection (topology contract Pattern A).
 *
 * Tenant tables can never FK across the database boundary; they store a plain
 * UUID and resolve central rows through `DB::connection('central')`. That named
 * connection must exist and be a real PostgreSQL connection so Pattern A code
 * (and Sanctum's CentralPersonalAccessToken) can read the central database while
 * the default connection is swapped to a tenant database mid-request.
 *
 * PG-only: the connection is a pgsql connection; the default SQLite suite cannot
 * open it.
 */
class CentralConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The central connection is PostgreSQL-only.');
        }
    }

    public function test_central_connection_is_defined_as_a_pgsql_connection(): void
    {
        $config = config('database.connections.central');

        $this->assertIsArray($config, "The 'central' connection must be defined in config/database.php.");
        $this->assertSame('pgsql', $config['driver']);
    }

    public function test_central_connection_database_resolves_from_env(): void
    {
        $expected = env('DB_CENTRAL_DATABASE', env('DB_DATABASE'));

        $this->assertSame($expected, config('database.connections.central.database'));
    }

    public function test_central_connection_is_queryable(): void
    {
        $row = DB::connection('central')->selectOne('select 1 as ok');

        $this->assertSame(1, (int) $row->ok);
    }

    public function test_stancl_central_connection_name_is_aligned_with_a_real_connection(): void
    {
        // Stancl reads/writes central models (Tenant, Domain) through the
        // connection named by tenancy.database.central_connection. Whatever it
        // resolves to must be a connection that actually exists, so central
        // models never hit an undefined connection after the flip.
        $centralConnectionName = config('tenancy.database.central_connection');

        $this->assertIsArray(
            config("database.connections.{$centralConnectionName}"),
            "tenancy.database.central_connection ('{$centralConnectionName}') must name a defined connection.",
        );
    }
}
