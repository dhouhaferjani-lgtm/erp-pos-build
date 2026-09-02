<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TenantMigrationsNeverReferenceCentralTablesTest extends TestCase
{
    private const array FORBIDDEN_PATTERNS = [
        "constrained('tenants')",
        'constrained("tenants")',
        "on('tenants')",
        'on("tenants")',
        "constrained('domains')",
        'constrained("domains")',
        "on('domains')",
        'on("domains")',
        'central_identities',
        'plans',
        'tenant_subscriptions',
        'super_admins',
        'personal_access_tokens',
    ];

    public function test_tenant_migrations_never_reference_central_tables(): void
    {
        $files = glob(database_path('migrations/tenant/*.php'));

        self::assertNotFalse($files);
        self::assertNotEmpty($files);
        sort($files);

        $violations = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            self::assertNotFalse($contents);
            $executableContents = $this->stripComments($contents);

            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (str_contains($executableContents, $pattern)) {
                    $violations[] = basename($file).': '.$pattern;
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Tenant migrations reference central-only tables:\n".implode("\n", $violations),
        );
    }

    public function test_unit_text_mappings_migration_is_idempotent(): void
    {
        $hadCompaniesTable = Schema::hasTable('companies');
        $hadUnitsTable = Schema::hasTable('units');
        $hadMappingsTable = Schema::hasTable('unit_text_mappings');

        if (! $hadCompaniesTable) {
            Schema::create('companies', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
            });
        }
        if (! $hadUnitsTable) {
            Schema::create('units', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
            });
        }

        try {
            $migration = require database_path(
                'migrations/tenant/2026_09_01_100000_create_unit_text_mappings_table.php',
            );

            self::assertInstanceOf(Migration::class, $migration);
            self::assertTrue(method_exists($migration, 'up'));
            $migration->up();
            $migration->up();

            self::assertTrue(Schema::hasTable('unit_text_mappings'));
        } finally {
            if (! $hadMappingsTable) {
                Schema::dropIfExists('unit_text_mappings');
            }
            if (! $hadUnitsTable) {
                Schema::dropIfExists('units');
            }
            if (! $hadCompaniesTable) {
                Schema::dropIfExists('companies');
            }
        }
    }

    private function stripComments(string $contents): string
    {
        $executableContents = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $executableContents .= is_array($token) ? $token[1] : $token;
        }

        return $executableContents;
    }
}
