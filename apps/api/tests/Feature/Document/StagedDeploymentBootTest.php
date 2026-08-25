<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * C-QR0a — the STAGED-DEPLOYMENT proof (SPEC §2.3, F-111/F-160).
 *
 * Tenant migrations run one tenant at a time (`RollingTenantMigrationCommand`),
 * so between this lane and C-QR0b the fleet is MIXED: some tenant databases carry
 * the authority columns, none carries the initialiser, the seed rows or the guard.
 * A database-level default, a NOT NULL, or a CHECK shipped here would decide the
 * authority policy by DDL — exactly what F-112 forbids ("no DB default and no code
 * default decides the mode") — and would break every INSERT the running release
 * still issues without the column.
 *
 * So this test does not assert "the migration ran". It asserts the SHAPE of what
 * ran, against `information_schema` / `pg_constraint` on real PostgreSQL, and then
 * boots the container and posts a document through the ordinary service to prove
 * the half-deployed schema is inert.
 *
 * The constraint arrives ATOMICALLY with the backfill in C-QR0b. That is the whole
 * reason it is absent here, and why this test must go RED the moment someone adds
 * it early.
 */
final class StagedDeploymentBootTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    /** @var list<array{table: string, column: string}> */
    private const NEW_COLUMNS = [
        ['table' => 'country_document_settings', 'column' => 'fiscal_authority_mode'],
        ['table' => 'country_document_settings', 'column' => 'fiscal_authority_types'],
        ['table' => 'country_document_settings', 'column' => 'policy_expertise_status'],
        ['table' => 'documents', 'column' => 'fiscal_authority_status'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('information_schema / pg_constraint proof is PostgreSQL-only (run with -c phpunit-pgsql.xml).');
        }
    }

    public function test_no_new_column_is_not_null(): void
    {
        $this->skipUnlessPostgres();

        foreach (self::NEW_COLUMNS as $target) {
            $row = DB::selectOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$target['table'], $target['column']],
            );

            self::assertNotNull($row, "{$target['table']}.{$target['column']} is missing from information_schema.");
            self::assertSame(
                'YES',
                $row->is_nullable,
                "{$target['table']}.{$target['column']} must stay nullable until C-QR0b backfills it.",
            );
        }
    }

    public function test_no_new_column_carries_a_database_default(): void
    {
        $this->skipUnlessPostgres();

        foreach (self::NEW_COLUMNS as $target) {
            $row = DB::selectOne(
                'SELECT column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$target['table'], $target['column']],
            );

            self::assertNotNull($row);
            self::assertNull(
                $row->column_default,
                "{$target['table']}.{$target['column']} must have NO DDL default — F-112: no default decides the authority policy.",
            );
        }
    }

    public function test_no_check_constraint_mentions_a_new_column(): void
    {
        $this->skipUnlessPostgres();

        foreach (self::NEW_COLUMNS as $target) {
            self::assertTrue(
                Schema::hasColumn($target['table'], $target['column']),
                "{$target['table']}.{$target['column']} must exist for this guard to mean anything.",
            );

            $definitions = DB::select(
                "SELECT c.conname, pg_get_constraintdef(c.oid) AS definition
                   FROM pg_constraint c
                   JOIN pg_class t ON t.oid = c.conrelid
                   JOIN pg_namespace n ON n.oid = t.relnamespace
                  WHERE t.relname = ? AND n.nspname = current_schema() AND c.contype = 'c'",
                [$target['table']],
            );

            foreach ($definitions as $definition) {
                self::assertStringNotContainsString(
                    $target['column'],
                    (string) $definition->definition,
                    "CHECK {$definition->conname} references {$target['column']}; the CHECK belongs to C-QR0b, atomically with the backfill.",
                );
            }
        }
    }

    /**
     * The columns are TEXT-ish and unconstrained on purpose, but they must still be
     * the width the enum values need — a truncating type would corrupt C-QR0b.
     */
    public function test_the_new_columns_are_wide_enough_for_every_enum_value(): void
    {
        $this->skipUnlessPostgres();

        foreach (self::NEW_COLUMNS as $target) {
            if ($target['column'] === 'fiscal_authority_types') {
                continue;
            }

            $row = DB::selectOne(
                'SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$target['table'], $target['column']],
            );

            self::assertNotNull($row);
            self::assertGreaterThanOrEqual(
                strlen('not_required'),
                (int) $row->character_maximum_length,
                "{$target['table']}.{$target['column']} cannot hold the longest enum value.",
            );
        }
    }

    /**
     * Unattended-safe re-run. `RollingTenantMigrationCommand` is retried per tenant
     * after a partial fleet failure, so `up()` has to be idempotent — every object
     * is guarded, and running it twice must be a no-op rather than a duplicate-column
     * error that strands the rest of the fleet.
     */
    public function test_the_migration_is_idempotent(): void
    {
        $migration = require database_path('migrations/tenant/2026_08_25_000100_add_fiscal_authority_columns_unactivated.php');

        $migration->up();
        $migration->up();

        foreach (self::NEW_COLUMNS as $target) {
            self::assertTrue(
                Schema::hasColumn($target['table'], $target['column']),
                "{$target['table']}.{$target['column']} must survive a re-run.",
            );
        }
    }

    /**
     * The half-deployed schema is inert: the container boots and the ordinary
     * posting path still reaches `posted`.
     */
    public function test_the_container_boots_and_posting_still_succeeds(): void
    {
        $service = app(DocumentPostingService::class);
        self::assertInstanceOf(DocumentPostingService::class, $service);

        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);

        self::assertSame(DocumentStatus::Posted, $service->post($invoice)->status);
    }
}
