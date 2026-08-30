<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Tenant\Application\Contracts\ExecutionTimeLimit;
use App\Modules\Tenant\Domain\Tenant;
use Closure;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use JsonException;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class RegistrationResponseIsPureJsonTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshDatabaseInTransaction;
    }

    private ?Tenant $tenant = null;

    /** @var list<string> */
    private const array CENSUS_MIGRATIONS = [
        '2026_08_26_100100_null_invented_default_lot_expiries',
        '2026_08_27_100000_census_cash_tender_invariant_violations',
        '2026_08_28_100000_enforce_company_scoped_payment_method_codes',
        '2026_08_29_100000_backfill_default_location_code_f1',
        '2026_08_30_100000_enforce_company_scoped_product_skus',
        '2026_08_30_100100_enforce_company_scoped_variant_skus',
        '2026_08_30_100200_enforce_company_scoped_partner_vat_numbers',
        '2026_08_30_100300_ensure_units_visible_per_company',
        '2026_08_31_100000_add_outcome_to_import_rows',
        '2026_08_31_100100_add_error_code_to_import_rows',
        '2026_08_31_100200_backfill_product_unit_ids',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'pgsql') {
            Artisan::call('db:seed', ['--class' => PlansSeeder::class, '--force' => true]);

            return;
        }

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CountriesSeeder::class);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $tenant = $this->tenant;
        $usesPostgres = DB::connection()->getDriverName() === 'pgsql';

        try {
            if ($tenant !== null && $usesPostgres) {
                $databaseName = $tenant->getDatabaseName();

                DB::purge('tenant');
                $tenant->database()->manager()->deleteDatabase($tenant);

                self::assertNull(
                    DB::connection('central')->selectOne(
                        'SELECT 1 FROM pg_database WHERE datname = ?',
                        [$databaseName],
                    ),
                    "Tenant database [{$databaseName}] still exists after DROP DATABASE.",
                );
            }
        } finally {
            try {
                if ($tenant !== null && $usesPostgres) {
                    DB::connection('central')->table('personal_access_tokens')
                        ->where('abilities', 'like', '%tenant:'.$tenant->id.'%')
                        ->delete();
                    DB::connection('central')->table('domains')->where('tenant_id', $tenant->id)->delete();
                    DB::connection('central')->table('central_identities')->where('tenant_id', $tenant->id)->delete();
                    DB::connection('central')->table('tenant_subscriptions')->where('tenant_id', $tenant->id)->delete();
                    DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
                }
            } finally {
                parent::tearDown();
            }
        }
    }

    public function refreshDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->refreshDatabaseInTransaction();

            return;
        }

        // CREATE DATABASE cannot run inside RefreshDatabase's transaction.
        // The dedicated PostgreSQL leg owns autoerp_test_j and the test removes
        // its physical tenant database plus central rows in tearDown().
        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    /** @throws JsonException */
    public function test_registration_response_is_unprefixed_json(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('In-request database-per-tenant registration is PostgreSQL-only.');
        }

        config(['tenancy_resolver.db_per_tenant' => true]);
        $executionTimeLimit = new RegistrationExecutionTimeLimitSpy;
        $this->app->instance(ExecutionTimeLimit::class, $executionTimeLimit);

        $runUuid = (string) Str::uuid();
        Tenant::creating(static function (Tenant $tenant) use ($runUuid): void {
            $tenant->id = $runUuid;
        });

        ob_start();
        try {
            $response = $this->register(
                "pure-json+{$runUuid}@example.com",
                "Pure JSON Company {$runUuid}",
            );
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->tenant = Tenant::query()->findOrFail($runUuid);
        $content = $response->getContent();

        $response->assertCreated();
        self::assertSame(
            '',
            $output,
            'In-request tenant migrations must not emit census bytes; first 120 characters: '.substr((string) $output, 0, 120),
        );
        self::assertIsString($content);
        self::assertTrue(
            str_starts_with(ltrim($content), '{'),
            'Registration response must start with JSON; first 120 characters: '.substr($content, 0, 120),
        );

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data'] ?? null);
        self::assertArrayHasKey('user', $decoded['data']);
        self::assertSame([240], $executionTimeLimit->limits);
    }

    public function test_dispatching_tenant_migrations_during_tests_emits_no_stdout(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The deterministic output-buffer migration pin uses SQLite.');
        }

        $response = $this->register('migration-output@example.com', 'Migration Output Company');
        $response->assertCreated();

        $tenant = Tenant::query()->where('name', 'Migration Output Company')->firstOrFail();

        // RefreshDatabase has already applied tenant migrations to the shared
        // SQLite/compat connection. Forget only the census migrations so the
        // real Stancl MigrateDatabase job deterministically executes them again.
        DB::table('migrations')->whereIn('migration', self::CENSUS_MIGRATIONS)->delete();

        ob_start();
        try {
            Bus::dispatchSync(new MigrateDatabase($tenant));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(
            '',
            $output,
            'Synchronous tenant migrations must not emit census bytes; first 120 characters: '.substr((string) $output, 0, 120),
        );
    }

    /** @return TestResponse<Response> */
    private function register(string $email, string $companyName): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Registration User',
            'email' => $email,
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => $companyName,
            'country_code' => 'FR',
            'vertical' => 'retail',
        ]);
    }
}

final class RegistrationExecutionTimeLimitSpy implements ExecutionTimeLimit
{
    /** @var list<int> */
    public array $limits = [];

    public function setTimeLimit(int $seconds): void
    {
        $this->limits[] = $seconds;
    }

    public function registerShutdownHandler(Closure $handler): void
    {
        // Unit coverage invokes this handler; the feature test only proves the
        // HTTP registration path resolves and calls the container-bound seam.
    }

    public function clear(): void
    {
        // Unit coverage proves pending shutdown compensation is cleared.
    }
}
