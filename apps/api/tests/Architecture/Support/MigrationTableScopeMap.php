<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * Which migration tree declares a table — `database/migrations/` (CENTRAL, the
 * tenant directory + auth in `synerivia_central`) or `database/migrations/tenant/`
 * (TENANT, one copy per `tenant_<uuid>` database).
 *
 * The enum↔CHECK parity gate asserts on TENANT tables only: the two trees run
 * through different migration paths (Stancl's `migration_parameters` for tenant,
 * the default `migrate` for central), so a central table is a different lane's
 * problem and is REPORTED, never asserted (lane D-1 brief, "Out of scope").
 *
 * Derivation is mechanical, from the migration files themselves — never a hand
 * list. Three declaration idioms are recognised because all three are live in
 * this tree:
 *   Schema::create('t', …)                        — the common form
 *   Schema::connection($c)->create('t', …)        — the central admin-template migrations
 *   Schema::rename('old', 'new')                  — location_zones → location_nodes
 */
final class MigrationTableScopeMap
{
    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_CENTRAL = 'central';

    public const SCOPE_UNKNOWN = 'unknown';

    /** @var array<string, string>|null */
    private ?array $map = null;

    public function __construct(private readonly string $migrationsPath) {}

    public function scopeOf(string $table): string
    {
        return $this->map()[$table] ?? self::SCOPE_UNKNOWN;
    }

    /**
     * @return array<string, string> table => scope
     */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];
        foreach ($this->tablesDeclaredIn($this->migrationsPath.'/*.php') as $table) {
            $map[$table] = self::SCOPE_CENTRAL;
        }
        foreach ($this->tablesDeclaredIn($this->migrationsPath.'/tenant/*.php') as $table) {
            // A table declared in BOTH trees would be a genuine topology defect;
            // the tenant tree wins here and the collision is surfaced by
            // collidingTables() so a test can assert the set is empty.
            $map[$table] = self::SCOPE_TENANT;
        }

        return $this->map = $map;
    }

    /**
     * @return list<string>
     */
    public function collidingTables(): array
    {
        $central = $this->tablesDeclaredIn($this->migrationsPath.'/*.php');
        $tenant = $this->tablesDeclaredIn($this->migrationsPath.'/tenant/*.php');

        $collisions = array_values(array_intersect($central, $tenant));
        sort($collisions, SORT_STRING);

        return $collisions;
    }

    /**
     * @return list<string>
     */
    private function tablesDeclaredIn(string $glob): array
    {
        $tables = [];
        foreach (glob($glob) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all('/->create\(\s*\'([a-z0-9_]+)\'/', $source, $m) > 0) {
                foreach ($m[1] as $table) {
                    $tables[$table] = true;
                }
            }
            if (preg_match_all('/Schema::create\(\s*\'([a-z0-9_]+)\'/', $source, $m) > 0) {
                foreach ($m[1] as $table) {
                    $tables[$table] = true;
                }
            }
            if (preg_match_all('/rename\(\s*\'[a-z0-9_]+\'\s*,\s*\'([a-z0-9_]+)\'/', $source, $m) > 0) {
                foreach ($m[1] as $table) {
                    $tables[$table] = true;
                }
            }
        }

        $list = array_keys($tables);
        sort($list, SORT_STRING);

        return $list;
    }
}
