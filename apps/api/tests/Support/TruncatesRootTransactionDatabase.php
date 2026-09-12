<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PDO;

/** Migrate once per class, then clear rows without wrapping receipt root transactions. */
trait TruncatesRootTransactionDatabase
{
    use DatabaseTruncation {
        truncateTablesForAllConnections as private truncateWithFramework;
    }

    private static bool $rootDatabaseInitialized = false;

    private static ?PDO $rootDatabasePdo = null;

    protected function beforeTruncatingDatabase(): void
    {
        if (! self::$rootDatabaseInitialized) {
            RefreshDatabaseState::$migrated = false;
            self::$rootDatabaseInitialized = true;
        }

        $connection = $this->app->make('db')->connection();
        if ($connection->getDatabaseName() === ':memory:' && self::$rootDatabasePdo !== null) {
            $connection->setPdo(self::$rootDatabasePdo);
        }
        $this->beforeApplicationDestroyed(function () use ($connection): void {
            if ($connection->getDatabaseName() === ':memory:') {
                self::$rootDatabasePdo = $connection->getPdo();
            }
        });
    }

    protected function truncateTablesForAllConnections(): void
    {
        $connection = $this->app->make('db')->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            $this->truncateWithFramework();

            return;
        }

        // Cleanup only: append-only ledger triggers must remain active during every test.
        if (! $this->app->environment('testing') || ! str_contains($connection->getDatabaseName(), '_test')) {
            throw new \LogicException('Root fixture truncation requires a testing database.');
        }
        $tables = array_filter($connection->getSchemaBuilder()->getTableListing(), static fn (string $table): bool => $table !== 'migrations' && $table !== 'public.migrations');
        $quoted = array_map($connection->getQueryGrammar()->wrapTable(...), $tables);
        $role = $connection->selectOne('SHOW session_replication_role')->session_replication_role;
        $connection->statement("SET session_replication_role = 'replica'");
        try {
            // One server-side cleanup avoids PostgreSQL TRUNCATE rewriting every empty relation.
            $deletes = array_map(static fn (string $table): string => 'DELETE FROM '.$table.';', $quoted);
            $connection->unprepared('DO $cleanup$ BEGIN '.implode(' ', $deletes).' END $cleanup$;');
        } finally {
            $connection->select("SELECT set_config('session_replication_role', ?, false)", [$role]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$rootDatabaseInitialized = false;
        self::$rootDatabasePdo = null;
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }
}
