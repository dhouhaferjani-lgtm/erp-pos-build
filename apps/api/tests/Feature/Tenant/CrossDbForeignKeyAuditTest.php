<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use Tests\TestCase;

/**
 * T6 Phase 0b — constitutional rule (migration topology contract §1):
 * NO cross-database foreign keys. A tenant-database table can never declare a
 * FOREIGN KEY pointing at a central-database table; references are stored as
 * plain UUIDs and resolved at the application layer.
 *
 * This is a syntax-independent static audit over database/migrations/tenant/ so
 * the rule is enforced in CI forever, not just at the moment of the flip.
 */
class CrossDbForeignKeyAuditTest extends TestCase
{
    private const CENTRAL_TABLES = [
        'tenants', 'plans', 'domains', 'tenant_subscriptions', 'super_admins', 'central_identities',
    ];

    /**
     * Columns whose implicit ->constrained() (no table argument) would infer a
     * central table from the column name (Laravel strips _id and pluralizes).
     */
    private const CENTRAL_IMPLICIT_COLUMNS = [
        'tenant_id', 'plan_id', 'domain_id', 'super_admin_id', 'central_identity_id', 'tenant_subscription_id',
    ];

    public function test_no_tenant_migration_declares_a_foreign_key_to_a_central_table(): void
    {
        $dir = database_path('migrations/tenant');
        $files = glob($dir.'/*.php') ?: [];

        $this->assertNotEmpty($files, 'Expected tenant migrations to exist after the Phase 0b flip.');

        $central = implode('|', self::CENTRAL_TABLES);
        $explicit = "/constrained\\(\\s*'($central)'\\s*\\)/";
        $onForm = "/->on\\(\\s*'($central)'\\s*\\)/";
        // Raw-SQL foreign keys (DB::statement): "... REFERENCES tenants(id) ...".
        $rawSql = "/references\\s+\"?($central)\"?\\s*\\(/i";
        $implicitCols = implode('|', self::CENTRAL_IMPLICIT_COLUMNS);

        $violations = [];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['explicit constrained' => $explicit, 'references->on' => $onForm, 'raw SQL references' => $rawSql] as $label => $pattern) {
                if (preg_match($pattern, $contents, $m) === 1) {
                    $violations[] = basename($file).' ['.$label.']: '.$m[0];
                }
            }

            // Implicit ->constrained() (no table arg) on a central-implying column.
            // Checked per statement so intervening modifiers (e.g. ->nullable())
            // between the column and ->constrained() are not a blind spot.
            foreach (explode(';', $contents) as $statement) {
                if (preg_match("/foreign(?:Uuid|Id|IdFor)?\\(\\s*'($implicitCols)'\\s*\\)/", $statement, $m) === 1
                    && preg_match('/->\\s*constrained\\(\\s*\\)/', $statement) === 1) {
                    $violations[] = basename($file).' [implicit constrained]: '.trim($m[0]);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Cross-database FK(s) to central tables found in tenant migrations:\n".implode("\n", $violations),
        );
    }
}
