<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * Which migration tree declares a table — `database/migrations/` (CENTRAL, the
 * tenant directory + auth in `synerivia_central`) or `database/migrations/tenant/`
 * (TENANT, one copy per `tenant_<uuid>` database).
 *
 * The enum↔CHECK parity gate asserts on TENANT tables only. **The reason is scope,
 * NOT a difference in migration path** (T-2, corrected): in production the two
 * trees do run separately — Stancl's `migration_parameters` points `tenants:migrate`
 * at `database/migrations/tenant`, the default `migrate` runs `database/migrations`
 * — but under `APP_ENV=testing` `AppServiceProvider::boot()` ALSO loads the tenant
 * tree, so `migrate` builds ONE database holding BOTH trees. The tenant DDL applied
 * there is byte-for-byte the same directory `tenants:migrate` uses; what the test
 * database additionally carries is the central tree. Central columns are therefore
 * a different lane's population, reported here and asserted by the separate
 * central-scope gate — not a technical impossibility.
 *
 * What keeps that UNION honest is a single property: **no central migration mutates
 * a tenant-scoped table.** If one ever did, the gate would read a CHECK that no real
 * `tenant_<uuid>` database has — a FALSE COVERED, the one failure mode that makes
 * the gate lie. `centralMutationsOfTenantTables()` computes it and
 * `EnumCheckParityTest` asserts it empty.
 *
 * Derivation is mechanical, from the migration files themselves — never a hand
 * list. Three declaration idioms are recognised because all three are live in
 * this tree:
 *   Schema::create('t', …)                        — the common form
 *   Schema::connection($c)->create('t', …)        — the central admin-template migrations
 *   Schema::rename('old', 'new')                  — location_zones → location_nodes
 *
 * DISCLOSED OMISSION (T-6): `database/migrations/manual/` is NOT scanned — the two
 * globs are one level deep by design. It holds a DML backfill and a README today,
 * so nothing is missed; and if a `Schema::create` ever lands there the table falls
 * into SCOPE_UNKNOWN, which `EnumCheckParityTest` fails on. The omission is
 * deliberate and fails in the safe direction.
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
     * Tables the CENTRAL tree MUTATES that the TENANT tree DECLARES — the union
     * database's honesty property, expressed as a set that must stay empty (T-2).
     *
     * A central migration that `Schema::table()`s or `ALTER TABLE`s a tenant table
     * would add a constraint the parity gate reads out of the test database while
     * NO production `tenant_<uuid>` database has it.
     *
     * @return list<string>
     */
    public function centralMutationsOfTenantTables(): array
    {
        $tenantTables = array_fill_keys($this->tablesDeclaredIn($this->migrationsPath.'/tenant/*.php'), true);

        $leaks = [];
        foreach ($this->tablesMutatedIn($this->migrationsPath.'/*.php') as $table => $files) {
            if (! isset($tenantTables[$table])) {
                continue;
            }
            $leaks[] = $table.' ← '.implode(', ', $files);
        }

        sort($leaks, SORT_STRING);

        return $leaks;
    }

    /**
     * table => the central migration basenames that mutate it. Three idioms:
     * `Schema::table('t', …)`, `Schema::connection($c)->table('t', …)`, and raw
     * `ALTER TABLE t` inside a `DB::statement`.
     *
     * @return array<string, list<string>>
     */
    public function tablesMutatedIn(string $glob): array
    {
        $mutated = [];
        foreach (glob($glob) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $basename = basename($file);

            foreach (
                [
                    '/Schema::(?:connection\(\s*[^)]*\)\s*->)?table\(\s*\'([a-z0-9_]+)\'/',
                    '/ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?\"?([a-z0-9_]+)\"?/i',
                ] as $pattern
            ) {
                if (preg_match_all($pattern, $source, $m) > 0) {
                    foreach ($m[1] as $table) {
                        $mutated[strtolower($table)][$basename] = true;
                    }
                }
            }
        }

        $out = [];
        foreach ($mutated as $table => $files) {
            $names = array_keys($files);
            sort($names, SORT_STRING);
            $out[$table] = $names;
        }
        ksort($out, SORT_STRING);

        return $out;
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
